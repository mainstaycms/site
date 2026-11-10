<?php

namespace Mainstay\Site;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mainstay\Content\Entry;
use Mainstay\Database\Paths;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;
use Mainstay\Mainstay;
use ReflectionClass;

/*
 | The `uris` table: one path to one entry, per site and locale, which every
 | save writes from the type's #[Route], every read looks up in one batch and every
 | lookup asks. Rows are deleted rather than soft-deleted, so a trashed entry's path
 | stops resolving in the same request.
 |
 | Nothing is kept past the call: the front page is read as it is asked for,
 | since this is made once and an Octane worker outlives the request.
 */
class UriPaths implements Paths
{
    /* The column's width, which the drivers other than SQLite enforce with an
       error nobody reading a form could act on. */
    private const LENGTH = 255;

    public function __construct(private Mainstay $mainstay, private Patterns $patterns, private Addresses $addresses) {}

    public function routed(string $type): bool
    {
        return (new ReflectionClass($type))->getAttributes(Route::class) !== [];
    }

    public function rules(string $type): array
    {
        $rules = $messages = [];

        foreach ($this->patterns->placeholders($type) as $name) {
            $rules[$name][] = 'regex:'.Route::SLUG;
            $messages["{$name}.regex"] = 'The :attribute field is part of a path: lowercase letters, digits and single hyphens, the way Str::slug() writes one.';
        }

        return [$rules, $messages];
    }

    public function slugField(string $type): ?string
    {
        return $this->patterns->slugField($type);
    }

    /*
     | Every locale's path, worked out again after every write rather than
     | only for the locale written: a shared field in the pattern moves them
     | all. Only what changed is written, each row by its key. A path left as
     | it was is not touched, and none is deleted to be put back: on MySQL
     | deleting by entry takes a gap lock on the lookup, and two saves holding
     | one each deadlock on the other's insert. A new entry has nothing to
     | compare with, so it only inserts.
     |
     | A path another entry holds is refused on the fields that build it,
     | never suffixed -- an editor looking at a URL they did not choose is the
     | outcome the route decision rules out. The unique index is what decides,
     | rather than a look beforehand, so two saves racing for one path cannot
     | both win. The violation is rethrown at once: Postgres has abandoned the
     | transaction, and the next statement in it would fail for that instead.
     |
     | The refusal names the path only to a caller that may read the type. A
     | path spells out the fields it is built from, stored ones the call did
     | not give and other locales' among them, which is why a write hands
     | such a caller back no path either.
     |
     | Where a plain read answers from a caller's older snapshot -- `$nested`
     | -- what paths are built from is read with a lock, so a slug another
     | request has changed since does not build the path from the old one, and
     | a path it added is not missed. Anywhere else the reads stay plain and
     | take no gap locks to deadlock on.
     */
    public function settle(string $type, int $id, int $site, bool $created, bool $reads, bool $nested): void
    {
        $handle = $type::handle();
        $wanted = $this->wanted($type, $id, $site, $reads, $nested);
        $rows = $created ? collect() : DB::table('uris')->where('type', $handle)->where('entry_id', $id)
            ->when($nested, fn (Builder $query) => $query->lockForUpdate())
            ->get(['id', 'locale', 'uri']);
        $held = $rows->keyBy('locale')->only(array_keys($wanted));

        /* The row of a locale whose path went: one the config dropped, or a
           route the type no longer has. */
        if (($gone = $rows->pluck('id')->diff($held->pluck('id')))->isNotEmpty()) {
            DB::table('uris')->whereIn('id', $gone)->delete();
        }

        foreach ($wanted as $locale => $uri) {
            $row = $held->get($locale);

            if ($row?->uri === $uri) {
                continue;
            }

            try {
                if ($row === null) {
                    DB::table('uris')->insert(['site_id' => $site, 'locale' => $locale, 'uri' => $uri, 'type' => $handle, 'entry_id' => $id]);
                } else {
                    DB::table('uris')->where('id', $row->id)->update(['uri' => $uri]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(array_fill_keys($this->patterns->blamed($type), $reads
                    ? "The path {$uri} is already taken in {$locale}."
                    : 'The path this builds is already taken.'));
            }
        }
    }

    /*
     | Where restoring from the trash finds a locale's path taken, the field
     | ending that locale's pattern -- a text slug -- takes the first of
     | `-2`, `-3` giving a free path in every locale built from it: a shared
     | slug in every locale. Cut short where the suffix would pass the
     | field's max or a path's 255 characters. A pattern ending otherwise, in
     | a literal segment or a select whose options a suffix would leave, or a
     | path another route or locale answers, which no suffix frees, is left
     | for settle() to refuse, naming it.
     |
     | Read with a lock where a plain read answers from a caller's older
     | snapshot -- `$nested`, as settle() reads -- so a slug another request
     | has changed since is not suffixed back from the old one.
     */
    public function resolveClashes(string $type, int $id, int $site, bool $nested): void
    {
        if (($patterns = $this->patterns->forLocales($type)) === null) {
            return;
        }

        $handle = $type::handle();
        $fields = $this->mainstay->fields($type);
        $taken = fn (string $locale, string $uri) => DB::table('uris')->where('site_id', $site)->where('locale', $locale)->where('uri', $uri)->when($nested, fn (Builder $query) => $query->lockForUpdate())->value('id') !== null;

        foreach (array_keys($patterns) as $locale) {
            $row = DB::table($handle)->where('id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->first();
            $translations = DB::table("{$handle}_locales")->where('parent_id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->get()->keyBy('locale');

            if (! $translations->has($locale) || ! $taken($locale, $this->patterns->built($type, $patterns[$locale], $row, $translations[$locale]))) {
                continue;
            }

            $field = preg_match('/'.Route::PLACEHOLDER.'\z/', $patterns[$locale], $last) ? $fields[$last[1]] : null;

            if (! $field instanceof Text && ! $field instanceof Textarea) {
                continue;
            }

            $value = ($field->localized ? $translations[$locale] : $row)->{Str::snake($field->name)};
            /* Every locale whose path this field builds. */
            $built = $field->localized ? [$locale] : array_values(array_filter($translations->keys()->all(), fn (string $other) => isset($patterns[$other]) && str_contains($patterns[$other], "{{$field->name}}")));

            for ($n = 2; ; $n++) {
                $room = min($field instanceof Text ? $field->max : PHP_INT_MAX, ...array_map(fn (string $other) => self::LENGTH - mb_strlen($this->patterns->built($type, $patterns[$other], $row, $translations[$other], [$field->name => ''])), $built)) - strlen("-{$n}");
                $base = rtrim(mb_substr($value, 0, max($room, 0)), '-');

                if ($room < 1 || $base === '') {
                    break;
                }

                $candidate = "{$base}-{$n}";
                $free = true;

                foreach ($built as $other) {
                    $uri = $this->patterns->built($type, $patterns[$other], $row, $translations[$other], [$field->name => $candidate]);

                    if ($taken($other, $uri) || $this->addresses->shadow($other, $uri) !== null) {
                        $free = false;

                        break;
                    }
                }

                if ($free) {
                    ($field->localized ? DB::table("{$handle}_locales")->where('parent_id', $id)->where('locale', $locale) : DB::table($handle)->where('id', $id))
                        ->update([Str::snake($field->name) => $candidate]);

                    break;
                }
            }
        }
    }

    /* By entry, which reads the rows as they are now: MySQL answers a plain
       read inside a caller's transaction from its snapshot, and a path added
       since would outlive the trash. The gap lock this takes makes a create
       inserting beside it wait until the commit. Alone that is a wait, not a
       deadlock; a caller's own transaction that writes a path after the
       delete can turn it into one, which reaches the caller. */
    public function forget(string $type, int $id): void
    {
        DB::table('uris')->where('type', $type::handle())->where('entry_id', $id)->delete();
    }

    public function held(string $type, int $id): array
    {
        return DB::table('uris')->where('type', $type::handle())->where('entry_id', $id)->pluck('uri', 'locale')->all();
    }

    /* One query for all the entries, which is what keeps a page of a list
       from asking once per row. */
    public function uris(string $type, array $ids, string $locale): array
    {
        if ($ids === [] || ! $this->routed($type)) {
            return [];
        }

        return DB::table('uris')->where('type', $type::handle())->where('locale', $locale)->whereIntegerInRaw('entry_id', $ids)
            ->pluck('uri', 'entry_id')->mapWithKeys(fn (mixed $uri, mixed $id) => [(int) $id => $uri])->all();
    }

    /* Null without a query for a path that is not lowercase segments, which
       no path is stored as and which MySQL and SQL Server would still match
       to one by folding case. */
    public function lookup(string $uri, string $locale, int $site): ?array
    {
        if ($uri !== '/' && ! preg_match('#\A'.Route::PATH.'\z#', $uri)) {
            return null;
        }

        $row = DB::table('uris')->where('site_id', $site)->where('locale', $locale)->where('uri', $uri)->first(['type', 'entry_id']);

        return $row === null ? null : [$row->type, (int) $row->entry_id];
    }

    public function url(Entry $entry): ?string
    {
        return $this->addresses->url($entry);
    }

    /*
     | The entry served at `/`, as its type and id, or null: this site's
     | choice, as WordPress's static front page is.
     */
    public function frontPage(int $site): ?array
    {
        $front = $this->front($site);
        $type = $front === null ? null : $this->mainstay->registered()[$front[0]] ?? null;

        return $type !== null && is_subclass_of($type, Entry::class) ? [$type, $front[1]] : null;
    }

    /* `/` would answer nothing, and a visitor's first page is not one to
       lose by tidying up. */
    public function guardTrashing(string $type, int $id, int $site): void
    {
        if ($this->front($site) === [$type::handle(), $id]) {
            throw ValidationException::withMessages(['front' => 'This is the front page. Choose another before moving it to the trash.']);
        }
    }

    /*
     | Its path becomes `/` in every locale it has, and the one it replaces
     | takes its pattern's path again -- suffixed as a restore suffixes where
     | that was taken while it was free, refused naming the path where no
     | suffix frees one.
     */
    public function setFrontPage(int $site, ?array $new, ?array $old, bool $nested, Closure $filed): void
    {
        DB::table('sites')->where('id', $site)->lockForUpdate()->value('id');
        DB::table('sites')->where('id', $site)->update(['front_type' => $new === null ? null : $new[0]::handle(), 'front_id' => $new[1] ?? null]);

        /* The old one first, so `/` is free for the new one to take. A slug
           moved to a free path is a write, as on a restore. */
        if ($old !== null && $old !== $new) {
            $filed($old[0], $old[1], fn () => $this->resolveClashes($old[0], $old[1], $site, $nested));
            $this->settle($old[0], $old[1], $site, false, true, $nested);
        }

        if ($new !== null) {
            $this->claimRoot($new, $site);
            $this->settle($new[0], $new[1], $site, false, true, $nested);
        }
    }

    /**
     * @param  array{0: class-string<Entry>, 1: int}  $entry
     */
    private function claimRoot(array $entry, int $site): void
    {
        $locales = DB::table("{$entry[0]::handle()}_locales")->where('parent_id', $entry[1])->pluck('locale');
        $held = DB::table('uris')->where('site_id', $site)->where('uri', '/')->whereIn('locale', $locales)
            ->where(fn (Builder $query) => $query->where('type', '!=', $entry[0]::handle())->orWhere('entry_id', '!=', $entry[1]))
            ->value('locale');

        if ($held !== null) {
            throw ValidationException::withMessages(['front' => "The path / is already taken in {$held}."]);
        }
    }

    /*
     | The path each of the entry's locale rows answers to, by locale. None
     | for a type with no route, or for a row in a locale that has since left
     | the config and so has no pattern to be given one by.
     |
     | @return array<string, string>
     */
    private function wanted(string $type, int $id, int $site, bool $reads, bool $nested): array
    {
        if (($patterns = $this->patterns->forLocales($type)) === null) {
            return [];
        }

        $handle = $type::handle();
        $row = DB::table($handle)->where('id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->first();
        $front = $this->front($site) === [$handle, $id];
        $wanted = [];

        foreach (DB::table("{$handle}_locales")->where('parent_id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->get() as $translation) {
            if (! isset($patterns[$translation->locale])) {
                continue;
            }

            $uri = $front ? '/' : $this->patterns->built($type, $patterns[$translation->locale], $row, $translation);

            if (mb_strlen($uri) > self::LENGTH) {
                throw ValidationException::withMessages(array_fill_keys($this->patterns->blamed($type), $reads
                    ? "The path {$uri} is longer than the ".self::LENGTH.' characters a path can be.'
                    : 'The path this builds is longer than the '.self::LENGTH.' characters a path can be.'));
            }

            /* Refused as a taken path is: a visitor asking for it would be
               answered by something else, and the entry never. */
            if (($shadow = $this->addresses->shadow($translation->locale, $uri)) !== null) {
                throw ValidationException::withMessages(array_fill_keys($this->patterns->blamed($type), $reads
                    ? "The path {$uri} is answered by {$shadow} in {$translation->locale}."
                    : 'The path this builds is answered by something else.'));
            }

            $wanted[$translation->locale] = $uri;
        }

        return $wanted;
    }

    /**
     * @return array{0: string, 1: int}|null the front page's handle and id
     */
    private function front(int $site): ?array
    {
        $row = DB::table('sites')->where('id', $site)->first(['front_type', 'front_id']);

        return $row?->front_type === null ? null : [$row->front_type, (int) $row->front_id];
    }
}
