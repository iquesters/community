<?php

namespace Iquesters\Community\Dashboard;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipPeriod;
use Iquesters\Community\Support\AdminMemberships;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MembershipPurchase;
use Iquesters\Community\Support\OrganisationResolver;

/**
 * The membership card on the user's dashboard (user-management shows the
 * services tagged "dashboard-cards"): "Apply for membership", or where the
 * application is, or "You're a member".
 *
 * Shown only while the community module is on (modules.status = active) and,
 * for people who aren't members yet, while self-registration is on.
 */
class MembershipCard
{
    public function render(?Authenticatable $user): ?string
    {
        // Super-admins run the platform; they don't join organisations.
        if (! $user || ! $this->moduleIsOn() || CommunityAccess::isPlatformAdmin($user)) {
            return null;
        }

        // Organisation admins are members of their organisations without applying.
        AdminMemberships::ensureFor($user);

        $memberships = Membership::query()
            ->with('organisation:id,uid,name')
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('id')
            ->get()
            ->unique('organisation_id')
            ->values();

        $canApply = (bool) DirectoryFields::conf()->self_registration && OrganisationResolver::available();
        $multiple = OrganisationResolver::isMultiple();
        // A membership already in an order that isn't paid yet (e.g. with an event registration).
        $inOrder = MembershipPurchase::inOpenOrder($user);
        // Single mode: one organisation, so once there is a membership there is nothing more to apply for.
        // While a membership is being paid there's nothing to apply for (in multiple mode too: no
        // "Join as a member" next to the one being paid; other organisations are on the Membership page).
        // Multiple mode: "Join another organisation" only while there is one they haven't joined or applied to.
        $joined = $memberships->where('status', '!=', Membership::STATUS_REJECTED)->pluck('organisation_id')->all();
        $another = $multiple && OrganisationResolver::choices()->whereNotIn('id', $joined)->isNotEmpty();
        $offerApply = $canApply && ! $inOrder && ($multiple ? $another : ($memberships->isEmpty()
            || $memberships->first()->status === Membership::STATUS_REJECTED));

        if ($memberships->isEmpty() && ! $inOrder && ! $offerApply) {
            return null;
        }

        // The paid period of each membership (e.g. Membership 2027, 1 Jan – 31 Dec 2027): the current one, else the latest.
        $periods = $memberships->mapWithKeys(fn (Membership $m) => [$m->id => MembershipPeriod::query()
            ->where('membership_id', $m->id)->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->orderByRaw('case when valid_until is null or valid_until >= ? then 0 else 1 end', [now()->toDateString()])
            ->orderByDesc('valid_until')->first()]);
        // What was bought (the commerce product's name, e.g. "Membership 2027").
        $product = 'Iquesters\Commerce\Models\Product';
        $productNames = class_exists($product)
            ? $product::query()->whereIn('id', $periods->filter()->pluck('product_id')->filter()->unique()->all())->pluck('name', 'id')
            : collect();

        return view('community::dashboard.membership-card', [
            'memberships' => $memberships,
            'offerApply' => $offerApply,
            'inOrder' => $inOrder,
            'periods' => $periods,
            'productNames' => $productNames,
            'multiple' => $multiple,
            'memberLabel' => (string) DirectoryFields::conf()->member_label,
        ])->render();
    }

    private function moduleIsOn(): bool
    {
        return Schema::hasTable('modules')
            && DB::table('modules')->where('name', 'community')->where('status', 'active')->exists();
    }
}
