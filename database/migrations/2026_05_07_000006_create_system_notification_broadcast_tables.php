<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_event_id')
                ->constrained(indexName: 'snb_notification_event_fk')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique('notification_event_id', 'snb_notification_event_unique');
            $table->index('created_at', 'snb_created_at_index');
        });

        Schema::create('system_notification_broadcast_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_notification_broadcast_id')
                ->constrained(table: 'system_notification_broadcasts', indexName: 'snbr_broadcast_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'snbr_user_fk')
                ->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['system_notification_broadcast_id', 'user_id'], 'snbr_broadcast_user_unique');
            $table->index(['user_id', 'read_at'], 'snbr_user_read_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notification_broadcast_reads');
        Schema::dropIfExists('system_notification_broadcasts');
    }
};
