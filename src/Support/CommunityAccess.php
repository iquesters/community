<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Community\Models\Membership;
use Iquesters\Organisation\Models\Organisation;

/**
 * Who may manage which organisations' members (Members, Applications, Bulk upload).
 *
 * The organisation module decides (OrganisationAccess), so every module agrees:
 * - super-admin: every organisation;
 * - org-admin: only the organisations where their role is org-admin. An
 *   org-admin of one school who is a member of another is only a member there.
 */
class CommunityAccess
{
    /** The global role that opens the admin menus (organisation admins hold it). */
    public const ORGANISATION_ADMIN_ROLE = 'admin';

    public const ORGANISATION_ACCESS = 'Iquesters\\Organisation\\Support\\OrganisationAccess';

    public static function isPlatformAdmin(?Authenticatable $user): bool
    {
        return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('super-admin', 'web');
    }

    /** @return int[] ids of the active organisations the user manages */
    public static function manageableOrganisationIds(?Authenticatable $user): array
    {
        if (! $user) {
            return [];
        }

        // The organisation module's rule, when it has one (same answer everywhere).
        if (class_exists(self::ORGANISATION_ACCESS)) {
            return (self::ORGANISATION_ACCESS)::adminOrganisationIds($user);
        }

        if (self::isPlatformAdmin($user)) {
            return Organisation::query()->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        // Without the organisation module only super-admins manage (fail closed).
        return [];
    }

    /** Super-admins always (even before any organisation exists: the pages then explain it). */
    public static function canManage(?Authenticatable $user): bool
    {
        return self::isPlatformAdmin($user) || self::manageableOrganisationIds($user) !== [];
    }

    public static function canManageOrganisation(?Authenticatable $user, ?int $organisationId): bool
    {
        return $organisationId !== null && in_array($organisationId, self::manageableOrganisationIds($user), true);
    }

    /** The user's approved membership (any organisation), if any. */
    public static function approvedMembership(?Authenticatable $user, ?int $organisationId = null): ?Membership
    {
        if (! $user) {
            return null;
        }

        return Membership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', Membership::STATUS_APPROVED)
            ->when($organisationId, fn ($q) => $q->where('organisation_id', $organisationId))
            ->latest('id')
            ->first();
    }
}
