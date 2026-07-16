<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->string('type', 20)->default('prepop')->after('role');
            $table->foreignId('parent_holster_id')
                ->nullable()
                ->after('type')
                ->constrained('bozja_holsters')
                ->restrictOnDelete();
            $table->index(['group_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'type']);
            $table->dropConstrainedForeignId('parent_holster_id');
            $table->dropColumn('type');
        });
    }
};
