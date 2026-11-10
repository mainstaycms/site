<?php

namespace Mainstay\Site;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Mainstay\Database\Paths;
use Mainstay\Plugin;

/*
 | Public pages: every GET no route of the application answers is looked up
 | as an entry's path and rendered in the entry's view. A host that builds its
 | front end elsewhere leaves the plugin out, and its own fallback route runs.
 | It has no admin screens, so it asks no capability of its own.
 */
class SitePlugin extends Plugin
{
    public static function handle(): string
    {
        return 'site';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/../database/migrations';
    }

    public function tables(): array
    {
        return ['uris'];
    }

    /*
     | Its paths in place of core's none, and a fallback, which Laravel
     | matches after every other route whatever order they were registered
     | in, so a route the host writes always wins. Under a parameter of its
     | own: Route::fallback() files every fallback under one path, and the
     | host's, registered after this, would replace it.
     |
     | The middleware is the host's `mainstay.site.middleware`, `web` unless it
     | says otherwise.
     */
    public function boot(Application $app): void
    {
        /* Not a singleton: the registry a test or a host swaps would leave a
           kept one holding the old. */
        $app->bind(Paths::class, UriPaths::class);

        Route::get('{mainstayPath}', RenderController::class)
            ->where('mainstayPath', '.*')
            ->fallback()
            ->middleware(config('mainstay.site.middleware', ['web']))
            ->name('mainstay.site');
    }
}
