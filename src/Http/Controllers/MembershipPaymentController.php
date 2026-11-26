<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Iquesters\Community\Support\MembershipPurchase;
use Iquesters\Organisation\Models\Organisation;

/** "Pay membership" on the Membership page (form schema community-membership-pay; {uid} = the organisation). */
class MembershipPaymentController extends Controller
{
    public function store(Request $request, string $uid): JsonResponse
    {
        abort_unless(MembershipPurchase::available(), 404);
        $organisation = Organisation::query()->where('uid', $uid)->where('status', 'active')->firstOrFail();
        // Within the donation product's limits (₹1 or more when it sets none).
        $offered = MembershipPurchase::donation($organisation);
        $limits = $offered && method_exists($offered, 'amountLimits') ? $offered->amountLimits() : ['min' => 1, 'max' => null];
        $text = $offered && method_exists($offered, 'amountLimitsText') ? $offered->amountLimitsText() : '₹1 or more';
        $donation = (float) ($request->validate(
            ['donation' => ['nullable', 'numeric', 'min:'.$limits['min'], 'max:'.($limits['max'] ?? 10000000)]],
            ['donation.min' => "A donation is {$text} (or leave it empty).", 'donation.max' => "A donation is {$text}."],
        )['donation'] ?? 0);

        try {
            $order = MembershipPurchase::buy($request->user(), $organisation, $donation);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // The commerce OrderException (not on sale, etc.).
            if (property_exists($e, 'errors')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            throw $e;
        }

        $back = route('community.membership', ['organisation' => $organisation->uid], false);
        $url = $order->isPaid() || ! Route::has('payment.pay')
            ? url($back)
            : route('payment.pay', ['uid' => $order->uid, 'return' => $back]);

        return response()->json(['success' => true, 'message' => 'Order '.$order->reference.' made.', 'redirect_url' => $url]);
    }
}
