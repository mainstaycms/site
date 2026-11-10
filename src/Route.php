<?php

namespace Mainstay\Site;

use Attribute;

/*
 | The path an entry answers to, with fields interpolated: `#[Route('/blog/{slug}')]`.
 | One pattern for every locale, or one per locale where a segment is
 | translated -- `['en' => '/blog/{slug}', 'nl' => '/nieuws/{slug}']`. No
 | locale base in it: how a request picks its locale, by prefix or by host, is
 | the catch-all's, and a pattern written with `/nl` in it would have to change
 | when that does.
 |
 | Not inherited. A subclass is its own type with its own table, and taking its
 | parent's pattern would claim the parent's paths.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Route
{
    /* One segment of a path: what Str::slug() writes. The pattern's literal
       segments, a routed field's values and a routed select's options are all
       held to it. */
    public const SEGMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /* A value that is one segment and nothing else. */
    public const SLUG = '/\A'.self::SEGMENT.'\z/';

    /* One or more segments, each after its slash: a path other than `/`, a
       locale's prefix, and what a stored path is held to. */
    public const PATH = '(?:/'.self::SEGMENT.')+';

    /* A `{field}` in a pattern, capturing the field's name. */
    public const PLACEHOLDER = '\{(\w+)\}';

    public function __construct(public readonly string|array $pattern) {}
}
