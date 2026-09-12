<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('group_resources')->whereNotNull('pending_revision_id')->orderBy('id')->eachById(function ($resource) {
                $snapshot = DB::table('group_resource_revisions')->where('id', $resource->pending_revision_id)->value('snapshot');
                DB::table('group_resources')->where('id', $resource->id)->update([
                    'working_copy' => $snapshot ?? $resource->working_copy,
                    'pending_revision_id' => null,
                    'version' => $resource->version + 1,
                ]);
            });
            DB::table('group_resource_revisions')->where('state', 'pending')->update(['state' => 'draft']);
        });
    }

    public function down(): void
    {
        // Drafts may have been edited since conversion; never restore stale publication locks.
    }
};
