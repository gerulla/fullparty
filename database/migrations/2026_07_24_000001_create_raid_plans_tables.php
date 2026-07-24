<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raid_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('visibility', 12)->default('unlisted')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('raid_plan_access_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raid_plan_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8);
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['raid_plan_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_plan_access_links');
        Schema::dropIfExists('raid_plans');
    }
};
