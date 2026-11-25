<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\ProofFields;
use Iquesters\Organisation\Models\Organisation;

/**
 * The applicant's side: apply for membership, see the application's status,
 * and send it again when an admin put it on hold.
 */
class ApplicationController extends Controller
{
    public function __construct(private MembershipService $memberships) {}

    public function show(Request $request): View
    {
        abort_unless((bool) DirectoryFields::conf()->self_registration, 404);

        $organisation = $this->organisation($request->query('organisation'));
        $membership = $organisation ? $this->memberships->current($request->user(), $organisation) : null;

        return view('community::applications.apply', [
            'organisation' => $organisation,
            'organisations' => OrganisationResolver::isMultiple() ? OrganisationResolver::choices() : collect(),
            'membership' => $membership?->load('proofs', 'histories'),
            'fields' => DirectoryFields::all(),
            'proofFields' => ProofFields::all(),
            'memberLabel' => DirectoryFields::conf()->member_label,
            // Prefill: the application on hold, else what the user's account knows.
            'values' => $membership?->application_data ?? [
                'name' => $request->user()->name ?? null,
                'email' => $request->user()->email ?? null,
                'phone' => $request->user()->phone ?? null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless((bool) DirectoryFields::conf()->self_registration, 404);

        $organisation = $this->organisation($request->input('organisation_uid'));
        if (! $organisation) {
            return back()->withInput()->with('error', 'Choose an organisation.');
        }

        try {
            $this->memberships->apply(
                $request->user(),
                $organisation,
                (array) $request->input('data', []),
                (array) $request->input('proof', []) + (array) $request->file('proof', [])
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('community.apply.show', OrganisationResolver::isMultiple() ? ['organisation' => $organisation->uid] : [])
            ->with('success', 'Application sent. An administrator will review it.');
    }

    private function organisation(?string $uid): ?Organisation
    {
        if (OrganisationResolver::isMultiple()) {
            return $uid ? OrganisationResolver::choices()->firstWhere('uid', $uid) : null;
        }

        return OrganisationResolver::default();
    }
}
