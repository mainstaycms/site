<?php

namespace Mainstay\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Mainstay\Mainstay;
use Symfony\Component\HttpFoundation\Response;

/*
 | The catch-all: a request's locale from its host and prefix, the entry its
 | path leads to there, and that entry's view. Everything a route the host
 | wrote does not answer ends here, and what leads to no entry is a 404.
 */
class RenderController
{
    public function __invoke(Request $request, Mainstay $mainstay, Addresses $addresses): Response
    {
        /* One address per page. The router ignores a trailing slash, so a
           path with one is sent to the path without, as Laravel's own
           .htaccess does, rather than rendered a second time. Trimmed at
           both ends: `//evil.example/` is a path on this host, and left with
           its leading `//` it is a URL of another. */
        if (($path = $request->getPathInfo()) !== '/' && str_ends_with($path, '/')) {
            return redirect()->to('/'.trim($path, '/').(($query = $request->getQueryString()) === null ? '' : "?{$query}"), 301);
        }

        [$locale, $uri] = $addresses->resolve($request->getHost(), $request->path()) ?? abort(404);

        /* Before the lookup, so a 404 renders in the locale too, and every
           read the template makes is in it unless it asks for another. */
        App::setLocale($locale);

        $entry = $mainstay->findByUri($uri, locale: $locale) ?? abort(404);

        return response()->view($mainstay->template($entry), ['entry' => $entry]);
    }
}
