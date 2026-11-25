<?php

namespace Iquesters\Community\Services\Membership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipStatusHistory;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MemberMatcher;
use Iquesters\Community\Support\MemberValue;
use Iquesters\Community\Support\ProofFields;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\Support\UserModel;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Organisation\Models\Organisation;
use Spatie\Permission\Models\Role;

/**
 * Applications and their approval.
 *
 * A person applies with their directory details and proof. Admins then approve
 * (linking the user to an existing record of the school's data, or creating a
 * new record from the application), put it on hold, or reject it. Every change
 * is kept in membership_status_histories. Payment comes later, after approval.
 */
class MembershipService
{
    use Loggable;

    public const PROOF_DISK = 'local';

    /** The user's latest membership of the organisation, if any. */
    public function current(Authenticatable $user, Organisation $organisation): ?Membership
    {
        return Membership::query()
            ->where('organisation_id', $organisation->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('id')
            ->first();
    }

    /**
     * Apply, or send an application on hold again with changes.
     *
     * @param  array<string, mixed>  $data  directory field id => value
     * @param  array<string, string|UploadedFile|null>  $proofs  proof field id => value or file
     *
     * @throws ValidationException
     * @throws \DomainException when the user already has an open or approved membership
     */
    public function apply(Authenticatable $user, Organisation $organisation, array $data, array $proofs = []): Membership
    {
        $current = $this->current($user, $organisation);
        if ($current && $current->status === Membership::STATUS_APPROVED) {
            throw new \DomainException('You are already a member.');
        }
        if ($current && $current->status === Membership::STATUS_APPLIED) {
            throw new \DomainException('Your application is waiting for a decision.');
        }

        $clean = $this->validated($data, $proofs, $organisation);
        $userId = (int) $user->getAuthIdentifier();

        $membership = DB::transaction(function () use ($current, $organisation, $clean, $proofs, $userId) {
            if ($current && $current->status === Membership::STATUS_ON_HOLD) {
                // The admin asked for more: the same application goes back to the queue.
                $current->update(['application_data' => $clean, 'updated_by' => $userId]);
                $this->storeProofs($current, $proofs);
                $this->move($current, Membership::STATUS_APPLIED, 'Updated by the applicant', $userId);

                return $current;
            }

            $membership = Membership::create([
                'organisation_id' => $organisation->id,
                'user_id' => $userId,
                'status' => Membership::STATUS_APPLIED,
                'application_data' => $clean,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->history($membership, null, Membership::STATUS_APPLIED, null, $userId);
            $this->storeProofs($membership, $proofs);

            return $membership;
        });

        static::logInfo('Membership application sent', ['membership' => $membership->uid, 'user_id' => $userId]);

        return $membership->fresh();
    }

    /**
     * Existing records that are probably the applicant, for the admin to link.
     *
     * @return array<int, array{record: MemberRecord, keys: string[], linked_to_other_user: bool}>
     */
    public function matches(Membership $membership): array
    {
        return collect(MemberMatcher::find($membership->organisation_id, (array) $membership->application_data))
            ->map(fn ($match) => $match + [
                'linked_to_other_user' => $match['record']->user_id !== null && (int) $match['record']->user_id !== (int) $membership->user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Approve: link the applicant to an existing record ($linkRecordUid), or
     * create a record from the application.
     *
     * @throws \DomainException
     */
    public function approve(Membership $membership, int $adminId, ?string $linkRecordUid = null, ?string $note = null): Membership
    {
        $this->assertCanMove($membership, Membership::STATUS_APPROVED);

        DB::transaction(function () use ($membership, $adminId, $linkRecordUid, $note) {
            $record = $linkRecordUid
                ? $this->linkRecord($membership, $linkRecordUid)
                : $this->createRecord($membership, $adminId);

            $membership->update(['member_record_id' => $record->id]);
            $this->decide($membership, Membership::STATUS_APPROVED, $note, $adminId);
            $this->grantMemberRole($membership);
            $this->joinOrganisation($membership);
        });

        static::logInfo('Membership approved', ['membership' => $membership->uid, 'admin_id' => $adminId, 'linked' => (bool) $linkRecordUid]);

        return $membership->fresh();
    }

    /** Ask the applicant for more; they can change the application and send it again. */
    public function hold(Membership $membership, int $adminId, string $note): Membership
    {
        $this->assertCanMove($membership, Membership::STATUS_ON_HOLD);
        DB::transaction(fn () => $this->decide($membership, Membership::STATUS_ON_HOLD, $note, $adminId));

        return $membership->fresh();
    }

    public function reject(Membership $membership, int $adminId, string $note): Membership
    {
        $this->assertCanMove($membership, Membership::STATUS_REJECTED);
        DB::transaction(fn () => $this->decide($membership, Membership::STATUS_REJECTED, $note, $adminId));

        static::logInfo('Membership rejected', ['membership' => $membership->uid, 'admin_id' => $adminId]);

        return $membership->fresh();
    }

    /** @return array<string, ?string> the directory values, normalised */
    private function validated(array $data, array $proofs, ?Organisation $organisation = null): array
    {
        // What they completed at the school (required), reported with the other fields.
        [$schoolRecords, $schoolErrors] = SchoolLevels::validate($organisation, (array) ($data['school_records'] ?? []));

        $fields = DirectoryFields::all();
        $input = [];
        foreach ($fields as $field) {
            $value = $data[$field['id']] ?? null;
            $input[$field['id']] = is_scalar($value) ? MemberValue::forField($field['id'], (string) $value) : null;
        }

        $rules = [];
        $attributes = ProofFields::attributes();
        foreach ($fields as $field) {
            $rules[$field['id']] = DirectoryFields::rulesFor($field);
            $attributes[$field['id']] = $field['label'];
        }

        $validator = Validator::make($input + ['proof' => $proofs], $rules + ProofFields::rules(), [], $attributes);
        $validator->after(function ($validator) use ($schoolErrors) {
            foreach ($schoolErrors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });
        $validator->validate();

        return $input + ['school_records' => $schoolRecords];
    }

    /**
     * Bug #26: someone uploaded from the school's records signs in with a matching
     * verified email or phone: link the account to that record and approve the
     * membership, without an application. Null when they already have one there.
     */
    public function approveMatched(Authenticatable $user, MemberRecord $record): ?Membership
    {
        $organisation = $record->organisation;
        if (! $organisation || $this->current($user, $organisation)) {
            return null;
        }

        $userId = (int) $user->getAuthIdentifier();
        $membership = DB::transaction(function () use ($record, $organisation, $userId) {
            $fresh = MemberRecord::query()->whereKey($record->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->user_id !== null) {
                return null; // linked meanwhile: never taken over
            }
            $fresh->update(['user_id' => $userId, 'updated_by' => $userId]);

            $membership = Membership::create([
                'organisation_id' => $organisation->id,
                'user_id' => $userId,
                'member_record_id' => $fresh->id,
                'status' => Membership::STATUS_APPROVED,
                'application_data' => ['name' => $fresh->name, 'email' => $fresh->email, 'phone' => $fresh->phone],
                'decided_at' => now(),
                'decision_note' => "Matched the school's records",
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->history($membership, null, Membership::STATUS_APPROVED, "Matched the school's records", $userId);
            $this->grantMemberRole($membership);
            $this->joinOrganisation($membership);

            return $membership;
        });

        if ($membership) {
            static::logInfo('Membership approved from a matching record', ['membership' => $membership->uid, 'user_id' => $userId]);
        }

        return $membership;
    }

    /** A member's proof from their details form (identifiers replaced, new files added). */
    public function saveProofs(Membership $membership, array $proofs): void
    {
        $this->storeProofs($membership, $proofs);
    }

    private function storeProofs(Membership $membership, array $proofs): void
    {
        foreach (ProofFields::all() as $field) {
            $value = $proofs[$field['id']] ?? null;

            if ($field['kind'] === ApplicationProof::KIND_FILE) {
                if (! $value instanceof UploadedFile) {
                    continue;
                }
                $path = $value->store(ApplicationProof::DIRECTORY.'/'.$membership->uid, self::PROOF_DISK);
                $membership->proofs()->create([
                    'field' => $field['id'],
                    'kind' => ApplicationProof::KIND_FILE,
                    'label' => $field['label'],
                    'file_path' => $path,
                    'original_name' => mb_substr($value->getClientOriginalName(), 0, 255),
                    'mime_type' => $value->getMimeType(),
                    'size' => $value->getSize(),
                ]);

                continue;
            }

            $text = is_scalar($value) ? MemberValue::text((string) $value) : null;
            if ($text === null) {
                continue;
            }
            // One value per identifier: a re-sent application replaces it.
            $membership->proofs()->updateOrCreate(
                ['field' => $field['id'], 'kind' => ApplicationProof::KIND_IDENTIFIER],
                ['label' => $field['label'], 'value' => mb_substr($text, 0, 255)]
            );
        }
    }

    private function linkRecord(Membership $membership, string $uid): MemberRecord
    {
        $record = MemberRecord::query()
            ->where('uid', $uid)
            ->where('status', '!=', 'deleted')
            ->lockForUpdate()
            ->first();

        if (! $record || (int) $record->organisation_id !== (int) $membership->organisation_id) {
            throw new \DomainException('That record is not in this organisation.');
        }
        if ($record->user_id !== null && (int) $record->user_id !== (int) $membership->user_id) {
            throw new \DomainException('That record is already linked to another person.');
        }

        // The school's data stays as it is; only the login is linked, and levels the
        // record doesn't have yet are added from the application (e.g. their ISC year).
        $record->update(['user_id' => $membership->user_id, 'updated_by' => auth()->id() ?? 0]);
        $have = $record->schoolRecords()->pluck('level')->all();
        SchoolLevels::attach($record, collect((array) ($membership->application_data['school_records'] ?? []))
            ->reject(fn ($r) => in_array($r['level'] ?? null, $have, true))->values()->all(), auth()->id());

        return $record;
    }

    private function createRecord(Membership $membership, int $adminId): MemberRecord
    {
        $data = (array) $membership->application_data;
        $record = MemberRecord::create([
            'organisation_id' => $membership->organisation_id,
            'user_id' => $membership->user_id,
            'name' => $data['name'] ?? 'Unknown',
            'email' => MemberValue::email($data['email'] ?? null),
            'phone' => MemberValue::phone($data['phone'] ?? null),
            'source' => MemberRecord::SOURCE_REGISTRATION,
            'status' => 'active',
            'created_by' => $adminId,
            'updated_by' => $adminId,
        ]);

        foreach (DirectoryFields::all() as $field) {
            $value = $data[$field['id']] ?? null;
            if ($field['meta'] && $value !== null && $value !== '') {
                $record->setMetaValue($field['id'], (string) $value, $adminId);
            }
        }
        SchoolLevels::attach($record, (array) ($data['school_records'] ?? []), $adminId);

        return $record;
    }

    /** Approved: the user also gets the member role (conf member_role); their other roles stay. */
    private function grantMemberRole(Membership $membership): void
    {
        $role = trim((string) DirectoryFields::conf()->member_role);
        $user = $membership->user_id ? UserModel::query()->find($membership->user_id) : null;

        if ($role === '' || ! $user || ! method_exists($user, 'assignRole')) {
            return;
        }

        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    }

    /** Approved: the user belongs to the organisation (they can open its profile). */
    private function joinOrganisation(Membership $membership): void
    {
        $user = $membership->user_id ? UserModel::query()->find($membership->user_id) : null;

        if (! $user || ! method_exists($user, 'organisations')) {
            return;
        }

        // As a member: approval never makes anyone an organisation admin (an
        // org-admin elsewhere keeps only that). An existing role here is kept.
        $roles = 'Iquesters\\Organisation\\Support\\OrganisationRoles';
        $organisation = $membership->organisation;
        if ($organisation && class_exists($roles) && $roles::available()) {
            $roles::join($user, $organisation);
        } else {
            $user->organisations()->syncWithoutDetaching([$membership->organisation_id]);
        }
    }

    private function assertCanMove(Membership $membership, string $status): void
    {
        if (! $membership->canMoveTo($status)) {
            throw new \DomainException(sprintf(
                "This application is %s: it can't be %s now.",
                strtolower($membership->statusLabel()),
                strtolower(Membership::LABELS[$status])
            ));
        }
    }

    private function decide(Membership $membership, string $status, ?string $note, int $adminId): void
    {
        $membership->update([
            'decision_note' => $note,
            'decided_by' => $adminId,
            'decided_at' => now(),
            'updated_by' => $adminId,
        ]);
        $this->move($membership, $status, $note, $adminId);
    }

    private function move(Membership $membership, string $status, ?string $note, int $userId): void
    {
        $from = $membership->status;
        $membership->update(['status' => $status]);
        $this->history($membership, $from, $status, $note, $userId);
    }

    private function history(Membership $membership, ?string $from, string $to, ?string $note, int $userId): void
    {
        MembershipStatusHistory::create([
            'membership_id' => $membership->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => $userId,
        ]);
    }

    /** The private disk proof files are kept on (never public). */
    public function proofDisk(): FilesystemAdapter
    {
        return Storage::disk(self::PROOF_DISK);
    }
}
