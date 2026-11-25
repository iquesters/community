<?php

namespace Iquesters\Community\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MemberValue;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Foundation\System\Traits\Loggable;

/**
 * Member records (the admins' register) for super-admins and organisation
 * admins, limited to the organisations they manage (the organisation they work
 * in, when chosen). The table community-member-table reads data(); the
 * directory form (community-member-record) creates and edits through here.
 * Foundation's generic entity API is super-admin only, so it isn't used.
 */
class MemberRecordController extends Controller
{
    use Loggable;

    public function data(Request $request): JsonResponse
    {
        $query = MemberRecord::query()->with(['metas', 'schoolRecords', 'organisation:id,name'])
            ->whereIn('organisation_id', $this->organisationIds($request))
            ->where('status', '!=', 'deleted');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('member_record_metas')
                    ->whereColumn('member_record_metas.ref_parent', 'member_records.id')->where('meta_value', 'like', $like))
                ->when(ctype_digit($search), fn ($y) => $y->orWhereExists(fn ($s) => $s->selectRaw('1')->from('member_school_records')
                    ->whereColumn('member_school_records.member_record_id', 'member_records.id')->where('year', (int) $search))));
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();
        $sort = (string) $request->query('sort', '');
        $column = ltrim($sort, '-');
        in_array($column, ['name', 'email', 'source', 'created_at'], true)
            ? $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            : $query->orderByDesc('created_at');

        $rows = $query->skip($offset)->take($length)->get()->map(fn (MemberRecord $r) => $this->row($r))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->find($request, $uid)));
    }

    public function store(Request $request): JsonResponse
    {
        [$input, $organisationId] = $this->validated($request);
        $userId = (int) $request->user()->id;

        $record = DB::transaction(function () use ($input, $organisationId, $userId) {
            $record = MemberRecord::create([
                'organisation_id' => $organisationId,
                'name' => $input['name'],
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'source' => MemberRecord::SOURCE_MANUAL,
                'status' => 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->writeMetas($record, $input, $userId);

            return $record;
        });
        static::logInfo('Member record added', ['record' => $record->uid, 'by' => $userId]);

        return response()->json(['success' => true, 'message' => 'Member record added. Add their school years from the record.', 'data' => $this->row($record->fresh())], 201);
    }

    public function update(Request $request, string $uid): JsonResponse
    {
        $record = $this->find($request, $uid);
        [$input, $organisationId] = $this->validated($request, $record);
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($record, $input, $organisationId, $userId) {
            $record->fill([
                'organisation_id' => $organisationId,
                'name' => $input['name'],
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'updated_by' => $userId,
            ])->save();
            $this->writeMetas($record, $input, $userId);
        });

        return response()->json(['success' => true, 'message' => 'Member record saved.', 'data' => $this->row($record->fresh())]);
    }

    /** @return int[] the organisations listed: those managed, or the one worked in */
    private function organisationIds(Request $request): array
    {
        $ids = CommunityAccess::manageableOrganisationIds($request->user());
        $context = OrganisationResolver::context();

        return $context && in_array((int) $context->id, $ids, true) ? [(int) $context->id] : $ids;
    }

    private function find(Request $request, string $uid): MemberRecord
    {
        $record = MemberRecord::query()->with(['metas', 'schoolRecords', 'organisation'])->where('uid', $uid)->first();
        abort_unless($record && CommunityAccess::canManageOrganisation($request->user(), $record->organisation_id ? (int) $record->organisation_id : null), 404);

        return $record;
    }

    /** @return array{0: array<string, ?string>, 1: int} the directory values and the organisation */
    private function validated(Request $request, ?MemberRecord $record = null): array
    {
        $fields = DirectoryFields::all();
        $input = [];
        $rules = ['organisation_id' => ['required', 'integer', Rule::in(CommunityAccess::manageableOrganisationIds($request->user()))]];
        $attributes = ['organisation_id' => 'organisation'];
        foreach ($fields as $field) {
            $value = $request->input("meta.{$field['id']}", $request->input($field['id']));
            $input[$field['id']] = is_scalar($value) ? MemberValue::forField($field['id'], (string) $value) : null;
            $rules[$field['id']] = DirectoryFields::rulesFor($field);
            $attributes[$field['id']] = $field['label'];
        }
        $organisationId = $request->input('organisation_id', $record?->organisation_id);

        Validator::make($input + ['organisation_id' => $organisationId], $rules, ['organisation_id.in' => "You don't manage this organisation."], $attributes)->validate();

        return [$input, (int) $organisationId];
    }

    private function writeMetas(MemberRecord $record, array $input, int $userId): void
    {
        foreach (DirectoryFields::all() as $field) {
            if (! $field['meta']) {
                continue;
            }
            $value = $input[$field['id']] ?? null;
            $value === null || $value === ''
                ? $record->metas()->where('meta_key', $field['id'])->delete()
                : $record->setMetaValue($field['id'], (string) $value, $userId);
        }
    }

    private function row(MemberRecord $record): array
    {
        $record->loadMissing(['metas', 'schoolRecords', 'organisation']);

        return [
            'uid' => $record->uid,
            'organisation_id' => $record->organisation_id,
            'organisation' => $record->organisation?->name,
            'name' => $record->name,
            'email' => $record->email,
            'phone' => $record->phone,
            'source' => $record->source,
            'school' => SchoolLevels::describe($record->organisation, $record->schoolRecords),
            'has_login' => $record->user_id ? 'Yes' : 'No',
            'created_at' => $record->created_at?->format('Y-m-d H:i'),
        ] + $record->metaValues();
    }
}
