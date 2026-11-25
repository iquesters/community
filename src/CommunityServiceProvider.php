<?php

namespace Iquesters\Community;

use Iquesters\Community\Dashboard\MembershipCard;
use Iquesters\Foundation\System\Providers\BaseServiceProvider;

class CommunityServiceProvider extends BaseServiceProvider
{
    // Routes (web + api.v1), views, migrations, the seeder command and the conf
    // are auto-detected by BaseServiceProvider.

    public function register(): void
    {
        parent::register();

        // The "Apply for membership" card on the user's dashboard (user-management
        // shows every service tagged "dashboard-cards").
        $this->app->singleton(MembershipCard::class);
        $this->app->tag([MembershipCard::class], 'dashboard-cards');
    }
}
