@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Your details', $organisation->name]))

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5 class="fs-6 mb-1">Your details at {{ $organisation->name }}</h5>
            <p class="text-muted small mb-3">
                What {{ $organisation->name }} has on record for you. Fellow members see your name, your school years
                and the fields the association shares; your email and phone stay private.
            </p>

            {{-- Form schema community-member-details-{organisation} (directory fields and school records). --}}
            @include('userinterface::components.form', [
                'id' => $formId,
                'entity_uid' => $membership->uid,
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])

            @if ($sentFiles->isNotEmpty())
                <p class="small text-muted mt-3 mb-0">
                    Proof already sent:
                    @foreach ($sentFiles as $file)
                        <a href="{{ route('community.proofs.download', $file->uid) }}" target="_blank" rel="noopener">{{ $file->original_name }}</a>@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
