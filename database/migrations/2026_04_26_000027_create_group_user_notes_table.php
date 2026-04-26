<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_user_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severity', 32);
            $table->text('body');
            $table->boolean('is_shared_with_groups')->default(false);
            $table->timestamps();

            $table->index(['group_id', 'user_id']);
            $table->index(['user_id', 'is_shared_with_groups']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_user_notes');
    }
};
