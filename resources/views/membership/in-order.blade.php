{{-- A membership in an order that isn't paid yet (e.g. registered for an event with the membership): where it is, and the pay page. $inOrder = MembershipPurchase::inOpenOrder(). --}}
@php
    $o = $inOrder['order'];
    [$color, $icon, $text, $action] = match ($o->status) {
        'under_review' => ['info', 'fa-hourglass-half', 'your payment is being checked. Your membership starts once it is confirmed.', 'See payment'],
        'rejected' => ['danger', 'fa-circle-xmark', 'your payment was not accepted. Please send the payment details again.', 'Pay again'],
        default => ['warning', 'fa-indian-rupee-sign', 'waiting for your payment. Your membership starts once it is paid.', 'Pay now'],
    };
    $payUrl = \Illuminate\Support\Facades\Route::has('payment.pay') ? route('payment.pay', ['uid' => $o->uid, 'return' => $return ?? '/dashboard']) : null;
@endphp
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 {{ $bordered ?? true ? 'border-bottom' : '' }} py-2 small">
    <div>
        <i class="fas fa-fw {{ $icon }} text-{{ $color }}"></i>
        <span class="fw-semibold">{{ $inOrder['line']->description }}</span>
        @if ($showOrganisation ?? false) ({{ $o->organisation?->name }}) @endif
        is in your order <span class="font-monospace">{{ $o->reference }}</span>: {{ $text }}
    </div>
    @if ($payUrl)
        <a href="{{ $payUrl }}" class="btn btn-sm {{ $o->status === 'under_review' ? 'btn-outline-secondary' : 'btn-primary' }}">{{ $action }}</a>
    @endif
</div>
