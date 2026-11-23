<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Community\Services\Import\MemberImportService;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Foundation\System\Traits\Loggable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin bulk upload of member records: upload a CSV, check the preview, import.
 */
class MemberImportController extends Controller
{
    use Loggable;

    public function __construct(private MemberImportService $imports) {}

    public function index(): View
    {
        return view('community::members.import.index', [
            'fields' => DirectoryFields::all(),
            'multiple' => OrganisationResolver::isMultiple(),
            'organisations' => OrganisationResolver::isMultiple() ? OrganisationResolver::choices() : collect(),
            // No organisation: the page explains it and links to creating one, instead of the form.
            'hasOrganisation' => OrganisationResolver::available(),
            'createOrganisationUrl' => Route::has('organisations.create') ? route('organisations.create') : null,
            'recordLabel' => DirectoryFields::conf()->record_label,
            'maxRows' => (int) DirectoryFields::conf()->import_max_rows,
        ]);
    }

    /** An empty CSV with the directory's columns. */
    public function template(): StreamedResponse
    {
        $header = $this->imports->templateHeader();

        return response()->streamDownload(function () use ($header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens it as UTF-8
            fputcsv($out, $header, ',', '"', '');
            fclose($out);
        }, 'member-records-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'organisation_uid' => ['nullable', 'string', 'max:26'],
        ]);

        try {
            $organisation = OrganisationResolver::resolve($request->input('organisation_uid'));
            $preview = $this->imports->preview($request->file('file')->getRealPath(), $organisation, (int) $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        static::logInfo('Member import previewed', ['user_id' => $request->user()->id, 'summary' => $preview['summary']]);

        return view('community::members.import.preview', ['preview' => $preview]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'on_duplicate' => ['required', Rule::in([MemberImportService::ON_DUPLICATE_SKIP, MemberImportService::ON_DUPLICATE_UPDATE])],
        ]);

        try {
            $result = $this->imports->import($token, (int) $request->user()->id, $validated['on_duplicate']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('community.members.import.index')->with('error', $e->getMessage());
        }

        static::logInfo('Member import done', ['user_id' => $request->user()->id] + $result);

        return redirect()->route('community.members.import.index')->with('success', sprintf(
            'Import done: %d created, %d updated, %d skipped, %d with errors.',
            $result['created'], $result['updated'], $result['skipped'], $result['failed']
        ));
    }
}
