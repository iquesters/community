@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['School levels', $organisation->name]))

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h5 class="fs-6 mb-1">School levels of {{ $organisation->name }}</h5>
                    <p class="text-muted small mb-0">
                        What people pass out with at this school, lowest class first. The application form, bulk upload and the directory use them.
                        @unless ($ownLevels) These are the app's defaults until you save. @endunless
                    </p>
                </div>
                @if ($organisations->count() > 1)
                    <div class="d-flex flex-wrap gap-1">
                        @foreach ($organisations as $other)
                            <a href="{{ route('community.school-levels.edit', $other->uid) }}"
                               class="btn btn-sm {{ $other->uid === $organisation->uid ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $other->name }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Form schema community-school-levels. --}}
            {{-- entity_uid: the form's {uid} (its address); read-only first, Edit switches. --}}
            @include('userinterface::components.form', [
                'id' => $formId,
                'entity_uid' => $organisation->uid,
                'form_mode' => 'view',
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])
        </div>
    </div>
</div>
@endsection
