<?php

use App\Models\Group;
use App\Services\Groups\Resources\ResourceHomeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('collection_id')->nullable()->change();
            $table->boolean('is_home')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX group_resource_home_unique ON group_resources (group_id) WHERE is_home = true');
        Group::query()->eachById(fn (Group $group) => app(ResourceHomeService::class)->ensure($group));
    }

    public function down(): void
    {
        Schema::table('group_resources', function (Blueprint $table) {
            $table->dropIndex('group_resource_home_unique');
            $table->dropColumn('is_home');
        });
        // Keep root resources and their content intact when rolling back.
    }
};
