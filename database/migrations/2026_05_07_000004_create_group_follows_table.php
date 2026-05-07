<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });

        DB::table('group_follows')->insertUsing(
            ['group_id', 'user_id', 'created_at', 'updated_at'],
            DB::table('group_memberships')
                ->selectRaw('group_id, user_id, COALESCE(joined_at, created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP')
                ->distinct()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('group_follows');
    }
};
