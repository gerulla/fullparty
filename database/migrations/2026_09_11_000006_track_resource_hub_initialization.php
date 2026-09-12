<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_features', function (Blueprint $table) {
            $table->timestamp('resource_hub_initialized_at')->nullable();
        });

        // Existing libraries have already been used, even if the feature is currently disabled.
        DB::table('group_features')->where('resource_hub_enabled', true)
            ->orWhereIn('group_id', DB::table('group_resource_libraries')->select('group_id'))
            ->update(['resource_hub_initialized_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('group_features', fn (Blueprint $table) => $table->dropColumn('resource_hub_initialized_at'));
    }
};
