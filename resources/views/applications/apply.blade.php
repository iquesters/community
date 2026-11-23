@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Apply for membership']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Apply for membership'))

@use('Iquesters\Community\Models\Membership')

@php
    $status = $membership?->status;
    // The form shows for a first application, one on hold (send it again), or after a rejection (apply again).
    $showForm = $organisation && (! $membership || in_array($status, [Membership::STATUS_ON_HOLD, Membership::STATUS_REJECTED], true));
    $prefill = $status === Membership::STATUS_REJECTED ? [] : ($values ?? []);
    $alert = [
        Membership::STATUS_APPLIED => ['info', 'fa-hourglass-half', 'Your application is waiting for a decision.'],
        Membership::STATUS_ON_HOLD => ['warning', 'fa-circle-question', 'An administrator needs more from you. Update your application below and send it again.'],
        Membership::STATUS_APPROVED => ['success', 'fa-circle-check', "You're a ".strtolower($memberLabel).'.'],
        Membership::STATUS_REJECTED => ['danger', 'fa-circle-xmark', 'Your application was not accepted. You can apply again with more details.'],
    ][$status] ?? null;
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-8 d-grid gap-3">
        @if (session('error'))
            <div class="alert alert-danger py-2 small mb-0">{{ session('error') }}</div>
        @endif
        @if (session('success'))
            <div class="alert alert-success py-2 small mb-0">{{ session('success') }}</div>
        @endif

        @if (! $organisation && $organisations->isNotEmpty())
            {{-- Multiple organisations: pick one first. --}}
            <form method="GET" action="{{ route('community.apply.show') }}" class="card border-0 shadow-sm">
                <div class="card-body d-flex gap-2 align-items-end">
                    <div class="flex-grow-1">
                        <label for="organisation" class="form-label small">Which organisation do you want to join?</label>
                        <select id="organisation" name="organisation" class="form-select form-select-sm" required>
                            <option value="">Choose…</option>
                            @foreach ($organisations as $option)
                                <option value="{{ $option->uid }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-sm btn-outline-primary">Continue</button>
                </div>
            </form>
        @elseif (! $organisation)
            <div class="alert alert-warning small mb-0">Applications aren't open yet. Please check back later.</div>
        @endif

        @if ($alert)
            <div class="alert alert-{{ $alert[0] }} small mb-0">
                <i class="fas fa-fw {{ $alert[1] }}"></i> {{ $alert[2] }}
                @if ($membership->decision_note && in_array($status, [Membership::STATUS_ON_HOLD, Membership::STATUS_REJECTED], true))
                    <div class="mt-2"><span class="fw-semibold">Note from the administrator:</span> {{ $membership->decision_note }}</div>
                @endif
            </div>
        @endif

        @if ($showForm)
            <form method="POST" action="{{ route('community.apply.store') }}" enctype="multipart/form-data" class="card border-0 shadow-sm">
                @csrf
                <input type="hidden" name="organisation_uid" value="{{ $organisation->uid }}">
                <div class="card-body d-grid gap-3">
                    <div>
                        <h5 class="fs-6 mb-1">Apply to {{ $organisation->name }}</h5>
                        <p class="text-muted small mb-0">Fill in your details as the school would have them, so we can find your record.</p>
                    </div>

                    <div class="row g-3">
                        @foreach ($fields as $field)
                            @php
                                $name = "data[{$field['id']}]";
                                $value = old("data.{$field['id']}", $prefill[$field['id']] ?? null);
                                $inputType = in_array($field['type'], ['email', 'tel', 'number', 'date'], true) ? $field['type'] : 'text';
                            @endphp
                            <div class="col-12 col-md-6">
                                <label for="f-{{ $field['id'] }}" class="form-label small">
                                    {{ $field['label'] }} @if ($field['required'])<span class="text-danger">*</span>@endif
                                </label>
                                @if (in_array($field['type'], ['select', 'radio'], true) && $field['options'])
                                    <select id="f-{{ $field['id'] }}" name="{{ $name }}" class="form-select form-select-sm @error($field['id']) is-invalid @enderror" @required($field['required'])>
                                        <option value="">Choose…</option>
                                        @foreach ($field['options'] as $option)
                                            <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field['type'] === 'textarea')
                                    <textarea id="f-{{ $field['id'] }}" name="{{ $name }}" rows="2" class="form-control form-control-sm @error($field['id']) is-invalid @enderror" @required($field['required'])>{{ $value }}</textarea>
                                @else
                                    <input id="f-{{ $field['id'] }}" type="{{ $inputType }}" name="{{ $name }}" value="{{ $value }}"
                                           class="form-control form-control-sm @error($field['id']) is-invalid @enderror" @required($field['required'])>
                                @endif
                                @error($field['id'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>

                    @if ($proofFields)
                        <div>
                            <h6 class="fs-6 mb-1">Proof</h6>
                            <p class="text-muted small mb-2">Helps us confirm you, especially if the school's records don't have you. Only administrators see it.</p>
                            <div class="row g-3">
                                @foreach ($proofFields as $proof)
                                    @php
                                        $existing = $membership?->proofs?->where('field', $proof['id'])->last();
                                    @endphp
                                    <div class="col-12 col-md-6">
                                        <label for="p-{{ $proof['id'] }}" class="form-label small">
                                            {{ $proof['label'] }} @if ($proof['required'])<span class="text-danger">*</span>@endif
                                        </label>
                                        @if ($proof['kind'] === 'file')
                                            <input id="p-{{ $proof['id'] }}" type="file" name="proof[{{ $proof['id'] }}]"
                                                   accept="{{ collect($proof['types'])->map(fn ($t) => '.'.$t)->implode(',') }}"
                                                   class="form-control form-control-sm @error('proof.'.$proof['id']) is-invalid @enderror"
                                                   @required($proof['required'] && ! $existing)>
                                            <div class="form-text">{{ strtoupper(implode(', ', $proof['types'])) }}, up to {{ round($proof['max_kb'] / 1024, 1) }} MB.
                                                @if ($existing) Sent: <a href="{{ route('community.proofs.download', $existing->uid) }}">{{ $existing->original_name }}</a>@endif
                                            </div>
                                        @else
                                            <input id="p-{{ $proof['id'] }}" type="text" name="proof[{{ $proof['id'] }}]" maxlength="255"
                                                   value="{{ old('proof.'.$proof['id'], $existing?->value) }}"
                                                   class="form-control form-control-sm @error('proof.'.$proof['id']) is-invalid @enderror" @required($proof['required'])>
                                        @endif
                                        @error('proof.'.$proof['id'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-sm btn-primary">
                            {{ $status === Membership::STATUS_ON_HOLD ? 'Send again' : 'Apply' }}
                        </button>
                    </div>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
