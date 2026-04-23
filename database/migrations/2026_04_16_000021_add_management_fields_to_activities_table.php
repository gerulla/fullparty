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
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('organized_by_character_id')->nullable()->after('organized_by_user_id')->constrained('characters')->nullOnDelete();
            $table->unsignedInteger('duration_hours')->default(2)->after('starts_at');
            $table->text('notes')->nullable()->after('description');
            $table->boolean('is_public')->default(true)->after('notes');
            $table->boolean('needs_application')->default(true)->after('is_public');
            $table->string('secret_key', 64)->nullable()->after('needs_application');

            $table->index(['group_id', 'is_public']);
            $table->unique('secret_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['secret_key']);
            $table->dropIndex(['group_id', 'is_public']);
            $table->dropConstrainedForeignId('organized_by_character_id');
            $table->dropColumn([
                'duration_hours',
                'notes',
                'is_public',
                'needs_application',
                'secret_key',
            ]);
        });
    }
};
