<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculator_actions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('source_path', 512)->unique();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 32);
            $table->string('name', 120);
            $table->json('name_translations')->nullable();
            $table->text('description')->nullable();
            $table->json('description_translations')->nullable();
            $table->text('description_macro')->nullable();
            $table->json('description_macro_translations')->nullable();
            $table->json('effects');
            $table->json('effects_translations')->nullable();
            $table->string('role', 80);
            $table->unsignedBigInteger('job_id');
            $table->string('job_name', 120);
            $table->string('job_abbreviation', 40)->nullable();
            $table->boolean('is_phantom_action')->default(false);
            $table->unsignedSmallInteger('unlock_level')->default(0);
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('icon_file', 120)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->unsignedBigInteger('action_category_id')->nullable();
            $table->string('action_category_name', 80)->nullable();
            $table->unsignedBigInteger('attack_type_id')->nullable();
            $table->string('attack_type_name', 80)->nullable();
            $table->decimal('timing_cast_seconds', 8, 3)->nullable();
            $table->decimal('timing_recast_seconds', 8, 3)->nullable();
            $table->decimal('timing_extra_cast_seconds', 8, 3)->nullable();
            $table->unsignedInteger('timing_cooldown_group')->nullable();
            $table->unsignedInteger('timing_additional_cooldown_group')->nullable();
            $table->unsignedSmallInteger('timing_max_charges')->nullable();
            $table->unsignedBigInteger('cost_primary_type_id')->nullable();
            $table->integer('cost_primary_value')->nullable();
            $table->unsignedBigInteger('cost_secondary_type_id')->nullable();
            $table->integer('cost_secondary_value')->nullable();
            $table->integer('range_target_yalms')->nullable();
            $table->integer('range_effect_yalms')->nullable();
            $table->unsignedInteger('range_cast_type')->nullable();
            $table->boolean('targeting_self')->default(false);
            $table->boolean('targeting_party')->default(false);
            $table->boolean('targeting_alliance')->default(false);
            $table->boolean('targeting_hostile')->default(false);
            $table->boolean('targeting_ally')->default(false);
            $table->boolean('targeting_own_pet')->default(false);
            $table->boolean('targeting_party_pet')->default(false);
            $table->boolean('targeting_is_area')->default(false);
            $table->unsignedSmallInteger('targeting_dead_target_behavior')->nullable();
            $table->boolean('targeting_requires_line_of_sight')->default(false);
            $table->boolean('targeting_requires_facing_target')->default(false);
            $table->unsignedBigInteger('combo_previous_action_id')->nullable();
            $table->boolean('combo_preserves_combo')->default(false);
            $table->unsignedBigInteger('status_gain_self_id')->nullable();
            $table->string('status_gain_self_name', 120)->nullable();
            $table->text('status_gain_self_description')->nullable();
            $table->unsignedBigInteger('status_gain_self_icon_id')->nullable();
            $table->unsignedSmallInteger('status_gain_self_max_stacks')->nullable();
            $table->unsignedBigInteger('status_proc_id')->nullable();
            $table->unsignedBigInteger('status_proc_status_id')->nullable();
            $table->string('status_proc_status_name', 120)->nullable();
            $table->text('status_proc_status_description')->nullable();
            $table->unsignedBigInteger('status_proc_status_icon_id')->nullable();
            $table->unsignedSmallInteger('status_proc_status_max_stacks')->nullable();
            $table->unsignedBigInteger('metadata_aspect_id')->nullable();
            $table->unsignedBigInteger('metadata_behavior_type')->nullable();
            $table->unsignedBigInteger('metadata_class_job_category_id')->nullable();
            $table->unsignedBigInteger('metadata_source_class_job_id')->nullable();
            $table->boolean('metadata_is_role_action')->default(false);
            $table->boolean('metadata_is_player_action')->default(false);
            $table->boolean('metadata_is_derived_action')->default(false);
            $table->unsignedBigInteger('metadata_equivalence_group')->nullable();
            $table->json('source_payload');
            $table->json('localized_payloads')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['role', 'job_id', 'source_id'], 'calc_actions_source_unique');
            $table->index(['job_abbreviation', 'unlock_level'], 'calc_actions_job_level_idx');
            $table->index(['is_phantom_action', 'is_active'], 'calc_actions_phantom_idx');
            $table->index(['action_category_name', 'is_active'], 'calc_actions_category_idx');
        });

        Schema::create('calculator_traits', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('source_path', 512)->unique();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 32);
            $table->string('name', 120);
            $table->json('name_translations')->nullable();
            $table->text('description')->nullable();
            $table->json('description_translations')->nullable();
            $table->text('description_macro')->nullable();
            $table->json('description_macro_translations')->nullable();
            $table->json('effects');
            $table->json('effects_translations')->nullable();
            $table->string('role', 80);
            $table->unsignedBigInteger('job_id');
            $table->string('job_name', 120);
            $table->string('job_abbreviation', 40)->nullable();
            $table->unsignedSmallInteger('unlock_level')->default(0);
            $table->integer('value')->nullable();
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('icon_file', 120)->nullable();
            $table->string('icon_url', 512)->nullable();
            $table->unsignedBigInteger('class_job_category_id')->nullable();
            $table->unsignedBigInteger('source_class_job_id')->nullable();
            $table->boolean('is_phantom_trait')->default(false);
            $table->json('source_payload');
            $table->json('localized_payloads')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['role', 'job_id', 'source_id'], 'calc_traits_source_unique');
            $table->index(['job_abbreviation', 'unlock_level'], 'calc_traits_job_level_idx');
            $table->index(['is_phantom_trait', 'is_active'], 'calc_traits_phantom_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_traits');
        Schema::dropIfExists('calculator_actions');
    }
};
