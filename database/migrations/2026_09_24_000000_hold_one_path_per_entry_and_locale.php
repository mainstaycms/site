<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | One path per entry and locale, held by the database rather than by the
     | code that writes the lookup: every read joins it, so a second row would
     | be an entry read twice and a page count one too high. It replaces the
     | index on (type, entry_id), which it serves as well.
     */
    public function up(): void
    {
        /* A second row for one entry and locale, which only a write from
           outside the layer could have left, would refuse the index. The
           first is kept, and the entry's next save writes it anew. Group by
           group rather than one delete: MySQL refuses a delete whose own
           subquery reads the table it deletes from. */
        DB::table('uris')
            ->select('type', 'entry_id', 'locale', DB::raw('min(id) as kept'))
            ->groupBy('type', 'entry_id', 'locale')
            ->havingRaw('count(*) > 1')
            ->get()
            ->each(fn (object $group) => DB::table('uris')
                ->where('type', $group->type)
                ->where('entry_id', $group->entry_id)
                ->where('locale', $group->locale)
                ->where('id', '!=', $group->kept)
                ->delete());

        Schema::table('uris', function (Blueprint $table) {
            $table->unique(['type', 'entry_id', 'locale']);
            $table->dropIndex(['type', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::table('uris', function (Blueprint $table) {
            $table->index(['type', 'entry_id']);
            $table->dropUnique(['type', 'entry_id', 'locale']);
        });
    }
};
