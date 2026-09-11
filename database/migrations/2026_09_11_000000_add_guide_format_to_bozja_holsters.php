<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bozja_holsters', function (Blueprint $table) {
            $table->string('guide_format', 20)->default('markdown');
        });
    }

    public function down(): void
    {
        if (DB::table('bozja_holsters')->where('guide_format', 'tiptap')->exists()) {
            throw new RuntimeException('Restore the Markdown guide backup before rolling back the rich-text migration.');
        }
        Schema::table('bozja_holsters', fn (Blueprint $table) => $table->dropColumn('guide_format'));
    }
};
