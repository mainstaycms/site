<?php

namespace Mainstay\Site\Tests;

use InvalidArgumentException;
use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Number;
use Mainstay\Fields\Select;
use Mainstay\Fields\Text;
use Mainstay\Site\Patterns;
use Mainstay\Site\Route;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;

/* A type's #[Route], read as declared and refused where no path can be built
   from it. */
class PatternsTest extends DatabaseTestCase
{
    private Patterns $patterns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patterns = app(Patterns::class);
    }

    #[Test]
    public function it_reads_a_route_as_declared(): void
    {
        $this->assertSame(['en' => '/blog/{slug}', 'nl' => '/nieuws/{slug}'], $this->patterns->route(Post::class));
        $this->assertSame('/', $this->patterns->route((new #[Route('/')] class extends Entry {})::class), 'The root is a path, which is how a home page is made.');
        $this->assertNull($this->patterns->route(Article::class));
    }

    #[Test]
    public function it_refuses_a_route_no_path_can_be_built_from(): void
    {
        $refusals = [
            'is not a path Mainstay can store' => [
                new #[Route('/Blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
                new #[Route('/blog/')] class extends Entry {},
                new #[Route('blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
                new #[Route('/blog/{slug}-x')] class extends Entry
                {
                    #[Text]
                    public string $slug;
                },
            ],
            'names {nothing}, which is not a field of the type' => [new #[Route('/blog/{nothing}')] class extends Entry {}],
            'names {slug}, which is internal, and the path is published' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Text]
                    #[Internal]
                    public string $slug;
                },
            ],
            'names {slug}, which is typed int, and a path is built from strings' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Number]
                    public int $slug;
                },
            ],
            'names {kind}, which offers "In review", which is not a path segment' => [
                new #[Route('/{kind}')] class extends Entry
                {
                    #[Select(options: ['draft', 'In review'], required: true)]
                    public string $kind;
                },
            ],
            'names {slug}, which is optional, and a path cannot be built from nothing' => [
                new #[Route('/blog/{slug}')] class extends Entry
                {
                    #[Text]
                    public ?string $slug;
                },
            ],
            '#[Route] is a list. Give one pattern, or a pattern per locale keyed by the locale.' => [new #[Route(['/blog'])] class extends Entry {}],
        ];

        foreach ($refusals as $message => $types) {
            foreach ($types as $type) {
                try {
                    $this->patterns->route($type::class);
                    $this->fail("A route was read that should have been refused with: {$message}");
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString($message, $exception->getMessage());
                }
            }
        }
    }
}
