<?php

namespace Mainstay\Site\Tests;

use Mainstay\Facades\Mainstay;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Post;
use Orchestra\Testbench\Attributes\DefineRoute;
use PHPUnit\Framework\Attributes\Test;

/* A host that builds its front end elsewhere leaves the plugin out: entries
   are written without paths, and its own fallback route answers the request. */
class HeadlessTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', []);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function handWritten($router): void
    {
        $router->fallback(fn () => 'host fallback');
    }

    #[Test]
    #[DefineRoute('handWritten')]
    public function with_the_plugin_left_out_the_hosts_fallback_runs(): void
    {
        Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => 'other', 'status' => 'live'], locale: 'en', overrideAccess: true);

        $this->get('/blog/other')->assertOk()->assertSee('host fallback');
    }
}
