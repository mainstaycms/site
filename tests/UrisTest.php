<?php

namespace Mainstay\Site\Tests;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mainstay\Database\ContentSchema;
use Mainstay\Mainstay;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Broken\Uris;
use PHPUnit\Framework\Attributes\Test;

/* The lookup table the plugin brings: what its migrations hold it to. */
class UrisTest extends DatabaseTestCase
{
    #[Test]
    public function the_uri_lookup_holds_one_path_per_site_and_locale(): void
    {
        $row = ['site_id' => 1, 'locale' => 'en', 'uri' => '/blog/hello', 'type' => 'article', 'entry_id' => 1];

        $second = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);

        /* An entry belongs to one site, so the same path on the second site
           is another entry's. */
        DB::table('uris')->insert($row);
        DB::table('uris')->insert(['locale' => 'nl'] + $row);
        DB::table('uris')->insert(['site_id' => $second, 'entry_id' => 2] + $row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('uris')->insert(['entry_id' => 3] + $row);
    }

    #[Test]
    public function one_path_per_entry_and_locale_arrives_over_rows_that_break_it(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_09_24_000000_hold_one_path_per_entry_and_locale.php';
        $migration->down();

        $row = ['site_id' => 1, 'locale' => 'en', 'type' => 'article', 'entry_id' => 1];
        $first = DB::table('uris')->insertGetId(['uri' => '/blog/first'] + $row);
        DB::table('uris')->insert(['uri' => '/blog/second'] + $row);
        DB::table('uris')->insert(['uri' => '/blog/other', 'entry_id' => 2] + $row);

        $migration->up();

        $this->assertSame([$first, '/blog/first'], [(int) DB::table('uris')->where('entry_id', 1)->value('id'), DB::table('uris')->where('entry_id', 1)->value('uri')]);
        $this->assertSame(2, DB::table('uris')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('uris')->insert(['uri' => '/blog/third'] + $row);
    }

    #[Test]
    public function the_uris_table_is_one_no_type_may_be_stored_in(): void
    {
        $this->app->make(Mainstay::class)->types([Uris::class]);

        $this->assertThrows(
            fn () => app(ContentSchema::class)->diff(),
            InvalidArgumentException::class,
            'would be stored in uris, a table Mainstay keeps for itself',
        );
    }
}
