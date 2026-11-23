<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\DirectoryFields;

/**
 * The approval queue: applications with match suggestions, and approve
 * (link to a record or create one), hold or reject.
 */
class ApplicationAdminController extends Controller
{
    public function __construct(private MembershipService $memberships) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'open');
        abort_unless($status === 'open' || $status === 'all' || in_array($status, Membership::STATUSES, true), 404);

        $applications = Membership::query()
            ->with(['organisation:id,name', 'user:id,name,email'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Membership::OPEN_STATUSES))
            ->when(in_array($status, Membership::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $counts = Membership::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('community::applications.index', compact('applications', 'status', 'counts'));
    }

    public function show(string $uid): View
    {
        $membership = $this->find($uid)->load(['organisation', 'user', 'memberRecord', 'proofs', 'histories.changedBy']);

        return view('community::applications.show', [
            'membership' => $membership,
            'fields' => DirectoryFields::all(),
            'matches' => $membership->isOpen() ? $this->memberships->matches($membership) : [],
        ]);
    }

    public function decide(Request $request, string $uid): RedirectResponse
    {
        $membership = $this->find($uid);
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
            return back()->withInput()->with('error', $e->getMessage());
        }

        $messages = ['approve' => 'Approved.', 'hold' => 'Put on hold; the applicant can update the application.', 'reject' => 'Rejected.'];

        return redirect()->route('community.applications.show', $membership->uid)->with('success', $messages[$validated['decision']]);
    }

    private function find(string $uid): Membership
    {
        return Membership::query()->where('uid', $uid)->firstOrFail();
    }
}
