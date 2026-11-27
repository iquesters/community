<?php

namespace Iquesters\Community;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Iquesters\Community\Dashboard\MembershipCard;
use Iquesters\Community\Support\AutoMembership;
use Iquesters\Community\Support\OrderMemberships;
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

        // With the event module: what a member is called and what they're asked when registering (school records).
        if (interface_exists('Iquesters\Event\Contracts\MembershipDetails')) {
            $this->app->bind('Iquesters\Event\Contracts\MembershipDetails', \Iquesters\Community\Support\EventMembershipDetails::class);
        }
    }

    public function boot(): void
    {
        parent::boot();

        // Its messages (membership approved), when notifications is installed.
        \Iquesters\Community\Support\CommunityNotifications::register();

        // People uploaded from a school's records become members when they sign in
        // with a matching verified email or phone, or verify it (AutoMembership).
        Event::listen(Login::class, fn (Login $event) => AutoMembership::check($event->user));
        Event::listen(Verified::class, fn (Verified $event) => AutoMembership::check($event->user));

        // A paid order's membership lines (commerce): the membership periods, and the memberships of those who paid.
        $orderPaid = 'Iquesters\\Commerce\\Events\\OrderPaid';
        if (class_exists($orderPaid)) {
            Event::listen($orderPaid, fn ($event) => OrderMemberships::handle($event->order));
        }
    }
}
