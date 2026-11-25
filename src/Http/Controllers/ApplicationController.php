<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\AdminMemberships;
use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\DirectoryTable;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\ProofFields;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationPolicies;

/**
 * The applicant's side: apply for membership, see the application's status,
 * and send it again when an admin put it on hold.
 */
class ApplicationController extends Controller
{
    public function __construct(private MembershipService $memberships) {}

    public function show(Request $request): View|RedirectResponse
    {
        // Super-admins manage every organisation's members; they have no membership.
        if (CommunityAccess::isPlatformAdmin($request->user())) {
            return redirect()->to(url('/dashboard/community'));
        }
        AdminMemberships::ensureFor($request->user());

        $organisation = $this->organisation($request->query('organisation'))
            // Multiple mode without a choice: the organisation they work in (header switcher),
            // else the one they are a member of, if any.
            ?? $this->organisation(OrganisationResolver::context()?->uid)
            ?? CommunityAccess::approvedMembership($request->user())?->organisation;
        $membership = $organisation ? $this->memberships->current($request->user(), $organisation) : null;

        // A member: their membership (the directory is its own page).
        if ($membership?->status === Membership::STATUS_APPROVED) {
            return view('community::membership.home', [
                'membership' => $membership->load(['memberRecord.metas', 'memberRecord.schoolRecords']),
                'organisation' => $organisation,
                // What they completed at the school: ICSE 2003 · ISC 2005 (Science).
                'school' => SchoolLevels::describe($organisation, $membership->memberRecord?->schoolRecords ?? []),
                'memberLabel' => DirectoryFields::conf()->member_label,
                'fields' => collect(DirectoryFields::all())->reject(fn ($f) => $f['id'] === 'name')->values()->all(),
            ]);
        }

        abort_unless((bool) DirectoryFields::conf()->self_registration, 404);

        $rejected = $membership?->status === Membership::STATUS_REJECTED;

        return view('community::applications.apply', [
            'organisation' => $organisation,
            'organisations' => OrganisationResolver::isMultiple() ? OrganisationResolver::choices() : collect(),
            'membership' => $membership?->load('proofs', 'histories'),
            'formId' => ApplicationForm::ensureFor($organisation),
            'memberLabel' => DirectoryFields::conf()->member_label,
            // Prefill: the application on hold, else what the user's account knows (a rejected one starts afresh).
            'formData' => array_merge(
                ($membership && ! $rejected ? (array) $membership->application_data : [
                    'name' => $request->user()->name ?? null,
                    'email' => $request->user()->email ?? null,
                    'phone' => $request->user()->phone ?? null,
                ]),
                ['organisation_uid' => $organisation?->uid]
            ) + ($membership && ! $rejected ? $this->proofValues($membership) : []),
        ]);
    }

    /**
     * The application form (form schema "community-membership-application") posts
     * here: the directory fields (meta fields under "meta"), proof__{id} fields,
     * and organisation_uid. The form engine gets JSON; a plain post is redirected.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless((bool) DirectoryFields::conf()->self_registration, 404);

        $organisation = $this->organisation($request->input('organisation_uid'));
        if (! $organisation) {
            return $this->failed($request, 'Choose an organisation.');
        }

        if (! $request->boolean('accept_terms')) {
            $error = ['accept_terms' => ["Please agree to the organisation's Terms and Privacy policy."]];

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $error], 422)
                : back()->withInput()->withErrors($error);
        }

        $data = [];
        foreach (DirectoryFields::all() as $field) {
            $data[$field['id']] = $request->input("meta.{$field['id']}", $request->input($field['id']));
        }
        $data['school_records'] = (array) $request->input('school_records', []);
        $proofs = [];
        foreach (ProofFields::all() as $proof) {
            $key = ApplicationForm::PROOF_PREFIX.$proof['id'];
            $proofs[$proof['id']] = $request->file($key) ?? $request->input($key);
        }

        try {
            $membership = $this->memberships->apply($request->user(), $organisation, $data, $proofs);
            // Which version of the organisation's terms they agreed to, and when.
            $membership->update(['application_data' => array_merge((array) $membership->application_data, [
                '_terms' => ['version' => OrganisationPolicies::version($organisation), 'accepted_at' => now()->toIso8601String()],
            ])]);
        } catch (\DomainException $e) {
            return $this->failed($request, $e->getMessage());
        } catch (ValidationException $e) {
            // Errors under the form's fields: proof.{id} is proof__{id} there.
            $errors = collect($e->errors())->mapWithKeys(fn ($messages, $key) => [
                str_starts_with($key, 'proof.') ? ApplicationForm::PROOF_PREFIX.substr($key, 6) : $key => $messages,
            ])->all();

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $errors], 422)
                : back()->withInput()->withErrors($errors);
        }

        $url = route('community.membership', OrganisationResolver::isMultiple() ? ['organisation' => $organisation->uid] : []);
        $message = 'Application sent. An administrator will review it.';

        if ($request->expectsJson()) {
            // The form engine follows redirect_url; the page then shows the message.
            session()->flash('success', $message);

            return response()->json(['success' => true, 'message' => $message, 'redirect_url' => $url]);
        }

        return redirect($url)->with('success', $message);
    }

    /** Old address: /community/apply is the Membership page now. */
    public function legacy(Request $request): RedirectResponse
    {
        return redirect()->route('community.membership', $request->query());
    }

    private function failed(Request $request, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 422)
            : back()->withInput()->with('error', $message);
    }

    /** Identifier proofs already sent, for prefilling the form (files can't be prefilled). */
    private function proofValues(Membership $membership): array
    {
        return $membership->proofs
            ->where('kind', ApplicationProof::KIND_IDENTIFIER)
            ->mapWithKeys(fn ($proof) => [ApplicationForm::PROOF_PREFIX.$proof->field => $proof->value])
            ->all();
    }

    /**
     * The member directory: approved members of the member's organisation
     * (name and the public fields only). Members only; others go to Membership.
     */
    public function directoryPage(Request $request): RedirectResponse
    {
        AdminMemberships::ensureFor($request->user());
        $organisation = $this->memberOrganisation($request);
        $table = DirectoryTable::uid();

        // Members: the directory table; others (or before community:seed) Membership.
        return $organisation && $table ? redirect()->to(url("/list/{$table}")) : redirect()->route('community.membership');
    }

    /** The directory table's rows (data_url): approved members of the viewer's organisation. */
    public function directoryData(Request $request): JsonResponse
    {
        $organisation = $this->memberOrganisation($request);
        // Not a member (yet): an empty directory saying why, not an error.
        if (! $organisation) {
            return ApiResponse::success([], 'The directory shows the members of your organisation once your membership is approved.', 200, ['pagination' => ['total' => 0, 'offset' => 0, 'length' => 25]]);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $request->merge(['q' => $request->query('search')]);
        $page = $this->directory($organisation, $request, $offset, $length);
        $public = array_column($this->publicFields(), 'id');

        $rows = $page['rows']->map(function (MemberRecord $member) use ($public, $organisation) {
            $values = $member->metas->pluck('meta_value', 'meta_key');

            return ['uid' => $member->uid, 'name' => $member->name, 'school' => SchoolLevels::describe($organisation, $member->schoolRecords)] + collect($public)->mapWithKeys(fn ($id) => [$id => $values->get($id)])->all();
        })->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $page['total'], 'offset' => $offset, 'length' => $length]]);
    }

    /** Options of the organisation choice (multiple-organisation mode). */
    public function organisationOptions(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => 'Choose…'],
            ...OrganisationResolver::choices()->map(fn ($o) => ['value' => $o->uid, 'label' => $o->name])->values()->all(),
        ]]);
    }

    /** The organisation choice form posts here: Membership for that organisation. */
    public function chooseOrganisation(Request $request): JsonResponse
    {
        $request->validate(['organisation_uid' => ['required', 'string', 'max:26']]);
        $organisation = $this->organisation($request->input('organisation_uid'));
        if (! $organisation) {
            return response()->json(['success' => false, 'message' => 'Choose an organisation.', 'errors' => ['organisation_uid' => ['Choose an organisation.']]], 422);
        }

        return response()->json(['success' => true, 'redirect_url' => route('community.membership', ['organisation' => $organisation->uid])]);
    }

    /** The organisation the user is an approved member of (the one asked for, worked in, or any). */
    private function memberOrganisation(Request $request): ?Organisation
    {
        $organisation = $this->organisation($request->query('organisation'))
            ?? $this->organisation(OrganisationResolver::context()?->uid)
            ?? CommunityAccess::approvedMembership($request->user())?->organisation;

        return $organisation && CommunityAccess::approvedMembership($request->user(), (int) $organisation->id) ? $organisation : null;
    }

    /** Fields members see of each other besides the name (conf directory_public_fields). */
    private function publicFields(): array
    {
        $ids = array_filter(array_map('trim', explode(',', (string) DirectoryFields::conf()->directory_public_fields)));

        return collect(DirectoryFields::all())
            ->whereIn('id', $ids)
            ->reject(fn ($field) => in_array($field['id'], ['email', 'phone'], true))
            ->values()
            ->all();
    }

    /**
     * Approved members of the organisation, searchable by name (q) and filtered by a public field.
     *
     * @return array{rows: Collection, total: int}
     */
    private function directory(Organisation $organisation, Request $request, int $offset = 0, int $length = 25): array
    {
        $search = trim((string) $request->input('q', ''));
        $publicIds = array_column($this->publicFields(), 'id');
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query = MemberRecord::query()
            ->with(['metas', 'schoolRecords'])
            ->where('organisation_id', $organisation->id)
            ->where('status', '!=', 'deleted')
            ->whereIn('id', Membership::query()
                ->where('organisation_id', $organisation->id)
                ->where('status', Membership::STATUS_APPROVED)
                ->whereNotNull('member_record_id')
                ->select('member_record_id'))
            // The table's search: the name, a public field's value (e.g. the section), or a school year (e.g. 2005).
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('member_record_metas')
                    ->whereColumn('member_record_metas.ref_parent', 'member_records.id')
                    ->whereIn('meta_key', $publicIds ?: ['-'])
                    ->where('meta_value', 'like', $like))
                ->when(ctype_digit($search), fn ($y) => $y->orWhereExists(fn ($s) => $s->selectRaw('1')->from('member_school_records')
                    ->whereColumn('member_school_records.member_record_id', 'member_records.id')
                    ->where('member_school_records.year', (int) $search)))))
            // ?level=isc&year=2010: everyone of that batch.
            ->when($request->filled('level') || $request->filled('year'), fn ($q) => $q->whereExists(fn ($s) => $s
                ->selectRaw('1')->from('member_school_records')
                ->whereColumn('member_school_records.member_record_id', 'member_records.id')
                ->when($request->filled('level'), fn ($l) => $l->where('level', (string) $request->query('level')))
                ->when($request->filled('year'), fn ($y) => $y->where('year', (int) $request->query('year')))))
            ->when(in_array($request->query('field'), $publicIds, true) && $request->filled('value'), fn ($q) => $q->whereExists(fn ($m) => $m
                ->selectRaw('1')->from('member_record_metas')
                ->whereColumn('member_record_metas.ref_parent', 'member_records.id')
                ->where('meta_key', $request->query('field'))
                ->where('meta_value', (string) $request->query('value'))))
            ->orderBy('name');

        $total = (clone $query)->count();

        return ['rows' => $query->skip($offset)->take($length)->get(), 'total' => $total];
    }

    private function organisation(?string $uid): ?Organisation
    {
        if (OrganisationResolver::isMultiple()) {
            return $uid ? OrganisationResolver::choices()->firstWhere('uid', $uid) : null;
        }

        return OrganisationResolver::default();
    }
}
