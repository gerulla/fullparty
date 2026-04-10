<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('character_field_definitions', function (Blueprint $table) {
            $table->string('group')->default('profile')->after('description');
            $table->json('display_contexts')->nullable()->after('group');
            $table->string('source_type')->default('user')->after('display_contexts');
            $table->boolean('is_editable')->default(true)->after('source_type');
            $table->boolean('is_visible')->default(true)->after('is_editable');
            $table->json('tags')->nullable()->after('is_visible');

            $table->index('group');
            $table->index('source_type');
            $table->index('is_visible');
        });

        DB::table('character_field_definitions')->update([
            'display_contexts' => json_encode(['profile']),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('character_field_definitions', function (Blueprint $table) {
            $table->dropIndex(['group']);
            $table->dropIndex(['source_type']);
            $table->dropIndex(['is_visible']);

            $table->dropColumn([
                'group',
                'display_contexts',
                'source_type',
                'is_editable',
                'is_visible',
                'tags',
            ]);
        });
    }
};
