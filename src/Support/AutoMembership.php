<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * Bug #26: after sign-in, people uploaded from a school's records become members
 * automatically when their account's VERIFIED email or phone matches exactly one
 * record of that organisation (not linked to anyone). Anything less certain
 * (several matches, a name match, an unverified email or phone) waits for an
 * application and an admin, as before. Setting: auto_approve_matches.
 */
class AutoMembership
{
    use Loggable;

    /** @return Membership[] the memberships made */
    public static function check(?Authenticatable $user): array
    {
        if (! $user || ! (bool) DirectoryFields::conf()->auto_approve_matches || ! Schema::hasTable('member_records')) {
            return [];
        }

        $email = ! empty($user->email_verified_at) ? MemberValue::email($user->email ?? null) : null;
        $phone = ! empty($user->phone_verified_at) ? MemberValue::phone($user->phone ?? null) : null;
        if (! $email && ! $phone) {
            return [];
        }

        $candidates = MemberRecord::query()
            ->with('organisation')
            ->whereNull('user_id')
            ->whereNotNull('organisation_id')
            ->where('status', '!=', 'deleted')
            ->where(fn ($q) => $q
                ->when($email, fn ($e) => $e->orWhereRaw('LOWER(email) = ?', [$email]))
                ->when($phone, fn ($p) => $p->orWhere('phone', $phone)))
            ->get();

        $made = [];
        foreach ($candidates->groupBy('organisation_id') as $records) {
            if ($records->count() !== 1) {
                continue; // several possible records: an admin decides
            }
            try {
                if ($membership = app(MembershipService::class)->approveMatched($user, $records->first())) {
                    $made[] = $membership;
                }
            } catch (\Throwable $e) {
                // Never block signing in.
                static::logError('Automatic membership failed', ['user_id' => $user->getAuthIdentifier(), 'error' => $e->getMessage()]);
            }
        }

        return $made;
    }
}
