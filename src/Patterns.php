<?php

namespace Mainstay\Site;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Mainstay;
use ReflectionClass;

/*
 | A type's #[Route], checked, and the paths it builds: the pattern for each
 | content locale, the fields a path is built from, and a path from an entry's
 | values.
 */
class Patterns
{
    /** @var array<class-string, string|array<string, string>|null> */
    private array $routes = [];

    public function __construct(private Mainstay $mainstay, private Addresses $addresses) {}

    /*
     | The type's #[Route] as declared -- one pattern, or one per locale --
     | or null for a type with no URL. Checked here, the first time it is
     | asked for, so a pattern no path can be built from is refused naming
     | the type rather than surfacing as a broken lookup row.
     |
     | A pattern is `/` or a run of segments, each a lowercase slug or one
     | `{field}`. Lowercase because the lookup's unique index compares as the
     | database does, and MySQL and SQL Server fold case where SQLite and
     | Postgres do not: `/Blog/x` and `/blog/x` would be one path on two
     | drivers and two on the others. A placeholder names a field that is
     | required, so there is always something to build the path from; a
     | string, so the path is the value an editor typed rather than a date's
     | column format; and not internal, since the path is published.
     |
     | @return string|array<string, string>|null
     */
    public function route(string $type): string|array|null
    {
        $type = (new ReflectionClass($type))->getName();

        if (array_key_exists($type, $this->routes)) {
            return $this->routes[$type];
        }

        $attributes = (new ReflectionClass($type))->getAttributes(Route::class);
        $route = $attributes === [] ? null : $attributes[0]->newInstance()->pattern;

        if (is_array($route) && ($route === [] || array_is_list($route))) {
            throw new InvalidArgumentException("{$type}'s #[Route] is a list. Give one pattern, or a pattern per locale keyed by the locale.");
        }

        foreach ((array) $route as $pattern) {
            $this->check($type, $pattern);
        }

        return $this->routes[$type] = $route;
    }

    /*
     | The type's pattern for each content locale, or null for a type with no
     | route. A map has to name exactly the configured locales: `nl-NL`
     | against `nl`, or a locale added to the config and not to the map, is a
     | path some translation would silently not get.
     |
     | @return array<string, string>|null
     */
    public function forLocales(string $type): ?array
    {
        $route = $this->route($type);
        $locales = array_keys($this->mainstay->locales());

        if ($route === null) {
            return null;
        }

        $patterns = is_array($route) ? $route : array_fill_keys($locales, $route);

        if (array_diff(array_keys($patterns), $locales) !== [] || array_diff($locales, array_keys($patterns)) !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] has patterns for %s, and mainstay.locales holds %s. Give a pattern for every content locale, or one pattern for all of them.",
                $type,
                implode(', ', array_keys($route)),
                implode(', ', $locales),
            ));
        }

        /* A pattern every entry would be refused for, named here, once,
           rather than as a slug that cannot be changed to anything that
           works. Asked with the pattern as written: a `{slug}` is no locale's
           prefix and no route's literal segment, so only what answers any
           value there answers it. */
        foreach ($patterns as $locale => $pattern) {
            if (($shadow = $this->addresses->shadow($locale, $pattern)) !== null) {
                throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" puts every {$locale} path under {$shadow}, which answers it instead.");
            }
        }

        return $patterns;
    }

    /* The fields a path is built from, in any locale's pattern. */
    public function placeholders(string $type): array
    {
        preg_match_all('/'.Route::PLACEHOLDER.'/', implode(' ', $this->forLocales($type) ?? []), $names);

        return array_values(array_unique($names[1]));
    }

    /* The fields a refused path is reported on: the ones that build it, or
       the path itself for a route with none. */
    public function blamed(string $type): array
    {
        return $this->placeholders($type) ?: ['uri'];
    }

    /* A locale's path from its pattern, each placeholder the field's value
       on the row or its locale's, or the one `$with` gives it instead. */
    public function built(string $type, string $pattern, object $row, object $translation, array $with = []): string
    {
        $fields = $this->mainstay->fields($type);

        return preg_replace_callback(
            '/'.Route::PLACEHOLDER.'/',
            fn (array $name) => $with[$name[1]] ?? ($fields[$name[1]]->localized ? $translation : $row)->{Str::snake($name[1])},
            $pattern,
        );
    }

    /* The Text field the first locale's pattern ends in, which a form offers
       as the entry's slug. Null for a type with none. */
    public function slugField(string $type): ?string
    {
        $route = $this->route($type);
        $pattern = is_array($route) ? reset($route) : $route;

        if ($pattern === null || ! preg_match('/\{(\w+)\}\z/', $pattern, $last) || $last[1] === 'title') {
            return null;
        }

        return ($this->mainstay->fields($type)[$last[1]] ?? null) instanceof Text ? $last[1] : null;
    }

    private function check(string $type, mixed $pattern): void
    {
        if (! is_string($pattern) || ($pattern !== '/' && ! preg_match('#\A(?:/(?:'.Route::SEGMENT.'|'.Route::PLACEHOLDER.'))+\z#', $pattern))) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] pattern %s is not a path Mainstay can store: it starts with /, has no trailing slash, and each segment is a lowercase slug or one {field}.",
                $type,
                is_string($pattern) ? "\"{$pattern}\"" : get_debug_type($pattern),
            ));
        }

        preg_match_all('/'.Route::PLACEHOLDER.'/', $pattern, $names);

        foreach ($names[1] as $name) {
            $field = $this->mainstay->fields($type)[$name] ?? throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which is not a field of the type.");

            $because = match (true) {
                $field->internal => 'is internal, and the path is published',
                $field->phpType !== 'string' => "is typed {$field->phpType}, and a path is built from strings",
                ! $field->isRequired() => 'is optional, and a path cannot be built from nothing',
                /* Every write choosing one would be refused for it. */
                $field instanceof Select && ($option = $this->unrouted($field)) !== null => "offers \"{$option}\", which is not a path segment",
                default => null,
            };

            if ($because !== null) {
                throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which {$because}.");
            }
        }
    }

    /* A select's first option that cannot be a segment of a path. */
    private function unrouted(Select $field): ?string
    {
        foreach ($field->values() as $value) {
            if (! preg_match(Route::SLUG, $value)) {
                return $value;
            }
        }

        return null;
    }
}
