<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('guide');
        });
    }

    public function down(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
