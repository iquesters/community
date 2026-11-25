<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\ApplicationTable;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\System\Http\ApiResponse;

/**
 * The approval queue, shown by user-interface's generic table
 * (community-application-table). This controller serves its rows (data_url),
 * a record, the record's HTML sections (proof files, history), the decision
 * form's options, and the decision. Everything is limited to the
 * organisations the admin manages.
 */
class ApplicationAdminController extends Controller
{
    /** Sort fields the table may ask for. */
    private const SORTS = ['created_at' => 'created_at', 'status' => 'status', 'name' => 'application_data->name'];

    public function __construct(private MembershipService $memberships) {}

    /** Old page: the generic table (the ?status= tab kept). */
    public function index(Request $request): RedirectResponse
    {
        $uid = ApplicationTable::uid();
        abort_unless($uid, 404, 'Run community:seed to create the applications table.');

        return redirect()->to(url("/list/{$uid}").($request->query('status') ? '?status='.urlencode((string) $request->query('status')) : ''));
    }

    /** Old link to one application: the table with the record open. */
    public function show(Request $request, string $uid): RedirectResponse
    {
        $membership = $this->find($request, $uid);
        $table = ApplicationTable::uid();
        $form = ApplicationTable::applicationFormUid();
        abort_unless($table && $form, 404);

        return redirect()->to(url("/list/{$table}/view/form/{$form}/data/{$membership->uid}"));
    }

    /** The table's rows (data_url): same parameters and response as the entity API. */
    public function data(Request $request): JsonResponse
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $query = $this->scoped($request)->with(['organisation:id,name', 'user:id,name,email']);

        $status = (string) $request->query('status', '');
        if (in_array($status, Membership::STATUSES, true)) {
            $query->where('status', $status);
        } elseif ($status !== 'all') {
            // No tab, or "Waiting": what needs a decision.
            $query->whereIn('status', Membership::OPEN_STATUSES);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('application_data->name', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like)));
        }

        $total = (clone $query)->count();

        $sorted = false;
        foreach (array_filter(explode(',', (string) $request->query('sort', ''))) as $sort) {
            $column = self::SORTS[ltrim($sort, '-')] ?? null;
            if ($column) {
                $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
                $sorted = true;
            }
        }
        if (! $sorted) {
            $query->latest('id');
        }

        $rows = $query->skip($offset)->take($length)->get()->map(fn (Membership $m) => $this->row($m))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    /** One record ({data_url}/{uid}): the row plus the application as the form shows it. */
    public function record(Request $request, string $uid): JsonResponse
    {
        $membership = $this->find($request, $uid)->load(['organisation:id,name', 'user:id,name,email', 'proofs']);

        return ApiResponse::success($this->row($membership)
            + (array) $membership->application_data
            + $membership->proofs->mapWithKeys(fn (ApplicationProof $proof) => [
                ApplicationForm::PROOF_PREFIX.$proof->field => $proof->isFile() ? $proof->original_name : $proof->value,
            ])->all()
            + [
                'decision_note' => $membership->decision_note,
                // The decision form: one clear match is picked already.
                'link_record_uid' => $this->singleMatchUid($membership),
            ]);
    }

    private function singleMatchUid(Membership $membership): string
    {
        if (! $membership->isOpen()) {
            return '';
        }
        $linkable = collect($this->memberships->matches($membership))->reject(fn ($m) => $m['linked_to_other_user'])->values();

        return $linkable->count() === 1 ? $linkable->first()['record']->uid : '';
    }

    /** Record section: the proof files, opening in the browser. */
    public function proofs(Request $request, string $uid): View
    {
        $membership = $this->find($request, $uid)->load('proofs');

        return view('community::applications.sections.proofs', ['files' => $membership->proofs->filter->isFile()->values()]);
    }

    /** Record section: who changed what, when, and the notes. */
    public function history(Request $request, string $uid): View
    {
        $membership = $this->find($request, $uid)->load(['histories.changedBy', 'memberRecord']);

        return view('community::applications.sections.history', ['membership' => $membership]);
    }

    /**
     * Options of the decision form's "Matching records": records that look like
     * the applicant (not linked to someone else), and "create a new one".
     */
    public function matches(Request $request, string $uid): JsonResponse
    {
        $membership = $this->find($request, $uid);
        $options = [];

        foreach ($membership->isOpen() ? $this->memberships->matches($membership) : [] as $match) {
            if ($match['linked_to_other_user']) {
                continue;
            }
            $record = $match['record'];
            $details = array_filter([$record->email, SchoolLevels::describe($membership->organisation, $record->schoolRecords) ?: null]);
            $options[] = [
                'value' => $record->uid,
                'label' => $record->name.($details ? ' ('.implode(', ', $details).')' : '').': same '.implode(', ', $match['keys']),
            ];
        }
        $options[] = ['value' => '', 'label' => $options ? 'None of these: create a new record' : 'No matching record: approving creates a new one'];

        return response()->json(['success' => true, 'data' => $options]);
    }

    /**
     * The decision form posts here. JSON for the form engine: the record then
     * reloads in place (no redirect); errors show under the fields.
     */
    public function decide(Request $request, string $uid): RedirectResponse|JsonResponse
    {
        $membership = $this->find($request, $uid);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'hold', 'reject'])],
            'link_record_uid' => ['nullable', 'string', 'max:26'],
            // The applicant sees the reason for hold and reject.
            'note' => [Rule::requiredIf(in_array($request->input('decision'), ['hold', 'reject'], true)), 'nullable', 'string', 'max:2000'],
        ], ['note.required' => 'Tell the applicant why (they will see this).']);

        $adminId = (int) $request->user()->id;

        try {
            match ($validated['decision']) {
                'approve' => $this->memberships->approve($membership, $adminId, $validated['link_record_uid'] ?? null, $validated['note'] ?? null),
                'hold' => $this->memberships->hold($membership, $adminId, $validated['note']),
                'reject' => $this->memberships->reject($membership, $adminId, $validated['note']),
            };
        } catch (\DomainException $e) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                : back()->withInput()->with('error', $e->getMessage());
        }

        $message = ['approve' => 'Approved.', 'hold' => 'Put on hold; the applicant can update the application.', 'reject' => 'Rejected.'][$validated['decision']];

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : redirect()->route('community.applications.show', $membership->uid)->with('success', $message);
    }

    private function row(Membership $membership): array
    {
        $data = (array) $membership->application_data;

        return [
            'uid' => $membership->uid,
            'name' => $data['name'] ?? $membership->user?->name,
            'email' => $membership->user?->email,
            // What they completed at the school (e.g. ICSE 2003 · ISC 2005); "batch" for tables saved before.
            'school' => $school = SchoolLevels::describe($membership->organisation, (array) ($data['school_records'] ?? [])),
            'batch' => $school,
            'organisation' => $membership->organisation?->name,
            'status' => $membership->status,
            'status_label' => $membership->statusLabel(),
            'created_at' => $membership->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** Applications of the organisations the admin manages (only the one worked in, if chosen). */
    private function scoped(Request $request): Builder
    {
        $allowed = CommunityAccess::manageableOrganisationIds($request->user());
        $context = OrganisationResolver::context();
        if ($context && in_array((int) $context->id, $allowed, true)) {
            $allowed = [(int) $context->id];
        }

        return Membership::query()->whereIn('organisation_id', $allowed);
    }

    /** Another organisation's application looks like a missing one. */
    private function find(Request $request, string $uid): Membership
    {
        $membership = Membership::query()->where('uid', $uid)->firstOrFail();
        abort_unless(CommunityAccess::canManageOrganisation($request->user(), (int) $membership->organisation_id), 404);

        return $membership;
    }
}
