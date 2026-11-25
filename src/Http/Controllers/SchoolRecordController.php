<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\MemberSchoolRecord;
use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\SchoolForms;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Organisation\Models\Organisation;

/**
 * School records and levels, for the admins of the member's organisation:
 * - a member's records (Members → record section, and its edit page);
 * - an organisation's levels (Community → School levels).
 */
class SchoolRecordController extends Controller
{
    use Loggable;

    /** Record section of the Members table: what they completed at the school, and a link to edit it. */
    public function section(Request $request, string $uid): View
    {
        $record = $this->record($request, $uid);

        return view('community::members.school-section', [
            'record' => $record,
            'summary' => SchoolLevels::describe($record->organisation, $record->schoolRecords),
        ]);
    }

    public function edit(Request $request, string $uid): View
    {
        $record = $this->record($request, $uid);
        $slug = SchoolForms::recordsSlug($record->organisation);
        if (! DB::table('form_schemas')->where('slug', $slug)->exists()) {
            SchoolForms::syncRecords($record->organisation);
        }

        return view('community::members.school-edit', [
            'record' => $record,
            'formId' => $slug,
            'formData' => ['uid' => $record->uid, 'school_records' => SchoolLevels::rows($record->schoolRecords)],
        ]);
    }

    /** Replace the member's school records with the form's rows. */
    public function update(Request $request, string $uid): JsonResponse
    {
        $record = $this->record($request, $uid);
        [$rows, $errors] = SchoolLevels::validate($record->organisation, (array) $request->input('school_records', []), false);
        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'Please check the highlighted rows.', 'errors' => array_map(fn ($m) => [$m], $errors)], 422);
        }

        DB::transaction(function () use ($record, $rows, $request) {
            MemberSchoolRecord::query()->where('member_record_id', $record->id)
                ->whereNotIn('level', array_column($rows, 'level') ?: ['-'])->delete();
            SchoolLevels::attach($record, $rows, $request->user()->id);
        });
        static::logInfo('School records saved', ['record' => $record->uid, 'by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => 'School records saved.']);
    }

    /** Sidebar "School levels": the organisation worked in, else the first one managed. */
    public function levelsIndex(Request $request): RedirectResponse
    {
        $ids = CommunityAccess::manageableOrganisationIds($request->user());
        $context = OrganisationResolver::context();
        $organisation = $context && in_array((int) $context->id, $ids, true)
            ? $context
            : Organisation::query()->whereIn('id', $ids)->orderBy('name')->first();
        abort_unless($organisation, 404, 'Create an organisation first.');

        return redirect()->route('community.school-levels.edit', $organisation->uid);
    }

    public function levelsEdit(Request $request, string $uid): View
    {
        $organisation = $this->organisation($request, $uid);

        return view('community::members.school-levels', [
            'organisation' => $organisation,
            'organisations' => Organisation::query()->whereIn('id', CommunityAccess::manageableOrganisationIds($request->user()))->orderBy('name')->get(['uid', 'name']),
            'formId' => SchoolForms::LEVELS,
            'formData' => ['uid' => $organisation->uid, 'levels' => SchoolLevels::for($organisation)],
            'ownLevels' => $organisation->getMetaValue(SchoolLevels::META) !== null,
        ]);
    }

    public function levelsUpdate(Request $request, string $uid): JsonResponse
    {
        $organisation = $this->organisation($request, $uid);
        $request->validate([
            'levels' => ['required', 'array', 'min:1', 'max:10'],
            'levels.*.key' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_ -]+$/', 'not_in:left,Left,LEFT'],
            'levels.*.label' => ['required', 'string', 'max:64'],
            'levels.*.class' => ['required', 'integer', 'between:1,12'],
            'levels.*.from_year' => ['nullable', 'integer', 'between:1800,2100'],
        ], [], ['levels.*.key' => 'short code', 'levels.*.label' => 'name', 'levels.*.class' => 'class', 'levels.*.from_year' => 'first year']);

        $levels = collect($request->input('levels'))->map(fn ($l) => [
            'key' => SchoolLevels::key((string) $l['key']),
            'label' => trim((string) $l['label']),
            'class' => (int) $l['class'],
            'from_year' => isset($l['from_year']) && $l['from_year'] !== '' ? (int) $l['from_year'] : null,
        ]);
        if ($levels->pluck('key')->duplicates()->isNotEmpty()) {
            return response()->json(['success' => false, 'message' => 'Each short code can be used once.', 'errors' => ['levels' => ['Each short code can be used once.']]], 422);
        }

        // A level people already have records of can't disappear (their records would lose their name).
        $used = MemberSchoolRecord::query()
            ->whereIn('member_record_id', MemberRecord::query()->where('organisation_id', $organisation->id)->select('id'))
            ->where('level', '!=', SchoolLevels::LEFT)->distinct()->pluck('level');
        $missing = $used->diff($levels->pluck('key'));
        if ($missing->isNotEmpty()) {
            $message = 'These levels have members\' records, so keep them: '.$missing->implode(', ').'.';

            return response()->json(['success' => false, 'message' => $message, 'errors' => ['levels' => [$message]]], 422);
        }

        $organisation->setMetaValue(SchoolLevels::META, json_encode($levels->sortBy('class')->values()->all(), JSON_UNESCAPED_UNICODE));
        ApplicationForm::syncOne($organisation);
        SchoolForms::syncRecords($organisation);
        static::logInfo('School levels saved', ['organisation' => $organisation->uid, 'by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => 'Levels saved. The application form now uses them.']);
    }

    private function record(Request $request, string $uid): MemberRecord
    {
        $record = MemberRecord::query()->with(['organisation', 'schoolRecords'])->where('uid', $uid)->firstOrFail();
        abort_unless(CommunityAccess::canManageOrganisation($request->user(), $record->organisation_id ? (int) $record->organisation_id : null), 404);

        return $record;
    }

    private function organisation(Request $request, string $uid): Organisation
    {
        $organisation = Organisation::query()->where('uid', $uid)->firstOrFail();
        abort_unless(CommunityAccess::canManageOrganisation($request->user(), (int) $organisation->id), 404);

        return $organisation;
    }
}
