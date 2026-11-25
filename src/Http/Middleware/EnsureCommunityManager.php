<?php

namespace Iquesters\Community\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Iquesters\Community\Support\CommunityAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * The community admin pages (Members, Applications, Bulk upload): super-admins
 * and organisation admins. Which organisations each may touch is checked again
 * in the controllers (CommunityAccess). Auto-registered as `ensure.community.manager`.
 */
class EnsureCommunityManager
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CommunityAccess::canManage($request->user()), 403);

        return $next($request);
    }
}
