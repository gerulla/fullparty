<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->foreignId('application_review_required_application_id')
                ->nullable()
                ->after('assigned_by_user_id')
                ->constrained('activity_applications')
                ->nullOnDelete();
            $table->timestamp('application_review_required_at')
                ->nullable()
                ->after('application_review_required_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_review_required_application_id');
            $table->dropColumn('application_review_required_at');
        });
    }
};
