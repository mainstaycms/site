<?php

namespace Mainstay\Site\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Signed\Flyer;
use PHPUnit\Framework\Attributes\Test;

/* The Site card: the front page and the live entries of each type with an address, for who may edit them. */
class SiteWidgetTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Post::class, Flyer::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function signIn(array $capabilities): void
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function writePost(string $slug): void
    {
        Mainstay::create(Post::class, data: ['title' => $slug, 'slug' => $slug, 'status' => 'live'], overrideAccess: true);
    }

    #[Test]
    public function it_says_there_is_no_front_page_and_counts_each_routed_type(): void
    {
        $this->writePost('one');
        $this->writePost('two');

        $this->signIn(['edit_entries']);
        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('id="widget-site"', false)
            ->assertSee('No front page chosen')
            ->assertSee('href="'.route('mainstay.entries', 'post', absolute: false).'"', false);

        $this->assertMatchesRegularExpression('#Posts</a>\s*<span[^>]*>2</span>#', $page->getContent());
        $this->assertMatchesRegularExpression('#Flyers</a>\s*<span[^>]*>0</span>#', $page->getContent());
    }

    #[Test]
    public function it_lists_only_the_types_the_user_may_edit(): void
    {
        $this->signIn(['edit_flyers']);

        $page = $this->get(route('mainstay.admin'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Flyers</a>\s*<span[^>]*>0</span>#', $page);
        $this->assertDoesNotMatchRegularExpression('#Posts</a>\s*<span[^>]*>\d+</span>#', $page);
    }

    #[Test]
    public function it_names_the_front_page_and_links_to_it(): void
    {
        $flyer = Mainstay::create(Flyer::class, ['title' => 'Welcome flyer', 'slug' => 'welcome'], overrideAccess: true);
        Mainstay::setFrontPage(Flyer::class, $flyer->id, overrideAccess: true);

        $this->signIn(['edit_flyers']);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('Welcome flyer')
            ->assertDontSee('No front page chosen')
            ->assertSee('href="'.url('/').'"', false);
    }

    #[Test]
    public function it_is_neither_shown_nor_offered_to_someone_who_may_edit_no_routed_type(): void
    {
        $this->signIn([]);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('id="widget-site"', false)
            ->assertDontSee('No front page chosen');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['site']])->assertRedirect();
        $this->assertArrayNotHasKey('site', auth('mainstay')->user()->fresh()->preference('dashboard.widgets'));
    }
}
