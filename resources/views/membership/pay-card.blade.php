{{-- Membership for a period (e.g. 2027): paid, waiting for payment, or "Pay membership" (form schema community-membership-pay; {uid} = the organisation). Needs commerce. --}}
@php
    $product = \Iquesters\Community\Support\MembershipPurchase::product($organisation ?? null);
    $period = $product ? \Iquesters\Community\Support\MembershipPurchase::period(auth()->user(), $product) : null;
    $pending = $product && ! $period ? \Iquesters\Community\Support\MembershipPurchase::pendingOrder(auth()->user(), $product) : null;
@endphp
@if ($product)
    <div class="card border-0 shadow-sm">
        <div class="card-body small d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">{{ $product->name }}</div>
                <div class="text-muted">
                    @if ($product->valid_until)Valid {{ $product->valid_from?->format('j M Y') }} – {{ $product->valid_until->format('j M Y') }} · @endif
                    ₹{{ rtrim(rtrim(number_format((float) $product->price, 2), '0'), '.') }}
                </div>
            </div>
            @if ($period)
                <span class="badge text-bg-success"><i class="fas fa-circle-check"></i> Paid ({{ $period->reference }})</span>
            @elseif ($pending && \Illuminate\Support\Facades\Route::has('payment.pay'))
                <a class="btn btn-sm btn-primary" href="{{ route('payment.pay', ['uid' => $pending->uid, 'return' => '/community/membership?organisation='.$organisation->uid]) }}">
                    {{ $pending->status === 'under_review' ? 'Payment under review' : 'Pay '.$pending->reference }}
                </a>
            @else
                @include('userinterface::components.form', ['id' => \Iquesters\Community\Support\CommunityForms::MEMBERSHIP_PAY, 'entity_uid' => $organisation->uid, 'formData' => ''])
            @endif
        </div>
    </div>
@endif
