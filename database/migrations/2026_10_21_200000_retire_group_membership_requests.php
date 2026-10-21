<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('group_membership_applications', 'cancelled_at')) {
            Schema::table('group_membership_applications', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason')->nullable();
            });
        }

        DB::transaction(function (): void {
            DB::table('groups')
                ->where(function ($query) {
                    $query->where('join_mode', 'application')
                        ->orWhereExists(function ($requests) {
                            $requests->selectRaw('1')->from('group_membership_applications')
                                ->whereColumn('group_membership_applications.group_id', 'groups.id')
                                ->where('status', 'pending');
                        });
                })
                ->orderBy('id')
                ->chunkById(100, function ($groups): void {
                    foreach ($groups as $group) {
                        $timestamp = now();
                        $nextMode = $group->join_mode === 'application' ? 'invite_only' : $group->join_mode;

                        $cancelledCount = DB::table('group_membership_applications')
                            ->where('group_id', $group->id)
                            ->where('status', 'pending')
                            ->update([
                                'status' => 'cancelled',
                                'cancelled_at' => $timestamp,
                                'cancellation_reason' => 'membership_requests_retired',
                                'updated_at' => $timestamp,
                            ]);

                        if ($nextMode !== $group->join_mode) {
                            DB::table('groups')->where('id', $group->id)->update([
                                'join_mode' => $nextMode,
                                'updated_at' => $timestamp,
                            ]);
                        }

                        // Deployment bookkeeping, without impersonating a moderator or sending notices.
                        DB::table('audit_logs')->insert([
                            'actor_user_id' => null,
                            'action' => 'group.membership_requests.retired',
                            'severity' => 'moderation_change',
                            'scope_type' => 'group',
                            'scope_id' => $group->id,
                            'subject_type' => 'App\\Models\\Group',
                            'subject_id' => $group->id,
                            'message' => 'audit_log.events.group.membership_requests_retired',
                            'metadata' => json_encode([
                                'previous_join_mode' => $group->join_mode,
                                'join_mode' => $nextMode,
                                'cancelled_request_count' => $cancelledCount,
                                'reason' => 'membership_requests_retired',
                            ], JSON_THROW_ON_ERROR),
                            'created_at' => $timestamp,
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Retain cancellation evidence; rollback must not reopen requests or change group access.
    }
};
