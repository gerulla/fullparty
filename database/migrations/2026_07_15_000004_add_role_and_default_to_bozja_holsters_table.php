<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->string('role')->nullable()->after('name');
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->index(['group_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'is_default']);
            $table->dropColumn(['role', 'is_default']);
        });
    }
};
