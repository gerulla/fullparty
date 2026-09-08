<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_slots')
            ->whereNull('assigned_character_id')
            ->where(fn ($query) => $query->where('is_host', true)->orWhere('is_raid_leader', true))
            ->update(['is_host' => false, 'is_raid_leader' => false]);
    }

    public function down(): void
    {
        // Invalid occupant privileges must not be restored during a rollback.
    }
};
