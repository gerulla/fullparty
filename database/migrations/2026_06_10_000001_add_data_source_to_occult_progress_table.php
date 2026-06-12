<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->string('data_source')->default('fflogs');
        });
    }

    public function down(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->dropColumn('data_source');
        });
    }
};
