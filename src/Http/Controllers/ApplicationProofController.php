<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Foundation\Http\Middleware\EnsureAdmin;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof files are private: only the applicant and platform admins may download them.
 */
class ApplicationProofController extends Controller
{
    public function __construct(private MembershipService $memberships) {}

    public function download(Request $request, string $uid): StreamedResponse
    {
        $proof = ApplicationProof::query()->where('uid', $uid)->where('kind', ApplicationProof::KIND_FILE)->with('membership')->firstOrFail();
        $user = $request->user();

        $isOwner = (int) $proof->membership->user_id === (int) $user->getAuthIdentifier();
        $isAdmin = method_exists($user, 'hasRole') && $user->hasRole(EnsureAdmin::roles(), 'web');
        // Same answer as a missing file, so others can't tell a proof exists.
        abort_unless($isOwner || $isAdmin, 404);

        $disk = $this->memberships->proofDisk();
        abort_unless($proof->file_path && $disk->exists($proof->file_path), 404);

        return $disk->download($proof->file_path, $proof->original_name ?: basename($proof->file_path));
    }
}
