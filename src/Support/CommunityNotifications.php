<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\Route;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipPeriod;

/**
 * The messages the community module sends, when iquesters/notifications is
 * installed (without it nothing is sent): a membership approved. The wording
 * is the notifications module's template (editable per organisation).
 */
class CommunityNotifications
{
    private const TYPES = 'Iquesters\Notifications\Support\NotificationTypes';

    private const SENDER = 'Iquesters\Notifications\Notifications';

    public static function available(): bool
    {
        return class_exists(self::TYPES) && class_exists(self::SENDER);
    }

    public static function register(): void
    {
        if (! self::available()) {
            return;
        }
        app(self::TYPES)->register('membership.approved', [
            'label' => 'Membership approved',
            'description' => 'An application was approved, a payment approved it, or the person matched the school\'s records.',
            'module' => 'community',
            'variables' => [
                'name' => 'Member name',
                'organisation' => 'Organisation',
                'member_label' => 'What a member is called (e.g. Alumnus)',
                'validity' => 'How long it lasts (when paid)',
                'membership_url' => 'Membership page link',
            ],
            'sample' => [
                'name' => 'Rahul Sen', 'organisation' => 'TTV Alumni Association', 'member_label' => 'Alumnus',
                'validity' => '1 Jan 2027 – 31 Dec 2027', 'membership_url' => url('/community/membership'),
            ],
            'defaults' => ['email' => [
                'subject' => 'Welcome to {{organisation}}',
                'body' => "Dear {{name}},\n\nYour membership of **{{organisation}}** is approved. Welcome!\n\n{{validity}}\n\n[Open your membership]({{membership_url}})\n\n{{organisation}}",
            ]],
        ]);
    }

    public static function approved(Membership $membership): void
    {
        if (! self::available()) {
            return;
        }
        $membership->loadMissing(['organisation', 'user']);
        $data = (array) $membership->application_data;
        $period = MembershipPeriod::query()->where('membership_id', $membership->id)->where('status', MembershipPeriod::STATUS_ACTIVE)->orderByDesc('valid_until')->first();
        $validity = $period?->valid_until
            ? 'Valid '.($period->valid_from ? $period->valid_from->format('j M Y').' – ' : 'until ').$period->valid_until->format('j M Y').'.'
            : '';
        $name = $membership->user?->name ?? ($data['name'] ?? '');

        (self::SENDER)::send('membership.approved', $membership->organisation_id, [
            'email' => $membership->user?->email ?? ($data['email'] ?? null),
            'name' => $name,
            'user_id' => $membership->user_id,
        ], [
            'name' => (string) $name,
            'organisation' => $membership->organisation?->name ?? '',
            'member_label' => (string) DirectoryFields::conf()->member_label,
            'validity' => $validity,
            'membership_url' => Route::has('community.membership') ? route('community.membership', ['organisation' => $membership->organisation?->uid]) : '',
        ], $membership);
    }
}
