<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | The entry served at `/`, chosen as WordPress chooses a static front
     | page: its type's handle and its id, or none. Kept on the site's row,
     | which the plugin does not own, so the columns are the plugin's to add.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('front_type')->nullable();
            $table->unsignedBigInteger('front_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['front_type', 'front_id']);
        });
    }
};
