<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        $now = now();

        DB::table('activity_applications')
            ->whereNull('selected_character_id')
            ->whereNotNull('applicant_lodestone_id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $application) use ($now): void {
                $character = DB::table('characters')
                    ->where('lodestone_id', $application->applicant_lodestone_id)
                    ->first();

                if ($character && $character->verified_at !== null) {
                    return;
                }

                if (!$character) {
                    $characterId = DB::table('characters')->insertGetId([
                        'user_id' => null,
                        'is_primary' => false,
                        'name' => $application->applicant_character_name,
                        'world' => $application->applicant_world,
                        'datacenter' => $application->applicant_datacenter,
                        'lodestone_id' => $application->applicant_lodestone_id,
                        'avatar_url' => $application->applicant_avatar_url,
                        'token' => null,
                        'add_method' => 'guest_application',
                        'expires_at' => null,
                        'verified_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $characterId = $character->id;
                }

                DB::table('activity_applications')
                    ->where('id', $application->id)
                    ->update([
                        'selected_character_id' => $characterId,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $guestCharacterIds = DB::table('characters')
            ->whereNull('user_id')
            ->where('add_method', 'guest_application')
            ->pluck('id');

        if ($guestCharacterIds->isNotEmpty()) {
            DB::table('activity_applications')
                ->whereIn('selected_character_id', $guestCharacterIds)
                ->update([
                    'selected_character_id' => null,
                ]);

            DB::table('characters')
                ->whereIn('id', $guestCharacterIds)
                ->delete();
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
