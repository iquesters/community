@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Preview', 'Bulk upload']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('Check the rows before importing'))

@use('Iquesters\Community\Services\Import\MemberImportService', 'Import')

@php
    $summary = $preview['summary'];
    $badges = [
        Import::STATUS_VALID => ['text-bg-success', 'New'],
        Import::STATUS_EXISTING => ['text-bg-warning', 'Already exists'],
        Import::STATUS_DUPLICATE_IN_FILE => ['text-bg-secondary', 'Duplicate in file'],
        Import::STATUS_ERROR => ['text-bg-danger', 'Error'],
    ];
@endphp

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="small">
                    <strong>{{ $summary['total'] }}</strong> rows
                    @if ($preview['organisation']) for <strong>{{ $preview['organisation']['name'] }}</strong>@endif:
                    <span class="text-success">{{ $summary['valid'] }} new</span>,
                    <span class="text-warning-emphasis">{{ $summary['existing'] }} already exist</span>,
                    <span class="text-secondary">{{ $summary['duplicates_in_file'] }} duplicates in the file</span>,
                    <span class="text-danger">{{ $summary['errors'] }} with errors</span>.
                </div>

                <form method="POST" action="{{ route('community.members.import.store', $preview['token']) }}" class="d-flex flex-wrap gap-2 align-items-center">
                    @csrf
                    @if ($summary['existing'] > 0)
                        <select name="on_duplicate" class="form-select form-select-sm w-auto" aria-label="Rows that already exist">
                            <option value="{{ Import::ON_DUPLICATE_SKIP }}">Skip rows that already exist</option>
                            <option value="{{ Import::ON_DUPLICATE_UPDATE }}">Update the existing record (one match only)</option>
                        </select>
                    @else
                        <input type="hidden" name="on_duplicate" value="{{ Import::ON_DUPLICATE_SKIP }}">
                    @endif
                    <a href="{{ route('community.members.import.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary" @disabled($summary['valid'] + $summary['existing'] === 0)>
                        Import {{ $summary['valid'] }} new
                    </button>
                </form>
            </div>

            @if ($preview['unknown_columns'])
                <div class="alert alert-info py-2 small mt-3 mb-0">
                    Ignored columns: {{ implode(', ', $preview['unknown_columns']) }}
                </div>
            @endif
            <p class="text-muted small mt-2 mb-0">Rows with errors and duplicates in the file are not imported. This preview is kept for 30 minutes.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Row</th>
                        <th>Status</th>
                        @foreach ($preview['columns'] as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        @php([$class, $label] = $badges[$row['status']])
                        <tr>
                            <td class="text-muted">{{ $row['line'] }}</td>
                            <td><span class="badge {{ $class }}">{{ $label }}</span></td>
                            @foreach ($preview['columns'] as $column)
                                <td>{{ $row['data'][$column['id']] ?? '' }}</td>
                            @endforeach
                            <td>
                                @if ($row['errors'])
                                    <span class="text-danger">{{ implode(' ', $row['errors']) }}</span>
                                @elseif ($row['duplicate_of_line'])
                                    Same as row {{ $row['duplicate_of_line'] }}
                                @elseif ($row['matches'])
                                    @foreach ($row['matches'] as $match)
                                        <div>{{ $match['name'] }} <span class="text-muted">(same {{ implode(', ', $match['keys']) }})</span></div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
