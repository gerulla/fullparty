<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_resource_images', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->boolean('library_upload')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('group_resource_images', fn (Blueprint $table) => $table->dropColumn(['original_name', 'library_upload']));
    }
};
