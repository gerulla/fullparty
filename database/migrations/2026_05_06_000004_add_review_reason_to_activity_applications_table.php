<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->text('review_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->dropColumn('review_reason');
        });
    }
};
