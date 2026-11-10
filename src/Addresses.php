<?php

namespace Mainstay\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Mainstay\Content\Entry;
use Mainstay\Mainstay;

/*
 | Where content locales are served: which locale a request is in, the link
 | to an entry in its locale, and what a path would run into there instead
 | of the entry that holds it. The locales themselves are core's
 | `mainstay.locales`; only serving them is the site's.
 */
class Addresses
{
    public function __construct(private Mainstay $mainstay) {}

    /*
     | The locale a request is in and its path with the locale's prefix taken
     | off, which is what the lookup holds. The longest prefix wins, and a
     | prefix matches whole segments: `/nlx` is not under `/nl`. A host is
     | compared without scheme or port, which a proxy in front of the
     | application may have changed. Null where no locale is served.
     |
     | @return array{0: string, 1: string}|null
     */
    public function resolve(string $host, string $path): ?array
    {
        $path = '/'.trim($path, '/');
        $found = null;

        foreach ($this->mainstay->locales() as $locale => $base) {
            if (($base['host'] === null || $base['host'] === strtolower($host)) && $this->under($path, $base['prefix'])
                && ($found === null || strlen($base['prefix']) > strlen($found[1]))) {
                $found = [$locale, $base['prefix']];
            }
        }

        return $found === null ? null : [$found[0], substr($path, strlen($found[1])) ?: '/'];
    }

    /*
     | The link to an entry: its locale's base and its path, with the
     | subdirectory the application is served from between them, since a
     | request's path is read below it. For a locale with no host, all of it
     | through the URL generator, which puts the request's host on as well.
     |
     | Null for a type with no #[Route], and for an entry not read with its
     | locale and path: one handed to a caller that may write the type but
     | not read it, the one a policy is shown, or one never read at all.
     */
    public function url(Entry $entry): ?string
    {
        if (! isset($entry->locale, $entry->uri)) {
            return null;
        }

        $base = $this->mainstay->locales()[$entry->locale];
        $path = $this->path($base['prefix'], $entry->uri);

        return $base['host'] === null ? URL::to($path) : $base['origin'].parse_url(URL::to('/'), PHP_URL_PATH).$path;
    }

    /*
     | What a request for a locale's path reaches instead of the entry that
     | holds it, or null when nothing does: a route of Mainstay's own, or
     | another locale whose prefix the path runs into -- `/nl` in an
     | unprefixed English beside a Dutch at `/nl`.
     |
     | The routes are asked of the router, which is what answers the request:
     | every one named `mainstay.*` but the catch-all, so the admin at any
     | path, the API, and whatever the package adds are one check. Not the
     | host's own, which may be reading the same entry on purpose. A locale
     | with no host is served on every host, and a route kept to a domain
     | takes only one of them, so for such a locale it is left out.
     */
    public function shadow(string $locale, string $uri): ?string
    {
        $base = $this->mainstay->locales()[$locale];
        $path = $this->path($base['prefix'], $uri);
        $request = Request::create(($base['host'] === null ? '' : "http://{$base['host']}").$path);

        foreach (app('router')->getRoutes()->get('GET') as $route) {
            if (str_starts_with((string) $route->getName(), 'mainstay.') && ! $route->isFallback
                && ($base['host'] !== null || $route->getDomain() === null) && $route->matches($request)) {
                return "the route {$route->getName()}";
            }
        }

        $reached = $this->resolve($base['host'] ?? '', $path)[0];

        return $reached === $locale ? null : "{$reached}'s prefix {$this->mainstay->locales()[$reached]['prefix']}";
    }

    /* A locale's path under its prefix: `/nl` for its `/`, not `/nl/`. */
    private function path(string $prefix, string $uri): string
    {
        return $prefix !== '' && $uri === '/' ? $prefix : $prefix.$uri;
    }

    private function under(string $path, string $prefix): bool
    {
        return $prefix === '' || $path === $prefix || str_starts_with($path, "{$prefix}/");
    }
}
