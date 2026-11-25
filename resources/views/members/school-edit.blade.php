@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['School records', $record->name]))

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5 class="fs-6 mb-1">School records of {{ $record->name }}</h5>
            <p class="text-muted small mb-3">
                {{ $record->organisation?->name }}. One row per level (e.g. ICSE and its year, ISC and its year), or
                "Left before finishing" with the class they left after and the year.
            </p>

            {{-- Form schema community-member-school-records-{organisation} (its levels). --}}
            {{-- entity_uid: the form's {uid} (its address); read-only first, Edit switches. --}}
            @include('userinterface::components.form', [
                'id' => $formId,
                'entity_uid' => $record->uid,
                'form_mode' => 'view',
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])
        </div>
    </div>
</div>
@endsection
