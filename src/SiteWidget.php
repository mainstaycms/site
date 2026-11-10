<?php

namespace Mainstay\Site;

use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Content\Entry;
use Mainstay\Database\Paths;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | The page served at `/` and how many live entries each type with an address
 | has, for whoever may edit and read such a type. Every live entry of one has
 | an address, so the count is the type's live entries, as the list opens
 | them: in the default locale.
 */
class SiteWidget extends Widget
{
    public function __construct(private Mainstay $mainstay, private Paths $paths) {}

    public static function handle(): string
    {
        return 'site';
    }

    public function title(): string
    {
        return 'Site';
    }

    public function view(): string
    {
        return 'site::widgets.site';
    }

    /** @return array{front: array{title: string|null, href: string|null}|null, types: list<array{label: string, count: int, href: string}>} */
    public function data(User $user): array
    {
        return [
            'front' => $this->front(),
            'types' => array_map(
                fn (string $type) => [
                    'label' => Navigation::label($type),
                    'count' => $this->mainstay->paginate($type, perPage: 1, depth: 0)->total(),
                    'href' => route('mainstay.entries', $type::handle(), absolute: false),
                ],
                $this->routed(),
            ),
        ];
    }

    public function visibleTo(User $user): bool
    {
        return $this->routed() !== [];
    }

    /* Named only to someone who may read its type: the address is public, but
       its title is not for everyone to see. */
    private function front(): ?array
    {
        $front = $this->mainstay->frontPage();

        if ($front === null) {
            return null;
        }

        [$type, $id] = $front;

        if (! Gate::about($type)->allows('viewAny', [$type, $type])) {
            return ['title' => null, 'href' => null];
        }

        $entry = $this->mainstay->findById($type, $id, depth: 0);

        return ['title' => $entry?->title, 'href' => $entry === null ? null : $this->paths->url($entry)];
    }

    /** @return list<class-string<Entry>> the types with an address this user may edit and read */
    private function routed(): array
    {
        return array_values(array_filter(
            $this->mainstay->registered(),
            fn (string $type) => is_subclass_of($type, Entry::class)
                && $this->paths->routed($type)
                && Navigation::writes($type)
                && Gate::about($type)->allows('viewAny', [$type, $type]),
        ));
    }
}
