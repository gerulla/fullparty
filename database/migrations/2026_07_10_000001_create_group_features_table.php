<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('availability_scheduler_enabled')->default(false);
            $table->boolean('statistics_enabled')->default(true);
            $table->boolean('leaderboard_enabled')->default(true);
            $table->boolean('calendar_sync_enabled')->default(false);
            $table->boolean('resource_hub_enabled')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('groups')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($groups) use ($now): void {
                DB::table('group_features')->insert(
                    $groups
                        ->map(fn ($group) => [
                            'group_id' => $group->id,
                            'availability_scheduler_enabled' => false,
                            'statistics_enabled' => true,
                            'leaderboard_enabled' => true,
                            'calendar_sync_enabled' => false,
                            'resource_hub_enabled' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_features');
    }
};
