<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_management_warnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('severity', 16)->default('warning');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_management_warnings');
    }
};
