<?php

namespace App\Console\Commands;

use App\Models\Character;
use Illuminate\Console\Command;

class ReleaseDeletedUserCharacters extends Command
{
    protected $signature = 'characters:release-deleted-users
                            {--dry-run : Show how many characters would be released without changing records}';

    protected $description = 'Release characters still attached to anonymized deleted user accounts';

    public function handle(): int
    {
        $characters = Character::query()
            ->whereNotNull('user_id')
            ->whereHas('user', function ($query): void {
                $query
                    ->where('name', 'like', 'Deleted User #%')
                    ->where('email', 'like', '%@deleted.fullparty.local');
            });

        $count = (clone $characters)->count();

        if ($this->option('dry-run')) {
            $this->warn('Dry run only. No character records were changed.');
            $this->info(sprintf('%d character(s) would be released.', $count));

            return self::SUCCESS;
        }

        $released = $characters->update([
            'user_id' => null,
            'is_primary' => false,
            'verified_at' => null,
            'token' => null,
            'expires_at' => null,
        ]);

        $this->info(sprintf('%d character(s) released.', $released));

        return self::SUCCESS;
    }
}
