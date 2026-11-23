@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Bulk upload', $recordLabel]))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Upload member records from a CSV file'))

@section('content')
<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="fs-6 mb-1">Upload {{ \Illuminate\Support\Str::plural(strtolower($recordLabel)) }}</h5>
                <p class="text-muted small mb-3">
                    Upload a CSV file (one row per person). You'll see a preview with any errors and duplicates before anything is saved.
                    At most {{ number_format($maxRows) }} rows per file.
                </p>

                @if (session('error'))
                    <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
                @endif
                @if (session('success'))
                    <div class="alert alert-success py-2 small">{{ session('success') }}</div>
                @endif

                @if (! $hasOrganisation)
                    <div class="alert alert-warning small mb-0" role="alert">
                        <div class="fw-semibold mb-1"><i class="fas fa-fw fa-building"></i> No organisation yet</div>
                        <p class="mb-2">Every {{ strtolower($recordLabel) }} belongs to an organisation. Create one first, then come back to upload.</p>
                        @if ($createOrganisationUrl)
                            <a href="{{ $createOrganisationUrl }}" class="btn btn-sm btn-outline-primary">Create organisation</a>
                        @endif
                    </div>
                @else
                <form method="POST" action="{{ route('community.members.import.preview') }}" enctype="multipart/form-data" class="d-grid gap-3">
                    @csrf

                    @if ($multiple)
                        <div>
                            <label for="organisation_uid" class="form-label small">Organisation</label>
                            <select id="organisation_uid" name="organisation_uid" class="form-select form-select-sm" required>
                                <option value="">Choose…</option>
                                @foreach ($organisations as $organisation)
                                    <option value="{{ $organisation->uid }}" @selected(old('organisation_uid') === $organisation->uid)>{{ $organisation->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label for="file" class="form-label small">CSV file</label>
                        <input id="file" type="file" name="file" accept=".csv,text/csv" class="form-control form-control-sm @error('file') is-invalid @enderror" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">From Excel: File → Save As → "CSV UTF-8".</div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="{{ route('community.members.import.template') }}" class="small">
                            <i class="fas fa-fw fa-download"></i> Download the template
                        </a>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Preview</button>
                    </div>
                </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fs-6 mb-2">Columns</h6>
                <p class="text-muted small mb-2">The first row must name the columns, by label or id, in any order. Other columns are ignored.</p>
                <ul class="list-unstyled small mb-0">
                    @foreach ($fields as $field)
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span>{{ $field['label'] }} <span class="text-muted">({{ $field['id'] }})</span></span>
                            @if ($field['required'])<span class="badge text-bg-light">required</span>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
