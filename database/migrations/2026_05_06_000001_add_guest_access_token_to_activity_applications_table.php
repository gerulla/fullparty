<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->string('guest_access_token', 64)->nullable()->after('applicant_avatar_url');
            $table->unique('guest_access_token');
        });

        $guestApplications = DB::table('activity_applications')
            ->select('id')
            ->whereNull('user_id')
            ->whereNull('guest_access_token')
            ->get();

        foreach ($guestApplications as $application) {
            do {
                $token = Str::random(40);
            } while (DB::table('activity_applications')->where('guest_access_token', $token)->exists());

            DB::table('activity_applications')
                ->where('id', $application->id)
                ->update([
                    'guest_access_token' => $token,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->dropUnique(['guest_access_token']);
            $table->dropColumn('guest_access_token');
        });
    }
};
