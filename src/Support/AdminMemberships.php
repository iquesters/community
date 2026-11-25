<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipStatusHistory;
use Spatie\Permission\Models\Role;

/**
 * Organisation admins are members of their organisations, without applying:
 * an approved membership (history note "Organisation admin"), a member record
 * from their account, and the member role. They pay fees like other members.
 *
 * Done when they open community (dashboard card, Membership, Directory) and by
 * `community:sync-admins` for everyone at once. Super-admins are not members.
 */
class AdminMemberships
{
    public const NOTE = 'Organisation admin';

    /** @return int number of memberships created */
    public static function ensureFor(?Authenticatable $user): int
    {
        if (! $user || CommunityAccess::isPlatformAdmin($user)) {
            return 0;
        }

        $created = 0;
        foreach (CommunityAccess::manageableOrganisationIds($user) as $organisationId) {
            $created += self::ensure($user, (int) $organisationId) ? 1 : 0;
        }

        return $created;
    }

    /** @return bool true when a membership was created */
    public static function ensure(Authenticatable $user, int $organisationId): bool
    {
        $userId = $user->getAuthIdentifier();

        $created = DB::transaction(function () use ($user, $userId, $organisationId) {
            $approved = Membership::query()
                ->where('organisation_id', $organisationId)
                ->where('user_id', $userId)
                ->where('status', Membership::STATUS_APPROVED)
                ->lockForUpdate()
                ->exists();
            if ($approved) {
                return false;
            }

            // Their record: the one already linked to them in this organisation, else a new one from the account.
            $record = MemberRecord::query()->where('organisation_id', $organisationId)->where('user_id', $userId)->first()
                ?? MemberRecord::create([
                    'organisation_id' => $organisationId,
                    'user_id' => $userId,
                    'name' => (string) ($user->name ?? 'Admin'),
                    'email' => MemberValue::email($user->email ?? null),
                    'phone' => MemberValue::phone($user->phone ?? null),
                    'source' => MemberRecord::SOURCE_ADMIN,
                    'status' => 'active',
                ]);

            $membership = Membership::create([
                'organisation_id' => $organisationId,
                'user_id' => $userId,
                'member_record_id' => $record->id,
                'status' => Membership::STATUS_APPROVED,
                'application_data' => ['name' => $record->name, 'email' => $record->email, 'phone' => $record->phone],
                'decision_note' => self::NOTE,
                'decided_at' => now(),
            ]);
            MembershipStatusHistory::create([
                'membership_id' => $membership->id,
                'from_status' => null,
                'to_status' => Membership::STATUS_APPROVED,
                'note' => self::NOTE,
                'changed_by' => null,
            ]);

            return true;
        });

        // The member role, as for approved applicants.
        $role = trim((string) DirectoryFields::conf()->member_role);
        if ($role !== '' && method_exists($user, 'assignRole') && ! $user->hasRole($role, 'web')) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $created;
    }
}
