<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            // NULL permits subsequent cases after an earlier case is resolved.
            $table->string('open_key', 100)->nullable()->unique();
            $table->string('title', 255);
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->json('snapshot');
            $table->string('evidence_path')->nullable();
            $table->timestamps();
            $table->unique(['moderation_case_id', 'reporter_id']);
        });
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('report_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('template', 40);
            $table->text('message')->nullable();
            $table->string('item_title');
            $table->timestamps();
        });
        Schema::create('report_feedback_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_feedback_id')->constrained('report_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['report_feedback_id', 'user_id']);
            $table->index(['user_id', 'acknowledged_at', 'id'], 'report_feedback_pending_index');
        });
        foreach (['group_resources', 'group_resource_images', 'bozja_holsters'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('moderation_hidden_at')->nullable()->index());
        }
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('banned_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('banned_at'));
        foreach (['group_resources', 'group_resource_images', 'bozja_holsters'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('moderation_hidden_at'));
        }
        foreach (['report_feedback_recipients', 'report_feedback', 'moderation_actions', 'content_reports', 'moderation_cases'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
