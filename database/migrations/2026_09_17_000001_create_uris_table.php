<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | The routing lookup: one path to one entry, per site and locale. Rows are
     | deleted rather than soft-deleted, so a trashed entry's path stops
     | resolving in the same request.
     */
    public function up(): void
    {
        Schema::create('uris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites');
            $table->string('locale');
            $table->string('uri');
            $table->string('type');
            $table->unsignedBigInteger('entry_id');

            $table->unique(['site_id', 'locale', 'uri']);
            $table->index(['type', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uris');
    }
};
