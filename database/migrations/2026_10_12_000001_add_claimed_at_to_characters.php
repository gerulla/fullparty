<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->index();
        });

        // Existing verification represents proof of ownership. Preserve its original date
        // and keep verified_at intact until the remaining Medas eligibility changes land.
        DB::table('characters')
            ->whereNotNull('verified_at')
            ->update(['claimed_at' => DB::raw('verified_at')]);
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropIndex(['claimed_at']);
            $table->dropColumn('claimed_at');
        });
    }
};
