<?php

namespace Iquesters\Community\Console;

use Illuminate\Console\Command;
use Iquesters\Community\Support\AdminMemberships;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Foundation\Support\UserModel;

/** Make every organisation admin a member of their organisations (safe to run again). */
class SyncAdminMembershipsCommand extends Command
{
    protected $signature = 'community:sync-admins';

    protected $description = 'Give organisation admins an approved membership of their organisations';

    public function handle(): int
    {
        $created = 0;
        $admins = 0;

        UserModel::query()
            ->whereHas('roles', fn ($q) => $q->where('name', CommunityAccess::ORGANISATION_ADMIN_ROLE))
            ->chunkById(200, function ($users) use (&$created, &$admins) {
                foreach ($users as $user) {
                    $admins++;
                    $created += AdminMemberships::ensureFor($user);
                }
            });

        $this->info("Checked {$admins} admins; created {$created} memberships.");

        return self::SUCCESS;
    }
}
