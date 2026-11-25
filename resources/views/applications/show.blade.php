@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Application', $membership->application_data['name'] ?? '']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Review a membership application'))

@use('Iquesters\Community\Models\Membership')

@php
    $data = (array) $membership->application_data;
    $badge = [
        Membership::STATUS_APPLIED => 'text-bg-info',
        Membership::STATUS_ON_HOLD => 'text-bg-warning',
        Membership::STATUS_APPROVED => 'text-bg-success',
        Membership::STATUS_REJECTED => 'text-bg-danger',
    ][$membership->status] ?? 'text-bg-secondary';
    // A record already linked to someone else can't be chosen.
    $linkable = collect($matches)->reject(fn ($m) => $m['linked_to_other_user']);
@endphp

@section('content')
<div class="d-grid gap-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="{{ route('community.applications.index') }}" class="small text-decoration-none"><i class="fas fa-fw fa-arrow-left"></i> Applications</a>
        <span class="badge {{ $badge }}">{{ $membership->statusLabel() }}</span>
    </div>

    @if (session('error'))
        <div class="alert alert-danger py-2 small mb-0">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success py-2 small mb-0">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        {{-- What the applicant sent --}}
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fs-6 mb-3">Application</h6>
                    <dl class="row small mb-0">
                        @foreach ($fields as $field)
                            <dt class="col-5 text-muted fw-normal">{{ $field['label'] }}</dt>
                            <dd class="col-7">{{ $data[$field['id']] ?? '—' }}</dd>
                        @endforeach
                        <dt class="col-5 text-muted fw-normal">Account</dt>
                        <dd class="col-7">{{ $membership->user?->email ?? '—' }}</dd>
                        <dt class="col-5 text-muted fw-normal">Organisation</dt>
                        <dd class="col-7">{{ $membership->organisation?->name }}</dd>
                        <dt class="col-5 text-muted fw-normal">Applied</dt>
                        <dd class="col-7">{{ $membership->created_at?->format('j M Y, H:i') }}</dd>
                    </dl>

                    @if ($membership->proofs->isNotEmpty())
                        <h6 class="fs-6 mt-3 mb-2">Proof</h6>
                        <ul class="list-unstyled small mb-0">
                            @foreach ($membership->proofs as $proof)
                                <li class="py-1 border-bottom">
                                    <span class="text-muted">{{ $proof->label }}:</span>
                                    @if ($proof->isFile())
                                        <a href="{{ route('community.proofs.download', $proof->uid) }}"><i class="fas fa-fw fa-paperclip"></i>{{ $proof->original_name }}</a>
                                    @else
                                        {{ $proof->value }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Records that are probably this person, and the decision --}}
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    @if ($membership->isOpen())
                        <form method="POST" action="{{ route('community.applications.decide', $membership->uid) }}" class="d-grid gap-3">
                            @csrf
                            <div>
                                <h6 class="fs-6 mb-1">Matching records</h6>
                                <p class="text-muted small mb-2">Records from your data that look like this person. Approving links their account to the one you pick.</p>

                                @forelse ($matches as $match)
                                    @php($record = $match['record'])
                                    <label class="d-flex gap-2 align-items-start border rounded p-2 mb-2 small @if ($match['linked_to_other_user']) opacity-50 @endif">
                                        <input type="radio" name="link_record_uid" value="{{ $record->uid }}" class="form-check-input mt-1"
                                               @disabled($match['linked_to_other_user']) @checked(old('link_record_uid', $linkable->count() === 1 ? $linkable->first()['record']->uid : null) === $record->uid)>
                                        <span>
                                            <span class="fw-semibold">{{ $record->name }}</span>
                                            <span class="text-muted">· {{ $record->email ?? 'no email' }} · {{ $record->getMetaValue('batch') ?? '' }}</span>
                                            <span class="d-block text-muted">Same {{ implode(', ', $match['keys']) }}
                                                @if ($match['linked_to_other_user']) · already linked to another account @endif</span>
                                        </span>
                                    </label>
                                @empty
                                    <div class="text-muted small mb-2">No matching record. Approving creates a new one from the application.</div>
                                @endforelse

                                @if ($matches)
                                    <label class="d-flex gap-2 align-items-center border rounded p-2 small">
                                        <input type="radio" name="link_record_uid" value="" class="form-check-input m-0"
                                               @checked(old('link_record_uid', $linkable->count() === 1 ? 'x' : '') === '')>
                                        <span>None of these: create a new record</span>
                                    </label>
                                @endif
                            </div>

                            <div>
                                <label for="note" class="form-label small">Note to the applicant <span class="text-muted">(required to hold or reject)</span></label>
                                <textarea id="note" name="note" rows="2" maxlength="2000" class="form-control form-control-sm @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                                @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="d-flex flex-wrap gap-2 justify-content-end">
                                <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                                <button type="submit" name="decision" value="hold" class="btn btn-sm btn-outline-warning">Hold</button>
                                <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success">Approve</button>
                            </div>
                        </form>
                    @else
                        <h6 class="fs-6 mb-2">Decision</h6>
                        <p class="small mb-1">{{ $membership->statusLabel() }} {{ $membership->decided_at?->diffForHumans() }}.</p>
                        @if ($membership->decision_note)<p class="small text-muted mb-1">{{ $membership->decision_note }}</p>@endif
                        @if ($membership->memberRecord)
                            <p class="small mb-0">Record: <span class="fw-semibold">{{ $membership->memberRecord->name }}</span>
                                <span class="text-muted">({{ $membership->memberRecord->source === 'registration' ? 'created from the application' : 'linked to an existing record' }})</span></p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- History --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h6 class="fs-6 mb-2">History</h6>
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
        </div>
    </div>
</div>
@endsection
