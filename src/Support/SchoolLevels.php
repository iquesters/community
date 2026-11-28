<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Collection;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\MemberSchoolRecord;
use Iquesters\Organisation\Models\Organisation;

/**
 * A school's levels (exams people pass out with, e.g. ICSE / ISC, CBSE 10 /
 * 12) and a person's school records: one per level (level + passing year,
 * stream), or "left" (the class they left after, and the year) for alumni who
 * didn't finish an exam there.
 *
 * Levels are set per organisation (organisation meta community_school_levels,
 * Community → School levels), else the conf's school_levels. Each level: key,
 * label, class (10, 12…), from_year (first year it existed, e.g. ISC 2005).
 */
class SchoolLevels
{
    public const LEFT = 'left';

    public const META = 'community_school_levels';

    /** @return array<int, array{key: string, label: string, class: ?int, from_year: ?int}> */
    public static function for(?Organisation $organisation): array
    {
        $own = $organisation ? json_decode((string) $organisation->getMetaValue(self::META), true) : null;
        $levels = is_array($own) && $own !== [] ? $own : (array) DirectoryFields::conf()->school_levels;

        return collect($levels)
            ->filter(fn ($l) => is_array($l) && trim((string) ($l['key'] ?? '')) !== '' && strtolower((string) $l['key']) !== self::LEFT)
            ->map(fn ($l) => [
                'key' => self::key((string) $l['key']),
                'label' => trim((string) ($l['label'] ?? $l['key'])),
                'class' => isset($l['class']) && $l['class'] !== '' ? (int) $l['class'] : null,
                'from_year' => isset($l['from_year']) && $l['from_year'] !== '' ? (int) $l['from_year'] : null,
            ])
            ->unique('key')->values()->all();
    }

    /** Select options: the levels, then "Left before finishing". */
    public static function options(?Organisation $organisation): array
    {
        return [
            ...array_map(fn ($l) => ['value' => $l['key'], 'label' => $l['label']], self::for($organisation)),
            ['value' => self::LEFT, 'label' => 'Left before finishing (give the class)'],
        ];
    }

    /** A level typed by a person or a CSV cell ("ISC", "isc", "ISC (Class 12)", "Left") → its key, or null. */
    public static function resolve(?Organisation $organisation, ?string $value): ?string
    {
        $wanted = self::key((string) $value);
        if ($wanted === '') {
            return null;
        }
        if ($wanted === self::LEFT || str_starts_with($wanted, 'left')) {
            return self::LEFT;
        }
        foreach (self::for($organisation) as $level) {
            if ($wanted === $level['key'] || $wanted === self::key($level['label'])) {
                return $level['key'];
            }
        }

        return null;
    }

    /** The years one can have passed out or left: from the organisation's founding (its profile's "Founded (year)", else 1900) to this year. */
    public static function yearRange(?Organisation $organisation): array
    {
        $now = (int) now()->year;
        $founded = $organisation ? (int) $organisation->getMetaValue('founded_year') : 0;

        return [$founded >= 1800 && $founded <= $now ? $founded : 1900, $now];
    }

    /**
     * Check and normalise school records (application or upload).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array{level: string, year: int, class_left: ?int, stream: ?string}>, 1: array<string, string>} rows and errors keyed "school_records.{i}.{field}"
     */
    public static function validate(?Organisation $organisation, array $rows, bool $required = true): array
    {
        $levels = collect(self::for($organisation))->keyBy('key');
        $clean = [];
        $errors = [];
        // No future years; none before the school was founded.
        [$minYear, $maxYear] = self::yearRange($organisation);

        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            if (trim(implode('', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $row))) === '') {
                continue; // an empty row of the repeater
            }
            $at = "school_records.{$i}";
            $level = self::resolve($organisation, isset($row['level']) ? (string) $row['level'] : null);
            $year = isset($row['year']) && is_numeric($row['year']) ? (int) $row['year'] : null;
            $classLeft = isset($row['class_left']) && is_numeric($row['class_left']) ? (int) $row['class_left'] : null;
            $stream = MemberValue::text(isset($row['stream']) ? (string) $row['stream'] : null);

            if (! $level) {
                $errors["{$at}.level"] = 'Choose what you completed at this school.';

                continue;
            }
            if (! $year || $year < $minYear || $year > $maxYear) {
                $errors["{$at}.year"] = $year > $maxYear
                    ? "The year can't be in the future (up to {$maxYear})."
                    : "Enter the year ({$minYear}–{$maxYear}).";

                continue;
            }
            if ($level === self::LEFT) {
                if (! $classLeft || $classLeft < 1 || $classLeft > 12) {
                    $errors["{$at}.class_left"] = 'Enter the class you left after (1–12).';

                    continue;
                }
            } else {
                $classLeft = null;
                $from = $levels[$level]['from_year'] ?? null;
                if ($from && $year < $from) {
                    $errors["{$at}.year"] = "{$levels[$level]['label']} started in {$from}: the year can't be earlier.";

                    continue;
                }
            }

            $clean[$level] = ['level' => $level, 'year' => $year, 'class_left' => $classLeft, 'stream' => $stream ? mb_substr($stream, 0, 64) : null];
        }

        if (count($clean) < count(array_filter($rows, fn ($r) => is_array($r) && isset($r['level']) && $r['level'] !== '')) && $errors === []) {
            $errors['school_records'] = 'Each level can be given once.';
        }

        // A higher class can't be passed before a lower one (e.g. ISC before ICSE).
        $exams = collect($clean)->reject(fn ($r) => $r['level'] === self::LEFT)
            ->map(fn ($r) => $r + ['class' => $levels[$r['level']]['class'] ?? null])
            ->filter(fn ($r) => $r['class'] !== null)->sortBy('class')->values();
        for ($i = 1; $i < $exams->count(); $i++) {
            if ($exams[$i]['class'] > $exams[$i - 1]['class'] && $exams[$i]['year'] <= $exams[$i - 1]['year']) {
                $errors['school_records'] = "{$levels[$exams[$i]['level']]['label']} must be after {$levels[$exams[$i - 1]['level']]['label']}.";
            }
        }

        if ($required && $clean === [] && $errors === []) {
            $errors['school_records'] = 'Add what you completed at this school (e.g. ICSE and the year), or the class you left after.';
        }

        return [array_values($clean), $errors];
    }

    /** Add or update the records on a member record (an existing level's year is updated). */
    public static function attach(MemberRecord $record, array $rows, ?int $userId = null): void
    {
        foreach ($rows as $row) {
            $school = MemberSchoolRecord::query()->firstOrNew(['member_record_id' => $record->id, 'level' => $row['level']]);
            $school->created_by ??= $userId;
            $school->fill([
                'year' => $row['year'],
                'class_left' => $row['class_left'] ?? null,
                // A blank stream keeps the one on record.
                'stream' => ($row['stream'] ?? null) ?: $school->stream,
                'updated_by' => $userId,
            ])->save();
        }
    }

    /** "ICSE 2003 · ISC 2005" or "Left after Class 8, 1998". */
    public static function describe(?Organisation $organisation, iterable $records): string
    {
        $labels = collect(self::for($organisation))->pluck('label', 'key');

        return collect($records)
            ->map(fn ($r) => is_array($r) ? (object) $r : $r)
            ->sortBy(fn ($r) => [$r->level === self::LEFT ? 1 : 0, $r->year])
            ->map(fn ($r) => $r->level === self::LEFT
                ? "Left after Class {$r->class_left}, {$r->year}"
                : self::shortLabel($labels[$r->level] ?? strtoupper($r->level)).' '.$r->year.($r->stream ? " ({$r->stream})" : ''))
            ->implode(' · ');
    }

    /** Records as form rows (repeater). */
    public static function rows(Collection $records): array
    {
        return $records->map(fn (MemberSchoolRecord $r) => [
            'id' => $r->uid,
            'level' => $r->level,
            'year' => $r->year,
            'class_left' => $r->class_left,
            'stream' => $r->stream,
        ])->values()->all();
    }

    /** "ISC (Class 12)" → "ISC". */
    public static function shortLabel(string $label): string
    {
        return trim((string) preg_replace('/\s*\(.*\)\s*$/', '', $label)) ?: $label;
    }

    public static function key(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($value))), '_');
    }
}
