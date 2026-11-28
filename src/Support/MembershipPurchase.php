<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Community\Models\MembershipPeriod;
use Iquesters\Organisation\Models\Organisation;

/**
 * Buying the membership on its own (no event), from the Membership page: the
 * organisation's membership product on sale now (commerce), one order, the pay
 * page. When the order is paid, OrderMemberships records the period; the
 * application itself is still decided by an admin ("separate").
 * Needs iquesters/commerce; without it nothing is offered.
 */
class MembershipPurchase
{
    private const PRODUCT = 'Iquesters\\Commerce\\Models\\Product';

    private const ORDER = 'Iquesters\\Commerce\\Models\\Order';

    private const SERVICE = 'Iquesters\\Commerce\\Services\\OrderService';

    /** The donation offered with the membership (the organisation's "offer with every paid order"), if any. */
    public static function donation(?Organisation $organisation)
    {
        $product = self::PRODUCT;

        return $organisation && self::available() && method_exists($product, 'donationForEveryOrder')
            ? $product::donationForEveryOrder((int) $organisation->id)
            : null;
    }

    private const LINE = 'Iquesters\Commerce\Models\OrderLine';

    /**
     * A membership of theirs already in an order that isn't paid yet (e.g.
     * registered for an event with the membership, payment sent or due): the
     * line for them (by their account, or their email on a guest's line), with
     * its order. They needn't apply meanwhile; once paid, OrderMemberships
     * makes the membership.
     *
     * @return array{order: object, line: object}|null
     */
    public static function inOpenOrder(Authenticatable $user, ?Organisation $organisation = null): ?array
    {
        if (! self::available()) {
            return null;
        }
        $lineClass = self::LINE;
        $userId = (int) $user->getAuthIdentifier();
        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        $line = $lineClass::query()
            ->where('product_type', 'membership')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['awaiting_payment', 'under_review', 'rejected'])
                ->when($organisation, fn ($q) => $q->where('organisation_id', $organisation->id)))
            ->with('order.organisation')
            ->latest('id')
            ->get()
            ->first(function ($line) use ($userId, $email) {
                $meta = (array) $line->meta;

                return ((int) ($meta['user_id'] ?? 0) === $userId)
                    || ($email !== '' && mb_strtolower((string) ($meta['email'] ?? '')) === $email);
            });

        return $line ? ['order' => $line->order, 'line' => $line] : null;
    }

    public static function available(): bool
    {
        return class_exists(self::SERVICE);
    }

    /** The organisation's membership product to sell now (the latest period on sale), if any. */
    public static function product(?Organisation $organisation)
    {
        if (! $organisation || ! self::available()) {
            return null;
        }
        $product = self::PRODUCT;

        return $product::query()
            ->where('organisation_id', $organisation->id)
            ->where('type', 'membership')
            ->where('status', 'active')
            ->where('visibility', 'public')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->orderByDesc('valid_from')
            ->get()
            ->first(fn ($p) => $p->isOnSale());
    }

    /** Their period for that product (paid), if any. */
    public static function period(Authenticatable $user, $product): ?MembershipPeriod
    {
        return MembershipPeriod::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('product_id', $product->id)
            ->where('status', MembershipPeriod::STATUS_ACTIVE)
            ->first();
    }

    /** Their unpaid order for that product (to pay instead of making another). */
    public static function pendingOrder(Authenticatable $user, $product)
    {
        $order = self::ORDER;

        return $order::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('status', ['awaiting_payment', 'under_review', 'rejected'])
            ->whereHas('lines', fn ($q) => $q->where('product_id', $product->id))
            ->latest('id')
            ->first();
    }

    /**
     * @throws \DomainException
     */
    public static function buy(Authenticatable $user, Organisation $organisation, float $donation = 0)
    {
        $product = self::product($organisation);
        if (! $product) {
            throw new \DomainException('Membership is not on sale right now.');
        }
        if (self::period($user, $product)) {
            throw new \DomainException('You have already paid this membership.');
        }
        if ($pending = self::pendingOrder($user, $product)) {
            return $pending;
        }

        $items = [[
            'product' => $product,
            'meta' => array_filter([
                'person' => $user->name ?? null,
                'email' => $user->email ?? null,
                'phone' => $user->phone ?? null,
                'user_id' => $user->getAuthIdentifier(),
                'role' => 'registrant',
                // Paying doesn't approve an application: an admin decides it.
                'approval' => 'separate',
            ], fn ($v) => $v !== null && $v !== ''),
        ]];
        // An optional donation, when the organisation offers one with every paid order.
        if ($donation > 0) {
            $donationProduct = self::donation($organisation);
            if (! $donationProduct) {
                throw new \DomainException('Donations are not taken with the membership.');
            }
            $items[] = ['product' => $donationProduct, 'amount' => $donation];
        }

        $order = app(self::SERVICE)->create($organisation, [
            'user_id' => $user->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
        ], $items, ['prefix' => 'MEM', 'source' => 'membership']);
        // Only for a new order: going back to an unpaid one sends nothing again.
        CommunityNotifications::orderPlaced($order, $product, $user);

        return $order;
    }
}
