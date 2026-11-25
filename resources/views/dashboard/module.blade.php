@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Community']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Community'))

@use('Iquesters\Community\Models\Membership')
@use('Iquesters\Community\Support\CommunityAccess')

@php
    $user = auth()->user();
    $allowed = CommunityAccess::manageableOrganisationIds($user);
    $waiting = $allowed ? Membership::query()->whereIn('organisation_id', $allowed)->whereIn('status', Membership::OPEN_STATUSES)->count() : 0;
    $members = $allowed ? Membership::query()->whereIn('organisation_id', $allowed)->where('status', Membership::STATUS_APPROVED)->count() : 0;
    $card = app(\Iquesters\Community\Dashboard\MembershipCard::class)->render($user);
@endphp

@section('content')
<div class="d-grid gap-3">
    @if ($allowed)
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <a href="{{ route('community.applications.index') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                    <div class="card-body">
                        <div class="small text-muted">Waiting for a decision</div>
                        <div class="fs-4 fw-semibold">{{ $waiting }}</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="small text-muted">Members</div>
                        <div class="fs-4 fw-semibold">{{ $members }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($card)
        {!! $card !!}
    @endif

    {{-- The module's pages this user can open (admins: Members, Applications, Bulk upload). --}}
    @if ($allowed && $items->isNotEmpty())
        <div class="row g-2 g-md-3">
            @foreach ($items as $item)
                <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                    <a href="{{ $item->href() }}" class="card h-100 text-decoration-none text-body">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary flex-shrink-0" style="width: 2.5rem; height: 2.5rem;">
                                <i class="{{ $item->icon ?: 'fas fa-circle' }}" aria-hidden="true"></i>
                            </span>
                            <span class="fw-medium text-truncate">{{ $item->label }}</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
