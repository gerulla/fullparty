<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('category');
            $table->boolean('is_mandatory')->default(false);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('title_key');
            $table->string('body_key')->nullable();
            $table->json('message_params')->nullable();
            $table->text('action_url')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_event_id', 'user_id']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('status');
            $table->string('target')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->unique(['notification_event_id', 'user_id', 'channel']);
            $table->index(['user_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_events');
    }
};
