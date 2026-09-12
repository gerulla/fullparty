<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_resources', fn (Blueprint $table) => $table->uuid('uuid')->nullable()->unique());
        DB::table('group_resources')->orderBy('id')->eachById(function ($resource) {
            $candidate = preg_replace('/^untitled-/', '', $resource->slug);
            $uuid = Str::isUuid($candidate) && ! DB::table('group_resources')->where('uuid', $candidate)->exists()
                ? $candidate : (string) Str::uuid();
            DB::table('group_resources')->where('id', $resource->id)->update(['uuid' => $uuid]);
        });
        // SQLite rebuilds tables for column changes and loses partial-index predicates.
        Schema::table('group_resources', fn (Blueprint $table) => $table->dropIndex('group_resource_home_unique'));
        Schema::table('group_resources', fn (Blueprint $table) => $table->uuid('uuid')->nullable(false)->change());
        DB::statement('CREATE UNIQUE INDEX group_resource_home_unique ON group_resources (group_id) WHERE is_home = true');
    }

    public function down(): void
    {
        Schema::table('group_resources', function (Blueprint $table) {
            $table->dropIndex('group_resource_home_unique');
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
        DB::statement('CREATE UNIQUE INDEX group_resource_home_unique ON group_resources (group_id) WHERE is_home = true');
    }
};
