<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->foreignId('current_published_version_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('activity_type_versions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_published_version_id');
        });
    }
};
