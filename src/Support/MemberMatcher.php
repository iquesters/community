<?php

namespace Iquesters\Community\Support;

use Illuminate\Database\Eloquent\Builder;
use Iquesters\Community\Models\MemberRecord;

/**
 * Finds existing member records that are probably the same person, using the
 * conf's match keys ("email,phone,batch+name": a key matches when all its
 * fields are equal). Used by bulk upload now, and registration later.
 */
class MemberMatcher
{
    /** @return array<int, string[]> e.g. [['email'], ['phone'], ['batch', 'name']] */
    public static function keys(): array
    {
        $known = collect(DirectoryFields::all())->pluck('id')->all();

        return collect(explode(',', (string) DirectoryFields::conf()->match_keys))
            ->map(fn ($key) => array_values(array_filter(array_map('trim', explode('+', $key)))))
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
            $parts = array_map(fn ($f) => MemberValue::key($data[$f] ?? null), $fields);
            if (! in_array('', $parts, true)) {
                $signatures[implode('+', $fields)] = implode('|', $parts);
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
            $values = array_map(fn ($f) => MemberValue::text($data[$f] ?? null), $fields);
            if (in_array(null, $values, true)) {
                continue;
            }

            $query = MemberRecord::query()->where('status', '!=', 'deleted');
            self::whereOrganisation($query, $organisationId);

            foreach ($fields as $i => $field) {
                self::whereField($query, $field, $values[$i]);
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
