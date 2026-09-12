<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_resource_libraries', function (Blueprint $table) {
            $table->foreignId('holster_collection_id')->nullable()->constrained('group_resource_collections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_resource_libraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holster_collection_id');
        });
    }
};
