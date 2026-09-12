<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_social_links', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->char('binding_hash', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('payload');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_social_links');
    }
};
