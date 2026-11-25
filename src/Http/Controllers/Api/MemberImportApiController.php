<?php

namespace Iquesters\Community\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Iquesters\Community\Services\Import\MemberImportService;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MemberMatcher;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Foundation\System\Http\ApiResponse;

/**
 * /api/community/v1: the directory's fields and the bulk upload, for app
 * screens and other clients. Same rules as the web pages.
 *
 * Responses use foundation's ApiResponse envelope (data in response_schema.data),
 * which the module API's ResponseMiddleware expects.
 */
class MemberImportApiController extends Controller
{
    public function __construct(private MemberImportService $imports) {}

    public function fields(): JsonResponse
    {
        return ApiResponse::success([
            'fields' => collect(DirectoryFields::all())->map(fn ($f) => collect($f)->except('field'))->values(),
            'organisation_mode' => DirectoryFields::conf()->organisation_mode,
            'match_keys' => array_map(fn (array $key) => implode('+', $key), MemberMatcher::keys()),
        ]);
    }

    /** Options for the directory form's organisation select. */
    public function organisations(Request $request): JsonResponse
    {
        return ApiResponse::success(OrganisationResolver::options(CommunityAccess::manageableOrganisationIds($request->user())));
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'organisation_uid' => ['nullable', 'string', 'max:26'],
        ]);

        try {
            $organisation = OrganisationResolver::resolve($request->input('organisation_uid'), CommunityAccess::manageableOrganisationIds($request->user()));
            $preview = $this->imports->preview($request->file('file')->getRealPath(), $organisation, (int) $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success($preview, 'Preview ready');
    }

    public function import(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'on_duplicate' => ['sometimes', Rule::in([MemberImportService::ON_DUPLICATE_SKIP, MemberImportService::ON_DUPLICATE_UPDATE])],
        ]);

        $stored = $this->imports->stored($token, (int) $request->user()->id);
        if ($stored && ! CommunityAccess::canManageOrganisation($request->user(), $stored['organisation']['id'] ?? null)) {
            return ApiResponse::error("You don't manage this organisation.", 403);
        }

        try {
            $result = $this->imports->import($token, (int) $request->user()->id, $validated['on_duplicate'] ?? MemberImportService::ON_DUPLICATE_SKIP);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 410);
        }

        return ApiResponse::success($result, 'Import done');
    }
}
