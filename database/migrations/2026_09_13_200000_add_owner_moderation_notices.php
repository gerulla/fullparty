<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_feedback', function (Blueprint $table) {
            $table->string('audience', 20)->default('reporter');
            $table->foreignId('moderation_action_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_feedback', function (Blueprint $table) {
            $table->dropForeign(['moderation_action_id']);
            $table->dropUnique(['moderation_action_id']);
            $table->dropColumn(['audience', 'moderation_action_id']);
        });
    }
};
