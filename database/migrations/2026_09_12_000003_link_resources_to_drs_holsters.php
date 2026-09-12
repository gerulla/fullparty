<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds the table for the foreign key and loses partial-index predicates.
        Schema::table('group_resources', fn (Blueprint $table) => $table->dropIndex('group_resource_home_unique'));
        Schema::table('group_resources', function (Blueprint $table) {
            $table->foreignId('holster_id')->nullable()->unique()->constrained('bozja_holsters')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX group_resource_home_unique ON group_resources (group_id) WHERE is_home = true');
    }

    public function down(): void
    {
        Schema::table('group_resources', fn (Blueprint $table) => $table->dropIndex('group_resource_home_unique'));
        Schema::table('group_resources', function (Blueprint $table) {
            $table->dropUnique(['holster_id']);
            $table->dropConstrainedForeignId('holster_id');
        });
        DB::statement('CREATE UNIQUE INDEX group_resource_home_unique ON group_resources (group_id) WHERE is_home = true');
    }
};
