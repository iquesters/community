@use('Iquesters\Community\Models\Membership')

@php
    $states = [
        Membership::STATUS_APPLIED => ['info', 'fa-hourglass-half', 'Your application is waiting for a decision.', 'View application'],
        Membership::STATUS_ON_HOLD => ['warning', 'fa-circle-question', 'An administrator needs more from you.', 'Update application'],
        Membership::STATUS_APPROVED => ['success', 'fa-circle-check', "You're a ".strtolower($memberLabel).'.', 'Open'],
        Membership::STATUS_REJECTED => ['danger', 'fa-circle-xmark', 'Your application was not accepted.', 'See why'],
    ];
@endphp

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h5 class="fs-6 text-muted mb-3"><i class="fas fa-people-group me-2"></i>Membership</h5>

        @foreach ($memberships as $membership)
            @php([$color, $icon, $text, $action] = $states[$membership->status] ?? ['secondary', 'fa-circle', $membership->statusLabel(), null])
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom py-2 small">
                <div>
                    <i class="fas fa-fw {{ $icon }} text-{{ $color }}"></i>
                    @if ($multiple) <span class="fw-semibold">{{ $membership->organisation?->name }}:</span> @endif
                    {{ $text }}
                </div>
                @if ($action)
                    <a href="{{ route('community.membership', $multiple ? ['organisation' => $membership->organisation?->uid] : []) }}"
                       class="btn btn-sm btn-outline-{{ $color === 'info' ? 'secondary' : $color }}">{{ $action }}</a>
                @endif
            </div>
        @endforeach

        @if ($offerApply)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 small">
                <span class="text-muted">
                    {{ $memberships->isEmpty() ? 'Join as a '.strtolower($memberLabel).': apply with your details and an administrator will review it.' : ($multiple ? 'Join another organisation.' : 'You can apply again with more details.') }}
                </span>
                <a href="{{ route('community.membership') }}" class="btn btn-sm btn-primary">
                    <i class="fas fa-fw fa-user-plus"></i> Apply for membership
                </a>
            </div>
        @endif
    </div>
</div>
