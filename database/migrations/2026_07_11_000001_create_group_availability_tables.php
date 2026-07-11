<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_availability_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('minimum_role', 16)->default('member');
            $table->timestamps();
        });

        Schema::create('group_availability_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_weeks')->default(1);
            $table->boolean('repeats')->default(true);
            $table->boolean('lock_weekends')->default(true);
            $table->date('starts_on');
            $table->string('timezone', 64);
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('group_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('group_availability_schedules')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_week');
            $table->unsignedSmallInteger('weekday');
            $table->string('status', 16);
            $table->unsignedSmallInteger('starts_minute');
            $table->unsignedSmallInteger('ends_minute');
            $table->timestamps();

            $table->index(['schedule_id', 'cycle_week', 'weekday'], 'availability_windows_schedule_day_index');
        });

        Schema::create('group_availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('group_availability_schedules')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 16)->default('unavailable');
            $table->unsignedSmallInteger('starts_minute')->nullable();
            $table->unsignedSmallInteger('ends_minute')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_availability_exceptions');
        Schema::dropIfExists('group_availability_windows');
        Schema::dropIfExists('group_availability_schedules');
        Schema::dropIfExists('group_availability_settings');
    }
};
