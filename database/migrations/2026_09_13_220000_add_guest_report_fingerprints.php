<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            $table->char('guest_fingerprint', 64)->nullable();
            $table->unique(['moderation_case_id', 'guest_fingerprint'], 'content_reports_case_guest_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_reports', function (Blueprint $table) {
            $table->dropUnique('content_reports_case_guest_unique');
            $table->dropColumn('guest_fingerprint');
        });
    }
};
