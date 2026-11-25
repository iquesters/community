@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Membership', $organisation->name]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Your membership'))

@php
    $record = $membership->memberRecord;
    $values = $record ? $record->metas->pluck('meta_value', 'meta_key')->merge(['email' => $record->email, 'phone' => $record->phone]) : collect();
@endphp

@section('content')
{{-- Full width, like every other page in the app layout. --}}
<div class="d-grid gap-3">
        {{-- The membership --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success-emphasis flex-shrink-0" style="width: 3rem; height: 3rem;">
                    <i class="fas fa-id-card fs-5" aria-hidden="true"></i>
                </span>
                <div class="flex-grow-1">
                    <div class="small text-success fw-semibold">{{ $memberLabel }}</div>
                    <div class="fs-5 fw-semibold">{{ $organisation->name }}</div>
                    <div class="small text-muted">Member since {{ ($membership->decided_at ?? $membership->created_at)?->format('j M Y') }}</div>
                </div>
                @if (\Illuminate\Support\Facades\Route::has('organisations.profile.show'))
                    <a href="{{ route('organisations.profile.show', $organisation->uid) }}" class="btn btn-sm btn-outline-secondary">About {{ $organisation->name }}</a>
                @endif
            </div>
        </div>

        {{-- What the organisation has on record for them --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h6 class="fs-6 mb-0">Your details</h6>
                    <a href="{{ route('community.membership.details', $membership->uid) }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-fw fa-pen"></i> Edit my details
                    </a>
                </div>
                @if (! filled($school ?? ''))
                    {{-- E.g. an organisation admin, made a member without an application. --}}
                    <div class="alert alert-warning small d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><span class="fw-semibold">Complete your details:</span> add what you completed at the school (e.g. ICSE and the year), so fellow members can find you.</span>
                        <a href="{{ route('community.membership.details', $membership->uid) }}" class="btn btn-sm btn-warning">Complete now</a>
                    </div>
                @endif
                <dl class="row small mb-0">
                    <dt class="col-sm-4 col-lg-3 col-xxl-2 text-muted fw-normal">Name</dt>
                    <dd class="col-sm-8 col-lg-9 col-xxl-10">{{ $record?->name ?? auth()->user()->name }}</dd>
                    @if (filled($school ?? ''))
                        <dt class="col-sm-4 col-lg-3 col-xxl-2 text-muted fw-normal">At school</dt>
                        <dd class="col-sm-8 col-lg-9 col-xxl-10">{{ $school }}</dd>
                    @endif
                    @foreach ($fields as $field)
                        @if (filled($values->get($field['id'])))
                            <dt class="col-sm-4 col-lg-3 col-xxl-2 text-muted fw-normal">{{ $field['label'] }}</dt>
                            <dd class="col-sm-8 col-lg-9 col-xxl-10">{{ $values->get($field['id']) }}</dd>
                        @endif
                    @endforeach
                </dl>
                <p class="small text-muted mb-0 mt-2">Something you can't change here? Ask an administrator of {{ $organisation->name }}.</p>
            </div>
        </div>
</div>
@endsection
