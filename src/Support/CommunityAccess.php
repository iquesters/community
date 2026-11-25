<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Iquesters\Community\Models\Membership;
use Iquesters\Organisation\Models\Organisation;

/**
 * Who may manage which organisations' members (Members, Applications, Bulk upload).
 *
 * - super-admin: every organisation;
 * - organisation admin: their organisations only. Track B's TenantAuthorization
 *   decides this from explicit assignments (organisation_role_assignments).
 *   Until that table exists, a user with the "admin" role is admin of the
 *   organisations they belong to (logged as a warning). When the table is
 *   added, the strict rule applies without any code change.
 */
class CommunityAccess
{
    public const ASSIGNMENTS_TABLE = 'organisation_role_assignments';

    public const TENANT_POLICY = 'Iquesters\\Foundation\\Security\\TenantAuthorization';

    public const ORGANISATION_ADMIN_ROLE = 'admin';

    public const ORGANISATION_ACCESS = 'Iquesters\\Organisation\\Support\\OrganisationAccess';

    private static bool $warned = false;

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

        if (class_exists(self::TENANT_POLICY) && Schema::hasTable(self::ASSIGNMENTS_TABLE)) {
            return array_map('intval', app(self::TENANT_POLICY)->adminOrganisationIds($user));
        }

        // Interim rule until Track C adds explicit organisation admin assignments.
        if (! method_exists($user, 'hasRole') || ! $user->hasRole(self::ORGANISATION_ADMIN_ROLE, 'web') || ! method_exists($user, 'organisations')) {
            return [];
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning('community.organisation_admin_fallback', [
                'reason' => self::ASSIGNMENTS_TABLE.' is missing: "admin" members of an organisation manage it',
            ]);
        }

        return $user->organisations()->where('organisations.status', 'active')->pluck('organisations.id')->map(fn ($id) => (int) $id)->all();
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
