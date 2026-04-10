<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->unsignedInteger('demon_tablet_progress')->default(0)->after('demon_tablet_kills');
            $table->unsignedInteger('dead_stars_progress')->default(0)->after('dead_stars_kills');
            $table->unsignedInteger('marble_dragon_progress')->default(0)->after('marble_dragon_kills');
            $table->unsignedInteger('magitaur_progress')->default(0)->after('magitaur_kills');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->dropColumn([
                'demon_tablet_progress',
                'dead_stars_progress',
                'marble_dragon_progress',
                'magitaur_progress',
            ]);
        });
    }
};
