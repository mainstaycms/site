<?php

namespace Mainstay\Site\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Blocks\Figure;
use Mainstay\Tests\Fixtures\Blocks\Nested\Slide as NestedSlide;
use Mainstay\Tests\Fixtures\Blocks\Other\Slide as OtherSlide;
use Mainstay\Tests\Fixtures\Blocks\Quote;
use Mainstay\Tests\Fixtures\Blocks\Slide;
use Mainstay\Tests\Fixtures\Guide;
use Mainstay\Tests\Fixtures\Home;
use Mainstay\Tests\Fixtures\Leaf;
use Mainstay\Tests\Fixtures\Leaflet;
use Mainstay\Tests\Fixtures\Lost;
use Mainstay\Tests\Fixtures\Page;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Shadowed;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\Attributes\DefineRoute;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 4 check: an entry written through the query layer, visited at
 | its path, in its locale by prefix or by host, and drawn by the view its
 | entry, its type or its handle names.
 */
class RenderTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(dirname(__DIR__, 2).'/cms/tests/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class, Page::class, Home::class, Leaf::class, Lost::class, Shadowed::class, Guide::class, Leaflet::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function writePost(array $data = [], string $locale = 'en'): Post
    {
        return Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => 'hello', 'status' => 'live', ...$data], locale: $locale, overrideAccess: true);
    }

    private function translate(int $id): void
    {
        Mainstay::update(Post::class, $id, ['title' => 'Hallo', 'slug' => 'hallo', 'status' => 'live'], locale: 'nl', overrideAccess: true);
    }

    /* A host's own routes, one of them on a path an entry holds, and a
       fallback of its own. */
    protected function handWritten($router): void
    {
        $router->get('blog/hello', fn () => 'hand-written');
        $router->fallback(fn () => 'host fallback');
    }

    /* Set before the routes are registered, which read them once. */
    protected function adminAtRoot($app): void
    {
        $app['config']->set('mainstay.path', '');
    }

    /* The host a path is probed on where the locale names none, so the
       admin is left out because it is kept to a domain, and not because it
       is another one. */
    protected function adminOnItsOwnHost($app): void
    {
        $app['config']->set('mainstay.domain', 'localhost');
    }

    protected function apiAtOneSegment($app): void
    {
        $app['config']->set('mainstay.api.prefix', 'content');
    }

    protected function stateless($app): void
    {
        $app['config']->set('mainstay.site.middleware', []);
    }

    #[Test]
    public function a_path_renders_its_entry_with_the_view_named_after_its_type(): void
    {
        $this->writePost();

        $this->get('/blog/hello')
            ->assertOk()
            ->assertSee('<h1>Hello</h1>', escape: false)
            ->assertSee('<a href="http://localhost/blog/hello">', escape: false)
            ->assertCookie(config('session.cookie'));
    }

    #[Test]
    public function an_entry_renders_with_its_own_view_before_its_types_and_its_handles(): void
    {
        $id = $this->writePost(['template' => 'special'])->id;
        $home = Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);

        $this->get('/blog/hello')->assertOk()->assertSee('special: Hello');
        $this->get('/')->assertOk()->assertSee('home: Welcome');

        Mainstay::update(Home::class, $home->id, ['template' => 'special'], locale: 'en', overrideAccess: true);
        $this->get('/')->assertOk()->assertSee('special: Welcome');

        $post = Mainstay::update(Post::class, $id, ['template' => ''], locale: 'en', overrideAccess: true);

        $this->assertSame('post', Mainstay::template($post), 'Blank takes the view off.');
        $this->get('/blog/hello')->assertSee('<h1>Hello</h1>', escape: false);
    }

    #[Test]
    public function a_view_named_and_missing_is_an_error_rather_than_a_step_down(): void
    {
        Mainstay::create(Lost::class, ['title' => 'Lost'], locale: 'en', overrideAccess: true);
        $id = $this->writePost()->id;
        /* A view deleted after the entry named it. */
        DB::table('post')->where('id', $id)->update(['template' => 'deleted']);

        $this->withoutExceptionHandling();
        $this->assertThrows(fn () => $this->get('/lost'), InvalidArgumentException::class, 'View [nowhere] not found.');
        $this->assertThrows(fn () => $this->get('/blog/hello'), InvalidArgumentException::class, 'View [deleted] not found.');

        $this->assertSame('Still', Mainstay::update(Post::class, $id, ['title' => 'Still'], locale: 'en', overrideAccess: true)->title, 'A stored view is not checked again.');
    }

    #[Test]
    public function a_view_written_to_an_entry_is_one_its_type_lists(): void
    {
        foreach ([
            ['leaf', 'The template field is one of the views this type renders with: post, special.'],
            ['mainstay::admin', 'The template field is one of the views this type renders with: post, special.'],
            [['post'], 'The template field must be a string.'],
        ] as [$view, $message]) {
            $this->assertThrows(fn () => $this->writePost(['template' => $view]), ValidationException::class, $message);
        }

        $this->assertThrows(
            fn () => Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'x', 'template' => 'special'], locale: 'en', overrideAccess: true),
            ValidationException::class,
            'The template field is one of the views this type renders with: leaf.',
        );

        /* A path built from no field is no reason to skip the check. */
        $this->assertThrows(
            fn () => Mainstay::create(Home::class, ['title' => 'X', 'template' => 'leaf'], locale: 'en', overrideAccess: true),
            ValidationException::class,
            'The template field is one of the views this type renders with: pages.home, special.',
        );

        $this->assertSame(0, DB::table('post')->count() + DB::table('leaf')->count() + DB::table('home')->count());
        $this->assertSame(['pages.home', 'special'], Mainstay::templates(Home::class));
    }

    #[Test]
    public function a_prefixed_locale_is_served_under_its_prefix(): void
    {
        $this->translate($this->writePost()->id);
        Mainstay::create(Home::class, ['title' => 'Welkom'], locale: 'nl', overrideAccess: true);

        $this->get('/nl/nieuws/hallo')->assertOk()->assertSee('<h1>Hallo</h1>', escape: false)->assertSee('locale nl');
        $this->get('/nl')->assertOk()->assertSee('home: Welkom');
        $this->get('/blog/hello')->assertOk()->assertSee('locale en');

        $this->get('/nieuws/hallo')->assertNotFound();
        $this->get('/nl/blog/hello')->assertNotFound();
        $this->get('/')->assertNotFound();
    }

    #[Test]
    public function a_trailing_slash_is_sent_to_the_path_without_one(): void
    {
        $this->writePost();

        /* By hand, since the test client trims the slash off first. */
        $get = fn (string $uri) => TestResponse::fromBaseResponse($this->app->make(Kernel::class)->handle(Request::create("http://localhost{$uri}")));

        $get('/blog/hello/')->assertStatus(301)->assertRedirect('/blog/hello');
        $get('/nl/?a=1&b=2')->assertStatus(301)->assertRedirect('/nl?a=1&b=2');
        $get('/nowhere/')->assertStatus(301)->assertRedirect('/nowhere');
        $get('//evil.example/')->assertStatus(301)->assertRedirect('/evil.example');
        $get('/blog/hello')->assertOk();
    }

    #[Test]
    public function a_prefix_matches_whole_segments(): void
    {
        Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'x'], locale: 'nl', overrideAccess: true);
        Mainstay::create(Leaf::class, ['title' => 'Nlx', 'slug' => 'nlx'], locale: 'en', overrideAccess: true);

        $this->get('/nl/x')->assertOk()->assertSee('leaf: X');
        $this->get('/nlx')->assertOk()->assertSee('leaf: Nlx');
    }

    #[Test]
    public function with_every_locale_prefixed_the_root_is_nobodys(): void
    {
        config()->set('mainstay.locales', ['en' => '/en', 'nl' => '/nl']);
        Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);

        $this->get('/')->assertNotFound();
        $this->get('/en')->assertOk()->assertSee('home: Welcome');
    }

    #[Test]
    public function a_locale_with_a_host_is_served_there_whatever_the_scheme_and_port(): void
    {
        config()->set('mainstay.locales', ['en' => 'https://example.test', 'nl' => 'http://nl.example.test:8000']);
        $id = $this->writePost()->id;
        $this->translate($id);

        $this->get('http://example.test/blog/hello')->assertOk()->assertSee('locale en');
        $this->get('https://nl.example.test/nieuws/hallo')->assertOk()->assertSee('<h1>Hallo</h1>', escape: false);
        $this->get('http://NL.example.test/nieuws/hallo')->assertOk();

        $this->get('http://nl.example.test/blog/hello')->assertNotFound();
        $this->get('http://elsewhere.test/blog/hello')->assertNotFound();

        $this->assertSame('https://example.test/blog/hello', Mainstay::findById(Post::class, $id, locale: 'en')->url());
        $this->assertSame('http://nl.example.test:8000/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());

        URL::forceRootUrl('http://example.test/site');
        $this->assertSame('http://nl.example.test:8000/site/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());
    }

    #[Test]
    public function a_link_is_the_locales_base_and_the_path(): void
    {
        $id = $this->writePost()->id;
        $this->translate($id);
        $home = Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);
        Mainstay::update(Home::class, $home->id, ['title' => 'Welkom'], locale: 'nl', overrideAccess: true);

        $this->assertSame('http://localhost/blog/hello', Mainstay::findById(Post::class, $id, locale: 'en')->url());
        $this->assertSame('http://localhost/nl/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());
        $this->assertSame('http://localhost', Mainstay::findById(Home::class, $home->id, locale: 'en')->url());
        $this->assertSame('http://localhost/nl', Mainstay::findById(Home::class, $home->id, locale: 'nl')->url(), 'Not /nl/.');

        /* Served from a subdirectory, which the request's path is relative
           to and the link has to hold. */
        URL::forceRootUrl('http://example.test/site');
        $this->assertSame('http://example.test/site/nl/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());
    }

    #[Test]
    public function an_entry_not_read_with_its_path_has_no_link_and_renders_as_its_type(): void
    {
        $this->assertNull((new Post)->url());
        $this->assertSame('post', Mainstay::template(new Post));

        /* What a caller that may write the type and not read it is handed:
           the locale, and not the path. */
        $post = new Post;
        $post->locale = 'en';
        $this->assertNull($post->url());
    }

    #[Test]
    public function a_path_that_leads_to_no_entry_is_not_found(): void
    {
        $id = $this->writePost()->id;
        DB::table('uris')->insert(['site_id' => 1, 'locale' => 'en', 'uri' => '/ghost', 'type' => 'ghost', 'entry_id' => $id]);

        /* Not asked at all: MySQL and SQL Server would match it to
           /blog/hello by folding case, and SQLite and Postgres would not. */
        $this->get('/Blog/Hello')->assertNotFound();
        $this->assertNull(Mainstay::findByUri('/Blog/hello', locale: 'en'));

        $this->get('/blog/nope')->assertNotFound();
        $this->get('/ghost')->assertNotFound();

        Mainstay::delete(Post::class, $id, overrideAccess: true);
        $this->get('/blog/hello')->assertNotFound();
    }

    #[Test]
    public function a_type_the_visitor_may_not_read_is_not_found_rather_than_refused(): void
    {
        Mainstay::create(Page::class, ['title' => 'Team', 'section' => 'about', 'slug' => 'team'], locale: 'en', overrideAccess: true);

        $this->get('/about/team')->assertNotFound();
        $this->assertNull(Mainstay::findByUri('/about/team', locale: 'en'));
        $this->assertInstanceOf(Page::class, Mainstay::findByUri('/about/team', locale: 'en', overrideAccess: true));
    }

    #[Test]
    public function an_internal_field_is_not_on_the_page(): void
    {
        $this->writePost(['editorNote' => 'Secret']);

        $this->get('/blog/hello')->assertOk()->assertSee('no note')->assertDontSee('Secret');
    }

    #[Test]
    #[DefineRoute('handWritten')]
    public function a_route_the_host_wrote_wins_and_its_fallback_does_not_replace_the_catch_all(): void
    {
        $this->writePost();
        $this->writePost(['title' => 'Other', 'slug' => 'other']);

        $this->get('/blog/hello')->assertOk()->assertSee('hand-written');
        $this->get('/blog/other')->assertOk()->assertSee('<h1>Other</h1>', escape: false);
        $this->get('/nowhere')->assertNotFound();
    }

    #[Test]
    public function the_locale_is_set_before_the_lookup_so_a_404_is_in_it(): void
    {
        $this->get('/nl/nowhere')->assertNotFound();

        $this->assertSame('nl', App::getLocale());
    }

    #[Test]
    public function a_path_another_locale_or_the_admin_answers_is_refused(): void
    {
        foreach ([
            'nl' => "The path /nl is answered by nl's prefix /nl in en.",
            'admin' => 'The path /admin is answered by the route mainstay.admin in en.',
        ] as $slug => $message) {
            $this->assertThrows(fn () => Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => $slug], locale: 'en', overrideAccess: true), ValidationException::class, $message);
        }

        $this->assertSame(0, DB::table('leaf')->count());

        /* Inside its own prefix nothing else answers. */
        Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'nl'], locale: 'nl', overrideAccess: true);

        $this->assertThrows(
            fn () => Mainstay::create(Shadowed::class, ['slug' => 'x'], locale: 'en', overrideAccess: true),
            InvalidArgumentException::class,
            '#[Route] pattern "/nl/{slug}" puts every en path under nl\'s prefix /nl, which answers it instead.',
        );
    }

    #[Test]
    #[DefineEnvironment('apiAtOneSegment')]
    public function a_path_the_api_answers_is_refused(): void
    {
        $this->assertThrows(
            fn () => Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'content'], locale: 'en', overrideAccess: true),
            ValidationException::class,
            'The path /content is answered by the route mainstay.api.index in en.',
        );
    }

    #[Test]
    #[DefineEnvironment('adminAtRoot')]
    public function with_the_admin_at_the_root_every_path_is_the_admins(): void
    {
        foreach ([
            [Leaf::class, '/{slug}', ['title' => 'X', 'slug' => 'x'], 'mainstay.entries'],
            [Home::class, '/', ['title' => 'X'], 'mainstay.admin'],
        ] as [$type, $pattern, $data, $route]) {
            $this->assertThrows(
                fn () => Mainstay::create($type, $data, locale: 'en', overrideAccess: true),
                InvalidArgumentException::class,
                "#[Route] pattern \"{$pattern}\" puts every en path under the route {$route}, which answers it instead.",
            );
        }
    }

    #[Test]
    #[DefineEnvironment('adminOnItsOwnHost')]
    public function the_admin_on_a_host_of_its_own_leaves_the_path_free(): void
    {
        Mainstay::create(Leaf::class, ['title' => 'Y', 'slug' => 'admin'], locale: 'en', overrideAccess: true);

        $this->get('http://example.test/admin')->assertOk()->assertSee('leaf: Y');
    }

    #[Test]
    #[DefineEnvironment('stateless')]
    public function public_pages_run_the_middleware_the_config_names(): void
    {
        $this->writePost();

        $this->get('/blog/hello')->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    #[Test]
    public function the_locale_map_is_read_again_when_the_config_changes(): void
    {
        $this->assertSame('/nl', Mainstay::locales()['nl']['prefix']);

        config()->set('mainstay.locales', ['en' => '/', 'nl' => '/nederlands']);

        $this->assertSame('/nederlands', Mainstay::locales()['nl']['prefix']);
    }

    #[Test]
    public function the_locale_map_is_refused_where_a_request_could_not_be_told_apart(): void
    {
        foreach ([
            [['en', 'nl'], 'mainstay.locales maps each content locale to where it is served'],
            [['en'], 'mainstay.locales maps each content locale to where it is served'],
            [['en' => 'nl'], 'mainstay.locales serves en at "nl", which is neither'],
            [['en' => '/NL'], 'mainstay.locales serves en at "/NL"'],
            [['en' => '/', 'nl' => 'https://example.nl'], 'gives some locales a host and not others'],
            [['en' => 'https://example.test', 'nl' => 'http://EXAMPLE.test:8000/'], 'serves en and nl at the same base'],
        ] as [$locales, $message]) {
            config()->set('mainstay.locales', $locales);

            $this->assertThrows(fn () => Mainstay::locales(), InvalidArgumentException::class, $message);
        }

        $this->assertThrows(fn () => Mainstay::find(Post::class, locale: 'en'), InvalidArgumentException::class, 'serves en and nl at the same base');
    }

    #[Test]
    public function a_block_is_drawn_by_its_view_where_the_content_holds_it(): void
    {
        $paragraph = fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $id = Mainstay::create(Guide::class, ['title' => 'Drawn', 'slug' => 'drawn', 'content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Top']]],
            ['type' => 'block', 'attrs' => ['type' => 'callout', 'data' => ['heading' => 'Note <b>', 'text' => ['type' => 'doc', 'content' => [$paragraph('Careful')]]]]],
            $paragraph('Between'),
            ['type' => 'block', 'attrs' => ['type' => 'gallery', 'data' => ['caption' => 'Shots']], 'content' => [['type' => 'block', 'attrs' => ['type' => 'slide', 'data' => ['title' => 'One']]]]],
            ['type' => 'heading', 'attrs' => ['level' => 6], 'content' => [['type' => 'text', 'text' => 'Bottom']]],
        ]]], locale: 'en', overrideAccess: true)->id;
        $guide = Mainstay::findById(Guide::class, $id, locale: 'en');

        $this->assertSame(
            "<h1>Top</h1><aside class=\"info\"><h2>Note &lt;b&gt;</h2><p>Careful</p></aside>\n<p>Between</p><figure><p>One</p>\n<figcaption>Shots</figcaption></figure>\n<h6>Bottom</h6>",
            Blade::render('{{ $content }}', ['content' => $guide->content]),
        );

        /* The blocks inside one, read in their order, the gallery's view
           printing them where it says. */
        $this->assertSame(['One'], array_map(fn (Slide $slide) => $slide->title, $guide->content->blocks()[1]->inner()));

        /* Named and not there is an error, as an entry's view is. */
        $this->assertThrows(fn () => (new Figure)->toHtml(), InvalidArgumentException::class, 'View [blocks.figure] not found.');

        /* A second block of that handle, in another list, would draw with
           the same view. */
        $this->assertThrows(
            fn () => Mainstay::fields(OtherSlide::class),
            InvalidArgumentException::class,
            'Two blocks are called "slide": Mainstay\Tests\Fixtures\Blocks\Slide and Mainstay\Tests\Fixtures\Blocks\Other\Slide',
        );

        /* Or inside the block itself, the blocks it holds read as it is. */
        $this->app->instance(Registry::class, $registry = new Registry);
        $this->assertThrows(
            fn () => $registry->innerBlocks(NestedSlide::class),
            InvalidArgumentException::class,
            'Two blocks are called "slide": Mainstay\Tests\Fixtures\Blocks\Nested\Slide and Mainstay\Tests\Fixtures\Blocks\Other\Slide',
        );
    }

    #[Test]
    public function the_front_page_is_served_at_the_root_in_every_locale_it_has(): void
    {
        $id = $this->writePost()->id;
        $this->translate($id);

        Mainstay::setFrontPage(Post::class, $id, overrideAccess: true);

        $this->assertSame([Post::class, $id], Mainstay::frontPage());
        $this->get('/')->assertSee('<h1>Hello</h1>', escape: false);
        $this->get('/nl')->assertSee('<h1>Hallo</h1>', escape: false);
        $this->get('/blog/hello')->assertNotFound();
        $this->assertSame(url('/'), Mainstay::findById(Post::class, $id, locale: 'en')->url());

        /* Kept there through a publish, which builds its paths as every
           write does, and refused the trash while it is the front page. */
        $draft = Mainstay::drafts()->save(Post::class, ['slug' => 'renamed'], entry: $id, locale: 'en', overrideAccess: true);
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $this->get('/')->assertSee('<h1>Hello</h1>', escape: false);
        $this->assertThrows(fn () => Mainstay::delete(Post::class, $id, overrideAccess: true), ValidationException::class, 'This is the front page.');

        /* Its own path, taken while it was free, so it goes back beside it
           when another is chosen. */
        $this->writePost(['title' => 'Usurper', 'slug' => 'renamed']);
        $next = $this->writePost(['title' => 'Next', 'slug' => 'next'])->id;
        Mainstay::setFrontPage(Post::class, $next, overrideAccess: true);

        $this->get('/')->assertSee('<h1>Next</h1>', escape: false);
        $this->assertSame(['/blog/renamed-2', '/nieuws/hallo'], [Mainstay::findById(Post::class, $id, locale: 'en')->uri, Mainstay::findById(Post::class, $id, locale: 'nl')->uri]);
        $this->assertSame('renamed-2', Mainstay::findById(Post::class, $id, locale: 'en')->slug, 'Slug and path agree, as after a restore.');

        Mainstay::setFrontPage(null, overrideAccess: true);
        $this->assertNull(Mainstay::frontPage());
        $this->get('/blog/next')->assertSee('<h1>Next</h1>', escape: false);
        $this->get('/')->assertNotFound();
    }

    #[Test]
    public function the_front_page_is_refused_where_the_root_is_taken_or_cannot_be_given(): void
    {
        Mainstay::create(Home::class, ['title' => 'Home'], locale: 'en', overrideAccess: true);
        $id = $this->writePost()->id;

        $this->assertThrows(fn () => Mainstay::setFrontPage(Post::class, $id, overrideAccess: true), ValidationException::class, 'The path / is already taken in en.');
        $this->assertNull(Mainstay::frontPage());
        $this->assertSame('/blog/hello', Mainstay::findById(Post::class, $id, locale: 'en')->uri);

        $leaflet = Mainstay::create(Leaflet::class, ['title' => 'Leaflet'], locale: 'en', overrideAccess: true)->id;
        $this->assertThrows(fn () => Mainstay::setFrontPage(Leaflet::class, $leaflet, overrideAccess: true), InvalidArgumentException::class, 'has no path, so it has nothing to serve at /.');

        /* Nobody signed in, nobody moves a page every visitor sees first. */
        $this->assertThrows(fn () => Mainstay::setFrontPage(Post::class, $id), AuthorizationException::class);
    }

    #[Test]
    public function field_prints_a_blocks_text_escaped_and_for_the_editor_marked_as_its_items_field(): void
    {
        $quote = new Quote;
        $quote->id = 'q1';
        $quote->line = 'Fish & <chips>';
        $quote->lines = null;
        $view = "<h2>@field(\$quote, 'line')</h2><p>@field(\$quote, 'lines')</p>";

        /* On the site, as {{ }} prints it; an empty one prints nothing. */
        $this->assertSame('<h2>Fish &amp; &lt;chips&gt;</h2><p></p>', Blade::render($view, ['quote' => $quote]));

        /* For the editor, marked with the item and the field, its label for
           when it is empty, and whether its field keeps lines. */
        $this->assertSame(
            '<h2><span data-mainstay-field="line" data-mainstay-item="q1" data-mainstay-label="Line">Fish &amp; &lt;chips&gt;</span></h2>'
            .'<p><span data-mainstay-field="lines" data-mainstay-item="q1" data-mainstay-label="Lines" data-mainstay-lines></span></p>',
            app(Registry::class)->marked(fn () => Blade::render($view, ['quote' => $quote])),
        );
        $this->assertSame('<h2>Fish &amp; &lt;chips&gt;</h2><p></p>', Blade::render($view, ['quote' => $quote]), 'Marked only while drawn for the editor.');

        /* Text alone: an image, or no field at all, is named. */
        $this->assertThrows(fn () => app(Registry::class)->field($quote, 'picture'), InvalidArgumentException::class, Quote::class.'::$picture is an Image field');
        $this->assertThrows(fn () => app(Registry::class)->field($quote, 'missing'), InvalidArgumentException::class, Quote::class.'::$missing is not a field');
    }
}
