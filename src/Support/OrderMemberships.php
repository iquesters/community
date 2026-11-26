<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\Schema;
use Iquesters\Commerce\Models\Order;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\MembershipPeriod;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Foundation\Support\UserModel;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * A commerce order was paid (OrderPaid): each of its membership lines becomes
 * a membership period for the person on the line (its meta: person, email,
 * phone, gender, user_id, approval), e.g. the alumni of a meet registration.
 *
 * - Their account (user_id on the line, else an account with that verified
 *   email): their membership; approved now when the line says "together"
 *   (the event's setting), else their application waits in Applications.
 * - No account: the school's record with that email or phone, else a new one
 *   (source event_registration); when they sign in with that verified email
 *   they become a member automatically and the period is theirs.
 *
 * Never fails the payment: errors are logged.
 */
class OrderMemberships
{
    use Loggable;

    /** @param  Order  $order */
    public static function handle($order): void
    {
        if (! Schema::hasTable('membership_periods')) {
            return;
        }
        $order->loadMissing(['lines', 'organisation']);
        if (! $order->organisation) {
            return;
        }

        foreach ($order->lines as $line) {
            if ($line->product_type !== 'membership' || MembershipPeriod::query()->where('order_line_id', $line->id)->exists()) {
                continue;
            }
            try {
                self::line($order, $line);
            } catch (\Throwable $e) {
                static::logError('Membership from a paid order failed', ['order' => $order->reference, 'line' => $line->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private static function line($order, $line): void
    {
        $meta = (array) $line->meta;
        $organisation = $order->organisation;
        $isRegistrant = ($meta['role'] ?? 'registrant') === 'registrant';
        $person = [
            'name' => $meta['person'] ?? ($isRegistrant ? $order->buyer_name : null),
            'email' => MemberValue::email($meta['email'] ?? ($isRegistrant ? $order->buyer_email : null)),
            'phone' => MemberValue::phone($meta['phone'] ?? ($isRegistrant ? $order->buyer_phone : null)),
            'gender' => self::gender($meta['gender'] ?? null),
        ];
        $approve = ($meta['approval'] ?? 'separate') === 'together';
        $adminId = (int) ($order->updated_by ?? 0);
        $note = 'Paid: order '.$order->reference;

        $user = self::user($meta['user_id'] ?? null, $person['email'], $isRegistrant ? $order->user_id : null);
        $membership = null;
        $record = null;
        if ($user) {
            $membership = app(MembershipService::class)->fromPayment($user, $organisation, $person, $approve, $adminId, $note);
            $record = $membership->member_record_id ? MemberRecord::query()->find($membership->member_record_id) : null;
        } elseif ($person['email'] || $person['phone']) {
            $record = self::record($organisation->id, $person, $adminId);
        }

        MembershipPeriod::create([
            'organisation_id' => $organisation->id,
            'membership_id' => $membership?->id,
            'member_record_id' => $record?->id,
            'user_id' => $user?->getAuthIdentifier(),
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'product_id' => $line->product_id,
            'reference' => $order->reference,
            'name' => $person['name'],
            'email' => $person['email'],
            'valid_from' => $line->valid_from,
            'valid_until' => $line->valid_until,
            'amount' => $line->line_total,
        ]);
        static::logInfo('Membership period from a paid order', ['order' => $order->reference, 'user_id' => $user?->getAuthIdentifier(), 'record' => $record?->uid]);
    }

    /** The account: the line's user, else one with that verified email. */
    private static function user($userId, ?string $email, $buyerId)
    {
        $id = $userId ?: $buyerId;
        if ($id && ($user = UserModel::query()->find($id))) {
            return $user;
        }
        if (! $email) {
            return null;
        }

        return UserModel::query()->whereRaw('LOWER(email) = ?', [$email])->whereNotNull('email_verified_at')->first();
    }

    /** The school's record of someone without an account (by email, else phone), or a new one. */
    private static function record(int $organisationId, array $person, int $by): MemberRecord
    {
        $base = fn () => MemberRecord::query()->where('organisation_id', $organisationId)->where('status', '!=', 'deleted');
        $record = ($person['email'] ? $base()->whereRaw('LOWER(email) = ?', [$person['email']])->first() : null)
            ?? ($person['phone'] ? $base()->where('phone', $person['phone'])->first() : null);

        if (! $record) {
            $record = MemberRecord::create([
                'organisation_id' => $organisationId,
                'name' => $person['name'] ?: 'Unknown',
                'email' => $person['email'],
                'phone' => $person['phone'],
                'source' => MemberRecord::SOURCE_EVENT,
                'status' => 'active',
                'created_by' => $by,
                'updated_by' => $by,
            ]);
        }
        if ($person['gender'] && ! $record->getMetaValue('gender')) {
            $record->setMetaValue('gender', $person['gender'], $by);
        }

        return $record;
    }

    /** "Male" / "male" → male. */
    private static function gender(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['male', 'female', 'other'], true) ? $value : null;
    }
}
