<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->unsignedInteger('two_headed_aevis_kills')->default(0);
            $table->unsignedInteger('two_headed_aevis_progress')->default(0);
            $table->unsignedInteger('sword_dancer_kills')->default(0);
            $table->unsignedInteger('sword_dancer_progress')->default(0);
            $table->unsignedInteger('necrophobia_kills')->default(0);
            $table->unsignedInteger('necrophobia_progress')->default(0);
            $table->unsignedInteger('index_kills')->default(0);
            $table->unsignedInteger('index_progress')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->dropColumn([
                'two_headed_aevis_kills',
                'two_headed_aevis_progress',
                'sword_dancer_kills',
                'sword_dancer_progress',
                'necrophobia_kills',
                'necrophobia_progress',
                'index_kills',
                'index_progress',
            ]);
        });
    }
};
