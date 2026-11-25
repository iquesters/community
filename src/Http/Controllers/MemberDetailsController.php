<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\MemberSchoolRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipStatusHistory;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MemberValue;
use Iquesters\Community\Support\ProofFields;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * A member edits their own details (the directory fields and what they
 * completed at the school) on their member record. Saved straight away; each
 * change is noted in the membership's history for the admins.
 */
class MemberDetailsController extends Controller
{
    use Loggable;

    public function edit(Request $request, string $uid): View
    {
        $membership = $this->membership($request, $uid);
        $record = $this->record($membership);

        return view('community::membership.details', [
            'membership' => $membership,
            'organisation' => $membership->organisation,
            'formId' => ApplicationForm::ensureDetailsFor($membership->organisation),
            'formData' => array_merge(
                ['name' => $record->name, 'email' => $record->email, 'phone' => $record->phone],
                $record->metaValues(),
                ['school_records' => SchoolLevels::rows($record->schoolRecords)],
                // The proof identifiers they gave (e.g. registration number).
                $membership->proofs()->where('kind', ApplicationProof::KIND_IDENTIFIER)->get()
                    ->mapWithKeys(fn ($p) => [ApplicationForm::PROOF_PREFIX.$p->field => $p->value])->all(),
            ),
            'sentFiles' => $membership->proofs()->where('kind', ApplicationProof::KIND_FILE)->get(),
        ]);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $membership = $this->membership($request, $uid);
        $record = $this->record($membership);

        $fields = DirectoryFields::all();
        $input = [];
        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            $value = $request->input("meta.{$field['id']}", $request->input($field['id']));
            $input[$field['id']] = is_scalar($value) ? MemberValue::forField($field['id'], (string) $value) : null;
            $rules[$field['id']] = DirectoryFields::rulesFor($field);
            $attributes[$field['id']] = $field['label'];
        }
        [$schoolRows, $schoolErrors] = SchoolLevels::validate($membership->organisation, (array) $request->input('school_records', []));

        // Proof, as in the application: a file already sent isn't asked for again.
        $proofs = [];
        $haveFiles = $membership->proofs()->where('kind', ApplicationProof::KIND_FILE)->pluck('field')->all();
        foreach (ProofFields::all() as $proof) {
            $key = ApplicationForm::PROOF_PREFIX.$proof['id'];
            $proofs[$proof['id']] = $request->file($key) ?? $request->input($key);
        }
        $proofRules = collect(ProofFields::rules())->map(function ($r, $key) use ($haveFiles) {
            return in_array(substr($key, 6), $haveFiles, true) ? array_map(fn ($x) => $x === 'required' ? 'nullable' : $x, $r) : $r;
        })->all();

        $validator = Validator::make($input + ['proof' => $proofs], $rules + $proofRules, [], $attributes + ProofFields::attributes());
        $validator->after(function ($validator) use ($schoolErrors) {
            foreach ($schoolErrors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
        if ($validator->fails()) {
            // proof.{id} is proof__{id} in the form.
            $errors = collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => [
                str_starts_with($key, 'proof.') ? ApplicationForm::PROOF_PREFIX.substr($key, 6) : $key => $messages,
            ])->all();

            return response()->json(['success' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $errors], 422);
        }

        $userId = (int) $request->user()->id;
        DB::transaction(function () use ($record, $membership, $fields, $input, $schoolRows, $userId, $proofs) {
            app(MembershipService::class)->saveProofs($membership, $proofs);

            $record->fill([
                'name' => $input['name'] ?? $record->name,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'updated_by' => $userId,
            ])->save();

            foreach ($fields as $field) {
                if ($field['meta']) {
                    $value = $input[$field['id']] ?? null;
                    $value === null || $value === ''
                        ? $record->metas()->where('meta_key', $field['id'])->delete()
                        : $record->setMetaValue($field['id'], (string) $value, $userId);
                }
            }

            MemberSchoolRecord::query()->where('member_record_id', $record->id)
                ->whereNotIn('level', array_column($schoolRows, 'level') ?: ['-'])->delete();
            SchoolLevels::attach($record, $schoolRows, $userId);

            MembershipStatusHistory::create([
                'membership_id' => $membership->id,
                'from_status' => $membership->status,
                'to_status' => $membership->status,
                'note' => 'Details updated by the member',
                'changed_by' => $userId,
            ]);
        });
        static::logInfo('Member updated their details', ['membership' => $membership->uid, 'user_id' => $userId]);

        return response()->json([
            'success' => true,
            'message' => 'Your details are saved.',
            'redirect_url' => route('community.membership', ['organisation' => $membership->organisation->uid]),
        ]);
    }

    /** The user's own approved membership. */
    private function membership(Request $request, string $uid): Membership
    {
        $membership = Membership::query()->with('organisation')->where('uid', $uid)->firstOrFail();
        abort_unless(
            (int) $membership->user_id === (int) $request->user()->id && $membership->status === Membership::STATUS_APPROVED,
            404
        );

        return $membership;
    }

    /** Their member record; made from the account when the membership has none yet. */
    private function record(Membership $membership): MemberRecord
    {
        $record = $membership->memberRecord;
        if (! $record) {
            $user = $membership->user;
            $record = MemberRecord::create([
                'organisation_id' => $membership->organisation_id,
                'user_id' => $membership->user_id,
                'name' => $user?->name ?? 'Member',
                'email' => MemberValue::email($user?->email),
                'source' => MemberRecord::SOURCE_REGISTRATION,
                'status' => 'active',
                'created_by' => (int) $membership->user_id,
                'updated_by' => (int) $membership->user_id,
            ]);
            $membership->update(['member_record_id' => $record->id]);
        }

        return $record->load(['metas', 'schoolRecords']);
    }
}
