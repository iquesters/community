<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipPeriod;

/**
 * The messages the community module sends, when iquesters/notifications is
 * installed (without it nothing is sent): the membership's journey (bought:
 * please pay; application received; more details needed; not approved;
 * approved). Paying itself is emailed by the payment module (details received,
 * confirmed, not accepted). The wording is the notifications module's template
 * (editable per organisation).
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
        self::registerJourneyTypes(app(self::TYPES));
    }

    /** The application's messages and the membership's order: received, more details needed, not approved, please pay. */
    private static function registerJourneyTypes($types): void
    {
        $variables = [
            'name' => 'Member name',
            'organisation' => 'Organisation',
            'note' => 'The organisers\' note (what to add, or why)',
            'membership_url' => 'Membership page link',
        ];
        $sample = ['name' => 'Rahul Sen', 'organisation' => 'TTV Alumni Association', 'note' => 'Please add your passing-out year.', 'membership_url' => url('/community/membership')];

        $types->register('membership.applied', [
            'label' => 'Membership application received',
            'description' => 'Someone applied (or sent their application again after being asked for more); the organisers will decide.',
            'module' => 'community',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'We received your application to {{organisation}}',
                'body' => "Dear {{name}},\n\nThank you for applying for membership of **{{organisation}}**. The organisers will check your application and let you know by email.\n\n[See your application]({{membership_url}})\n\n{{organisation}}",
            ]],
        ]);
        $types->register('membership.on_hold', [
            'label' => 'Membership: more details needed',
            'description' => 'The organisers asked the applicant for more; they can change the application and send it again.',
            'module' => 'community',
            'variables' => $variables,
            'sample' => $sample,
            'defaults' => ['email' => [
                'subject' => 'Your application to {{organisation}}: a little more needed',
                'body' => "Dear {{name}},\n\nThe organisers of **{{organisation}}** looked at your application and need a little more:\n\n> {{note}}\n\nPlease update your application and send it again.\n\n[Update your application]({{membership_url}})\n\n{{organisation}}",
            ]],
        ]);
        $types->register('membership.rejected', [
            'label' => 'Membership not approved',
            'description' => 'The organisers did not approve the application.',
            'module' => 'community',
            'variables' => $variables,
            'sample' => ['note' => 'We could not find you in the school\'s records.'] + $sample,
            'defaults' => ['email' => [
                'subject' => 'Your application to {{organisation}}',
                'body' => "Dear {{name}},\n\nThank you for applying to **{{organisation}}**. We're sorry: your application was not approved.\n\n> {{note}}\n\nIf you think this is a mistake, simply reply to this email.\n\n{{organisation}}",
            ]],
        ]);
        $types->register('membership.order_placed', [
            'label' => 'Membership: please pay',
            'description' => 'Someone chose to pay the membership; their order waits for payment.',
            'module' => 'community',
            'variables' => [
                'name' => 'Member name',
                'organisation' => 'Organisation',
                'membership' => 'The membership (e.g. Membership 2027)',
                'reference' => 'Order number',
                'amount' => 'Amount to pay',
                'pay_url' => 'Pay page link',
            ],
            'sample' => ['name' => 'Rahul Sen', 'organisation' => 'TTV Alumni Association', 'membership' => 'Membership 2027', 'reference' => 'MEM-0042', 'amount' => '₹600.00', 'pay_url' => url('/payment/pay')],
            'defaults' => ['email' => [
                'subject' => 'Your {{organisation}} membership: please pay {{reference}}',
                'body' => "Dear {{name}},\n\nThank you for choosing **{{membership}}** of **{{organisation}}**.\n\nPlease pay **{{amount}}** for order **{{reference}}** by UPI:\n\n[Pay now]({{pay_url}})\n\nAfter paying, send the UTR and a screenshot on the same page; we'll confirm by email.\n\n{{organisation}}",
            ]],
        ]);
    }

    /** Application received (new, or sent again after being asked for more). */
    public static function applied(Membership $membership): void
    {
        self::sendFor('membership.applied', $membership);
    }

    /** More details needed, with the organisers' note. */
    public static function onHold(Membership $membership, string $note): void
    {
        self::sendFor('membership.on_hold', $membership, $note);
    }

    /** Not approved, with the organisers' note. */
    public static function rejected(Membership $membership, string $note): void
    {
        self::sendFor('membership.rejected', $membership, $note);
    }

    /** The membership bought on its own: its order waits for payment. */
    public static function orderPlaced($order, $product, Authenticatable $user): void
    {
        if (! self::available() || ! $order) {
            return;
        }
        $order->loadMissing('organisation');
        $money = (! $order->currency || $order->currency === 'INR' ? '₹' : $order->currency.' ').number_format((float) $order->total, 2);

        (self::SENDER)::send('membership.order_placed', $order->organisation_id, [
            'email' => $user->email ?? null,
            'name' => $user->name ?? null,
            'user_id' => $user->getAuthIdentifier(),
        ], [
            'name' => (string) ($user->name ?? ''),
            'organisation' => $order->organisation?->name ?? '',
            'membership' => (string) ($product->name ?? 'Membership'),
            'reference' => (string) $order->reference,
            'amount' => $money,
            'pay_url' => Route::has('payment.pay') ? route('payment.pay', $order->uid) : '',
        ], $order);
    }

    private static function sendFor(string $type, Membership $membership, string $note = ''): void
    {
        if (! self::available()) {
            return;
        }
        $membership->loadMissing(['organisation', 'user']);
        $data = (array) $membership->application_data;
        $name = $membership->user?->name ?? ($data['name'] ?? '');

        (self::SENDER)::send($type, $membership->organisation_id, [
            'email' => $membership->user?->email ?? ($data['email'] ?? null),
            'name' => $name,
            'user_id' => $membership->user_id,
        ], [
            'name' => (string) $name,
            'organisation' => $membership->organisation?->name ?? '',
            'note' => $note,
            'membership_url' => Route::has('community.membership') ? route('community.membership', ['organisation' => $membership->organisation?->uid]) : '',
        ], $membership);
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
