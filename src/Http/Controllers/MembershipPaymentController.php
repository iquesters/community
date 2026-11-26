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

        try {
            $order = MembershipPurchase::buy($request->user(), $organisation);
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
