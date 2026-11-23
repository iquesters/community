@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Applications']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Membership applications waiting for a decision'))

@use('Iquesters\Community\Models\Membership')

@php
    $badge = [
        Membership::STATUS_APPLIED => 'text-bg-info',
        Membership::STATUS_ON_HOLD => 'text-bg-warning',
        Membership::STATUS_APPROVED => 'text-bg-success',
        Membership::STATUS_REJECTED => 'text-bg-danger',
    ];
    $tabs = ['open' => 'Waiting', Membership::STATUS_APPLIED => 'Applied', Membership::STATUS_ON_HOLD => 'On hold',
        Membership::STATUS_APPROVED => 'Approved', Membership::STATUS_REJECTED => 'Rejected', 'all' => 'All'];
    $countFor = fn ($key) => match ($key) {
        'open' => ($counts[Membership::STATUS_APPLIED] ?? 0) + ($counts[Membership::STATUS_ON_HOLD] ?? 0),
        'all' => $counts->sum(),
        default => $counts[$key] ?? 0,
    };
@endphp

@section('content')
<div class="d-grid gap-3">
    <ul class="nav nav-pills small flex-nowrap overflow-auto">
        @foreach ($tabs as $key => $label)
            <li class="nav-item">
                <a class="nav-link py-1 px-2 text-nowrap @if ($status === $key) active @endif" href="{{ route('community.applications.index', ['status' => $key]) }}">
                    {{ $label }} <span class="badge text-bg-light ms-1">{{ $countFor($key) }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card border-0 shadow-sm">
        @if ($applications->isEmpty())
            <div class="card-body text-muted small">No applications here.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th class="d-none d-md-table-cell">Account</th>
                            <th class="d-none d-lg-table-cell">Organisation</th>
                            <th>Status</th>
                            <th class="d-none d-md-table-cell">Applied</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($applications as $application)
                            <tr>
                                <td>
                                    <a href="{{ route('community.applications.show', $application->uid) }}" class="stretched-link text-decoration-none">
                                        {{ $application->application_data['name'] ?? $application->user?->name ?? '—' }}
                                    </a>
                                    @if (! empty($application->application_data['batch']))
                                        <span class="text-muted">· {{ $application->application_data['batch'] }}</span>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell text-muted">{{ $application->user?->email ?? '—' }}</td>
                                <td class="d-none d-lg-table-cell">{{ $application->organisation?->name }}</td>
                                <td><span class="badge {{ $badge[$application->status] ?? 'text-bg-secondary' }}">{{ $application->statusLabel() }}</span></td>
                                <td class="d-none d-md-table-cell text-muted">{{ $application->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{ $applications->links() }}
</div>
@endsection
