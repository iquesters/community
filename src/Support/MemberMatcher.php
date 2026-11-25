<?php

namespace Iquesters\Community\Support;

use Illuminate\Database\Eloquent\Builder;
use Iquesters\Community\Models\MemberRecord;

/**
 * Finds existing member records that are probably the same person, using the
 * conf's match keys ("email,phone,school+name": a key matches when all its
 * parts match). Used by bulk upload and by applications.
 *
 * "school" compares school records ($data['school_records']): the same level
 * and year, or another exam level within 3 years (one person's Class 10 and
 * Class 12 registers), or the same "left" year. "batch" means "school".
 */
class MemberMatcher
{
    public const SCHOOL = 'school';

    /** An exam record matches another level's record within this many years. */
    private const OTHER_LEVEL_YEARS = 3;

    /** @return array<int, string[]> e.g. [['email'], ['phone'], ['batch', 'name']] */
    public static function keys(): array
    {
        $known = [...collect(DirectoryFields::all())->pluck('id')->all(), self::SCHOOL];

        return collect(explode(',', (string) DirectoryFields::conf()->match_keys))
            ->map(fn ($key) => array_values(array_unique(array_filter(array_map(
                fn ($part) => trim($part) === 'batch' ? self::SCHOOL : trim($part),
                explode('+', $key)
            )))))
            // A key naming a field the directory no longer has is ignored.
            ->filter(fn ($fields) => $fields !== [] && array_diff($fields, $known) === [])
            ->values()
            ->all();
    }

    /**
     * Comparable values of each key the data fills completely, e.g. ['email' => 'a@b.com', 'batch+name' => '1962|ann roy'].
     *
     * @param  array<string, ?string>  $data  normalised field values
     */
    public static function signatures(array $data): array
    {
        $signatures = [];
        foreach (self::keys() as $fields) {
            $plain = array_values(array_diff($fields, [self::SCHOOL]));
            $parts = array_map(fn ($f) => MemberValue::key($data[$f] ?? null), $plain);
            if (in_array('', $parts, true)) {
                continue;
            }
            if (! in_array(self::SCHOOL, $fields, true)) {
                $signatures[implode('+', $fields)] = implode('|', $parts);

                continue;
            }
            // One signature per school record (same level and year = same person in the file).
            foreach (self::schoolRecords($data) as $school) {
                $signatures[implode('+', $fields).':'.$school['level']] = implode('|', [...$parts, $school['year']]);
            }
        }

        return $signatures;
    }

    /**
     * Existing records matching any key, with the keys each one matched on.
     *
     * @param  array<string, ?string>  $data  normalised field values
     * @return array<int, array{record: MemberRecord, keys: string[]}> keyed by record id
     */
    public static function find(?int $organisationId, array $data): array
    {
        $matches = [];

        foreach (self::keys() as $fields) {
            $plain = array_values(array_diff($fields, [self::SCHOOL]));
            $values = array_map(fn ($f) => MemberValue::text($data[$f] ?? null), $plain);
            $schools = self::schoolRecords($data);
            if (in_array(null, $values, true) || (in_array(self::SCHOOL, $fields, true) && $schools === [])) {
                continue;
            }

            $query = MemberRecord::query()->where('status', '!=', 'deleted');
            self::whereOrganisation($query, $organisationId);

            foreach ($plain as $i => $field) {
                self::whereField($query, $field, $values[$i]);
            }
            if (in_array(self::SCHOOL, $fields, true)) {
                self::whereSchool($query, $schools);
            }

            foreach ($query->limit(20)->get() as $record) {
                $matches[$record->id]['record'] = $record;
                $matches[$record->id]['keys'][] = implode('+', $fields);
            }
        }

        return $matches;
    }

    /**
     * Records of the organisation. In single mode, records without one (e.g. added
     * through the directory form, which doesn't set it yet) belong to it too.
     */
    private static function whereOrganisation(Builder $query, ?int $organisationId): void
    {
        if (! $organisationId) {
            $query->whereNull('organisation_id');
        } elseif (OrganisationResolver::isMultiple()) {
            $query->where('organisation_id', $organisationId);
        } else {
            $query->where(fn (Builder $q) => $q->where('organisation_id', $organisationId)->orWhereNull('organisation_id'));
        }
    }

    /** @return array<int, array{level: string, year: int}> */
    private static function schoolRecords(array $data): array
    {
        return collect((array) ($data['school_records'] ?? []))
            ->filter(fn ($r) => is_array($r) && ! empty($r['level']) && is_numeric($r['year'] ?? null))
            ->map(fn ($r) => ['level' => (string) $r['level'], 'year' => (int) $r['year']])
            ->values()->all();
    }

    private static function whereSchool(Builder $query, array $schools): void
    {
        $query->whereExists(function ($q) use ($schools) {
            $q->selectRaw('1')->from('member_school_records as s')
                ->whereColumn('s.member_record_id', 'member_records.id')
                ->where(function ($any) use ($schools) {
                    foreach ($schools as $school) {
                        $any->orWhere(fn ($same) => $same->where('s.level', $school['level'])->where('s.year', $school['year']));
                        if ($school['level'] !== SchoolLevels::LEFT) {
                            // The same person's other exam (Class 10 vs 12) a few years apart.
                            $any->orWhere(fn ($other) => $other->where('s.level', '!=', $school['level'])->where('s.level', '!=', SchoolLevels::LEFT)
                                ->whereBetween('s.year', [$school['year'] - self::OTHER_LEVEL_YEARS, $school['year'] + self::OTHER_LEVEL_YEARS]));
                        }
                    }
                });
        });
    }

    private static function whereField(Builder $query, string $field, string $value): void
    {
        if (in_array($field, DirectoryFields::CORE, true)) {
            // Values are saved normalised; compare case-insensitively.
            $query->whereRaw("LOWER({$field}) = ?", [MemberValue::key(MemberValue::forField($field, $value))]);

            return;
        }

        $query->whereExists(function ($q) use ($field, $value) {
            $q->selectRaw('1')->from('member_record_metas')
                ->whereColumn('member_record_metas.ref_parent', 'member_records.id')
                ->where('member_record_metas.meta_key', $field)
                ->whereRaw('LOWER(member_record_metas.meta_value) = ?', [MemberValue::key($value)]);
        });
    }
}
