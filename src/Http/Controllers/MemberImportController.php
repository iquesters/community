<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Community\Services\Import\MemberImportService;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\CommunityForms;
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

    public function index(Request $request): View
    {
        $allowed = CommunityAccess::manageableOrganisationIds($request->user());
        $organisations = OrganisationResolver::options($allowed);

        return view('community::members.import.index', [
            'fields' => DirectoryFields::all(),
            'multiple' => OrganisationResolver::isMultiple(),
            'organisations' => OrganisationResolver::isMultiple() ? OrganisationResolver::choices($allowed) : collect(),
            // No organisation (that this admin manages): a notice instead of the form.
            'hasOrganisation' => collect($organisations)->contains(fn ($o) => $o['value'] !== ''),
            'createOrganisationUrl' => Route::has('organisations.create') ? route('organisations.create') : null,
            'recordLabel' => DirectoryFields::conf()->record_label,
            'maxRows' => (int) DirectoryFields::conf()->import_max_rows,
            // The upload form (form schema community-member-import): the organisation preselected.
            'formId' => CommunityForms::IMPORT,
            'formData' => ['organisation_uid' => $this->preselected($request)],
        ]);
    }

    /** Options of the upload form's organisation select: the organisations this admin manages. */
    public function organisations(Request $request): JsonResponse
    {
        $allowed = CommunityAccess::manageableOrganisationIds($request->user());
        $organisations = OrganisationResolver::isMultiple()
            ? OrganisationResolver::choices($allowed)
            : collect([OrganisationResolver::default()])->filter()->whereIn('id', $allowed);

        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => 'Choose…'],
            ...$organisations->map(fn ($o) => ['value' => $o->uid, 'label' => $o->name])->values()->all(),
        ]]);
    }

    /** A preview made earlier (the upload form goes here after checking the file). */
    public function show(Request $request, string $token): View|RedirectResponse
    {
        $preview = $this->imports->stored($token, (int) $request->user()->id);
        if (! $preview) {
            return redirect()->route('community.members.import.index')->with('error', 'This preview has expired. Upload the file again.');
        }

        return view('community::members.import.preview', ['preview' => $preview]);
    }

    private function preselected(Request $request): ?string
    {
        $context = OrganisationResolver::context();
        if ($context && CommunityAccess::canManageOrganisation($request->user(), (int) $context->id)) {
            return $context->uid;
        }

        return OrganisationResolver::isMultiple() ? null : OrganisationResolver::default()?->uid;
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

    /** The upload form posts here: check the file, keep the preview, show it. */
    public function preview(Request $request): View|RedirectResponse|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'organisation_uid' => ['nullable', 'string', 'max:26'],
        ]);

        try {
            $organisation = OrganisationResolver::resolve($request->input('organisation_uid'), CommunityAccess::manageableOrganisationIds($request->user()));
            $preview = $this->imports->preview($request->file('file')->getRealPath(), $organisation, (int) $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422)
                : back()->withInput()->with('error', $e->getMessage());
        }

        static::logInfo('Member import previewed', ['user_id' => $request->user()->id, 'summary' => $preview['summary']]);

        // The form engine opens the preview page (its own address, kept 30 minutes).
        return $request->expectsJson()
            ? response()->json(['success' => true, 'redirect_url' => route('community.members.import.show', $preview['token'])])
            : view('community::members.import.preview', ['preview' => $preview]);
    }

    /** The confirm form (form schema community-member-import-confirm) posts here. */
    public function store(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'on_duplicate' => ['required', Rule::in([MemberImportService::ON_DUPLICATE_SKIP, MemberImportService::ON_DUPLICATE_UPDATE])],
        ]);

        // Still allowed to manage the preview's organisation (roles can change in 30 minutes).
        $stored = $this->imports->stored($token, (int) $request->user()->id);
        if ($stored && ! CommunityAccess::canManageOrganisation($request->user(), $stored['organisation']['id'] ?? null)) {
            abort(403);
        }

        try {
            $result = $this->imports->import($token, (int) $request->user()->id, $validated['on_duplicate']);
        } catch (\InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 410)
                : redirect()->route('community.members.import.index')->with('error', $e->getMessage());
        }

        static::logInfo('Member import done', ['user_id' => $request->user()->id] + $result);

        $message = sprintf('Import done: %d created, %d updated, %d skipped, %d with errors.', $result['created'], $result['updated'], $result['skipped'], $result['failed']);
        if ($request->expectsJson()) {
            session()->flash('success', $message);

            return response()->json(['success' => true, 'message' => $message, 'redirect_url' => route('community.members.import.index')]);
        }

        return redirect()->route('community.members.import.index')->with('success', $message);
    }
}
