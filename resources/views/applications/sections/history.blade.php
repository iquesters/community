{{-- Record section: the decision (once made) and every status change. --}}
@use('Iquesters\Community\Models\Membership')

@if (! $membership->isOpen())
    <p class="small mb-2">
        <span class="fw-semibold">{{ $membership->statusLabel() }}</span> {{ $membership->decided_at?->diffForHumans() }}
        @if ($membership->memberRecord)
            · record <span class="fw-semibold">{{ $membership->memberRecord->name }}</span>
            <span class="text-muted">({{ $membership->memberRecord->source === 'registration' ? 'created from the application' : 'linked to an existing record' }})</span>
        @endif
    </p>
@endif

<ul class="list-unstyled small mb-0">
    @foreach ($membership->histories as $history)
        <li class="py-1 border-bottom">
            <span class="text-muted">{{ $history->created_at?->format('j M Y, H:i') }}</span>
            · {{ Membership::LABELS[$history->to_status] ?? $history->to_status }}
            · <span class="text-muted">{{ $history->changedBy?->name ?? 'System' }}</span>
            @if ($history->note)<div class="text-muted">{{ $history->note }}</div>@endif
        </li>
    @endforeach
</ul>
