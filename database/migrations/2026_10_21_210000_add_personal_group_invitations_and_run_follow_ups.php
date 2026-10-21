<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_invites', function (Blueprint $table) {
            // Deleting the recipient must never turn a personal invitation into a public link.
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_activity_id')->nullable()->constrained('activities')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->index(['recipient_user_id', 'status', 'expires_at']);
        });

        DB::statement("CREATE UNIQUE INDEX group_invites_pending_recipient_unique ON group_invites (group_id, recipient_user_id) WHERE recipient_user_id IS NOT NULL AND status = 'pending'");

        Schema::table('activities', function (Blueprint $table) {
            $table->timestamp('group_follow_up_prepared_at')->nullable();
        });

        Schema::create('activity_group_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->json('characters')->nullable();
            $table->json('assignment_ids')->nullable();
            $table->timestamp('presented_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['activity_id', 'user_id', 'kind'], 'activity_follow_up_recipient_unique');
            $table->index(['user_id', 'kind', 'presented_at', 'dismissed_at'], 'activity_follow_up_inbox_index');
        });
    }

    public function down(): void
    {
        if (DB::table('group_invites')->whereNotNull('recipient_user_id')->exists()) {
            throw new RuntimeException('Personal invitations must be retired before rolling back their recipient binding.');
        }

        Schema::dropIfExists('activity_group_follow_ups');
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('group_follow_up_prepared_at'));
        DB::statement('DROP INDEX group_invites_pending_recipient_unique');
        Schema::table('group_invites', function (Blueprint $table) {
            $table->dropIndex(['recipient_user_id', 'status', 'expires_at']);
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropConstrainedForeignId('source_activity_id');
            $table->dropColumn(['status', 'accepted_at', 'declined_at', 'revoked_at']);
        });
    }
};
