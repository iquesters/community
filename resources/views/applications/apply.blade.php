@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Membership']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Apply for membership'))

@use('Iquesters\Community\Models\Membership')

@php
    $status = $membership?->status;
    // The form shows for a first application, one on hold (send it again), or after a rejection (apply again).
    // Not while their membership is in an order being paid (e.g. with an event registration).
    $showForm = $organisation && empty($inOrder) && (! $membership || in_array($status, [Membership::STATUS_ON_HOLD, Membership::STATUS_REJECTED], true));
    $alert = [
        Membership::STATUS_APPLIED => ['info', 'fa-hourglass-half', 'Your application is waiting for a decision.', 'Application sent'],
        Membership::STATUS_ON_HOLD => ['warning', 'fa-circle-question', 'An administrator needs more from you. Update your application below and send it again.', 'More details needed'],
        Membership::STATUS_REJECTED => ['danger', 'fa-circle-xmark', 'Your application was not accepted. You can apply again with more details.', 'Not accepted'],
    ][$status] ?? null;
    $sentFiles = $status === Membership::STATUS_ON_HOLD ? $membership->proofs->where('kind', 'file') : collect();
@endphp

@section('content')
{{-- Full width, like every other page in the app layout. --}}
<div class="d-grid gap-3">
        {{-- Applied (waiting): they may pay the membership meanwhile; an admin still decides the application. --}}
        @if ($organisation && $membership && $membership->isOpen())
            @include('community::membership.pay-card', ['organisation' => $organisation])
        @endif

        @if (! $organisation && $organisations->isNotEmpty())
            {{-- Multiple organisations: pick one first. --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    {{-- Form schema community-organisation-choice. --}}
                    {{-- Its own (empty) data: an @include inherits this page's $formData (the
                         application's values, an array), which crashed the page in multiple mode. --}}
                    @include('userinterface::components.form', [
                        'id' => \Iquesters\Community\Support\CommunityForms::ORGANISATION_CHOICE,
                        'formData' => '',
                        'entity_uid' => null,
                        'form_mode' => null,
                    ])
                </div>
            </div>
        @elseif (! $organisation)
            <div class="alert alert-warning small mb-0">Applications aren't open yet. Please check back later.</div>
        @endif

        @if (! empty($inOrder))
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fs-6 mb-1">No need to apply</h6>
                    @include('community::membership.in-order', ['inOrder' => $inOrder, 'bordered' => false, 'return' => '/community/membership?organisation='.$organisation->uid])
                </div>
            </div>
        @endif

        {{-- Where the application is (not a flash message: it stays until the status changes). --}}
        @if ($alert)
            <div class="card border-0 shadow-sm">
                <div class="card-body d-flex gap-3 align-items-start">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-{{ $alert[0] }}-subtle text-{{ $alert[0] }}-emphasis flex-shrink-0" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fas {{ $alert[1] }}" aria-hidden="true"></i>
                    </span>
                    <div class="small">
                        <div class="fw-semibold">{{ $alert[3] }}</div>
                        <div class="text-muted">{{ $alert[2] }}</div>
                        @if ($membership->decision_note && in_array($status, [Membership::STATUS_ON_HOLD, Membership::STATUS_REJECTED], true))
                            <div class="mt-2"><span class="fw-semibold">Note from the administrator:</span> {{ $membership->decision_note }}</div>
                        @endif
                        <div class="text-muted mt-1">Applied {{ $membership->created_at?->format('j M Y') }}</div>
                    </div>
                </div>
            </div>
        @endif

        @if ($showForm)
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="fs-6 mb-1">Apply to {{ $organisation->name }}</h5>
                    <p class="text-muted small mb-3">Fill in your details as the school would have them, so we can find your record. Proof helps when the school's records don't have you.</p>
                    <p class="small mb-3">
                        <i class="fas fa-file-contract text-muted me-1" aria-hidden="true"></i>
                        By applying you agree to
                        @foreach (\Iquesters\Organisation\Support\OrganisationPolicies::links($organisation) as $link)
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $link['label'] }}</a>@if (! $loop->last) and @endif
                        @endforeach
                    </p>

                    {{-- Form schema "community-membership-application" (built by community:seed from the directory and proof settings). --}}
                    @include('userinterface::components.form', [
                        'id' => $formId,
                        'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
                    ])

                    @if ($sentFiles->isNotEmpty())
                        <p class="small text-muted mt-3 mb-0">
                            Already sent:
                            @foreach ($sentFiles as $file)
                                <a href="{{ route('community.proofs.download', $file->uid) }}">{{ $file->original_name }}</a>@if (! $loop->last), @endif
                            @endforeach
                            . Add another file only if you have more.
                        </p>
                    @endif
                </div>
            </div>
        @endif
</div>
@endsection
