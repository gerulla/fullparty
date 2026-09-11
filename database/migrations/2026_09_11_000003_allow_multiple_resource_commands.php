<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_resource_commands', function (Blueprint $table) {
            $table->dropUnique(['resource_id']);
            $table->index('resource_id');
        });
        foreach (['group_resources' => 'working_copy', 'group_resource_revisions' => 'snapshot'] as $table => $column) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $snapshot = json_decode($row->$column, true, flags: JSON_THROW_ON_ERROR);
                    $snapshot['commands'] ??= isset($snapshot['command']) ? [$snapshot['command']] : [];
                    unset($snapshot['command']);
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            });
        }
    }

    public function down(): void
    {
        // Do not silently discard commands or historical content during a rollback.
        throw new RuntimeException('Multiple resource commands require a data-preserving forward migration.');
    }
};
