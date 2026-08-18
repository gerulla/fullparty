<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculator_buffs', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('source_path', 512)->unique();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('source_id')->unique();
            $table->string('kind', 32);
            $table->string('name', 120);
            $table->json('name_translations')->nullable();
            $table->text('description')->nullable();
            $table->json('description_translations')->nullable();
            $table->json('effects');
            $table->json('effects_translations')->nullable();
            $table->string('classification', 20);
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('icon_file', 120)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->unsignedSmallInteger('max_stacks')->default(0);
            $table->unsignedBigInteger('status_category_id')->nullable();
            $table->unsignedSmallInteger('target_type')->nullable();
            $table->boolean('can_dispel')->default(false);
            $table->boolean('can_remove_manually')->default(false);
            $table->boolean('is_permanent')->default(false);
            $table->boolean('inflicted_by_actor')->default(false);
            $table->unsignedSmallInteger('party_list_priority')->default(0);
            $table->integer('parameter_effect')->nullable();
            $table->integer('parameter_modifier')->nullable();
            $table->unsignedBigInteger('class_job_category_id')->nullable();
            $table->json('source_abilities');
            $table->json('source_abilities_translations')->nullable();
            $table->json('source_payload');
            $table->json('localized_payloads')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['classification', 'is_active'], 'calc_buffs_class_idx');
            $table->index(['class_job_category_id', 'is_active'], 'calc_buffs_job_cat_idx');
            $table->index(['status_category_id', 'is_active'], 'calc_buffs_status_cat_idx');
        });

        Schema::create('calculator_potions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('source_path', 512)->unique();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('source_id')->unique();
            $table->string('kind', 32);
            $table->string('name', 120);
            $table->json('name_translations')->nullable();
            $table->text('description')->nullable();
            $table->json('description_translations')->nullable();
            $table->text('description_macro')->nullable();
            $table->json('description_macro_translations')->nullable();
            $table->json('effects');
            $table->json('effects_translations')->nullable();
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('icon_file', 120)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->unsignedInteger('item_level')->nullable();
            $table->boolean('can_be_high_quality')->default(false);
            $table->unsignedInteger('stack_size')->nullable();
            $table->unsignedSmallInteger('rarity')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category_name', 80)->nullable();
            $table->json('category_translations')->nullable();
            $table->unsignedBigInteger('use_item_action_id')->nullable();
            $table->unsignedBigInteger('use_action_id')->nullable();
            $table->boolean('use_usable_in_battle')->default(false);
            $table->unsignedSmallInteger('use_minimum_level')->nullable();
            $table->unsignedInteger('use_duration_seconds')->nullable();
            $table->unsignedBigInteger('use_effect_row_id')->nullable();
            $table->json('use_raw_data');
            $table->json('use_raw_data_high_quality');
            $table->json('stats');
            $table->json('stats_translations')->nullable();
            $table->unsignedBigInteger('primary_stat_id')->nullable();
            $table->string('primary_stat_name', 80)->nullable();
            $table->boolean('primary_stat_is_percentage')->default(false);
            $table->integer('primary_stat_normal_value')->nullable();
            $table->integer('primary_stat_normal_cap')->nullable();
            $table->integer('primary_stat_high_quality_value')->nullable();
            $table->integer('primary_stat_high_quality_cap')->nullable();
            $table->json('source_payload');
            $table->json('localized_payloads')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active'], 'calc_potions_category_idx');
            $table->index(['primary_stat_id', 'is_active'], 'calc_potions_stat_idx');
            $table->index(['item_level', 'is_active'], 'calc_potions_item_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_potions');
        Schema::dropIfExists('calculator_buffs');
    }
};
