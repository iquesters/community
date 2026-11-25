{{-- Record section (Members table): what the member completed at the school. --}}
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="small">
        <div class="text-muted mb-1">At school</div>
        <div class="fw-semibold">{{ $summary !== '' ? $summary : 'Not given yet' }}</div>
    </div>
    <a href="{{ route('community.members.school.edit', $record->uid) }}" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-fw fa-graduation-cap"></i> Edit school records
    </a>
</div>
