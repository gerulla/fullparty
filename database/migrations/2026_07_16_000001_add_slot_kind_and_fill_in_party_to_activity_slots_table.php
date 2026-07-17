<?php

use App\Models\ActivitySlot;
use App\Services\Groups\ActivitySlotBench;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->string('slot_kind', 32)->default(ActivitySlot::SLOT_KIND_ROSTER);
            $table->string('filled_group_key')->nullable();
            $table->json('filled_group_label')->nullable();
            $table->index(['activity_id', 'slot_kind', 'sort_order']);
        });

        DB::table('activity_slots')
            ->where('group_key', ActivitySlotBench::GROUP_KEY)
            ->update(['slot_kind' => ActivitySlot::SLOT_KIND_BENCH]);
    }

    public function down(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->dropIndex(['activity_id', 'slot_kind', 'sort_order']);
            $table->dropColumn([
                'slot_kind',
                'filled_group_key',
                'filled_group_label',
            ]);
        });
    }
};
