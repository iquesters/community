<?php

namespace Iquesters\Community\Services\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\MemberMatcher;
use Iquesters\Community\Support\MemberValue;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Organisation\Models\Organisation;

/**
 * Bulk upload of member records from a CSV file, in two steps:
 *
 * 1. preview(): read and check every row (required fields, formats, options),
 *    find duplicates (inside the file and against existing records, using the
 *    match keys) and keep the result for 30 minutes under a token;
 * 2. import(): create the valid rows. A duplicate of an existing record is
 *    skipped, or updates it when asked (only when exactly one record matches).
 */
class MemberImportService
{
    public const STATUS_VALID = 'valid';

    public const STATUS_ERROR = 'error';

    /** Same person as an earlier row of the file. */
    public const STATUS_DUPLICATE_IN_FILE = 'duplicate_in_file';

    /** Matches an existing record. */
    public const STATUS_EXISTING = 'existing';

    public const ON_DUPLICATE_SKIP = 'skip';

    public const ON_DUPLICATE_UPDATE = 'update';

    /**
     * School record columns (not directory fields): what the person completed at
     * the school. "Batch" (older files) is the year of the organisation's first level.
     */
    public const SCHOOL_COLUMNS = [
        ['id' => 'school_level', 'label' => 'Level'],
        ['id' => 'school_year', 'label' => 'Year'],
        ['id' => 'school_class_left', 'label' => 'Class left'],
        ['id' => 'school_stream', 'label' => 'Stream'],
    ];

    private const CACHE_PREFIX = 'community.member-import.';

    private const TTL_MINUTES = 30;

    /**
     * @return array{token: string, organisation: ?array, columns: array, unknown_columns: string[], missing_columns: string[], rows: array, summary: array}
     *
     * @throws \InvalidArgumentException for an unreadable file, a header without required columns, or too many rows
     */
    public function preview(string $path, ?Organisation $organisation, int $userId): array
    {
        $fields = DirectoryFields::all();
        [$header, $lines] = $this->read($path);

        $max = (int) DirectoryFields::conf()->import_max_rows ?: 5000;
        if (count($lines) > $max) {
            throw new \InvalidArgumentException('The file has '.count($lines)." rows; at most {$max} can be uploaded at once.");
        }

        [$columns, $unknown] = $this->mapColumns($header, [
            ...$fields,
            ...self::SCHOOL_COLUMNS,
            ['id' => 'school_year', 'label' => 'Batch (passing year)'],
            ['id' => 'school_year', 'label' => 'batch'],
        ]);
        $missing = collect($fields)->where('required', true)->pluck('id')->diff(array_values($columns))->values()->all();
        if ($missing !== []) {
            $labels = collect($fields)->whereIn('id', $missing)->pluck('label')->implode(', ');
            throw new \InvalidArgumentException("Required columns are missing: {$labels}. Download the template to see the columns.");
        }

        $rows = [];
        $seen = [];
        $organisationId = $organisation?->id;

        foreach ($lines as $index => $cells) {
            $line = $index + 2; // 1-based, after the header
            $data = [];
            foreach ($columns as $position => $fieldId) {
                $data[$fieldId] = MemberValue::forField($fieldId, $cells[$position] ?? null);
            }

            $school = array_intersect_key($data, array_flip(array_column(self::SCHOOL_COLUMNS, 'id')));
            $data = array_diff_key($data, $school);
            $errors = $this->validate($data, $fields);
            [$data['school_records'], $schoolErrors] = $this->schoolRecords($organisation, $school);
            $errors = [...$errors, ...$schoolErrors];
            $data['school'] = SchoolLevels::describe($organisation, $data['school_records']);
            $status = $errors ? self::STATUS_ERROR : self::STATUS_VALID;
            $duplicateOf = null;
            $matches = [];

            if (! $errors) {
                foreach (MemberMatcher::signatures($data) as $key => $signature) {
                    if (isset($seen[$key][$signature])) {
                        $status = self::STATUS_DUPLICATE_IN_FILE;
                        $duplicateOf = $seen[$key][$signature];
                        break;
                    }
                }

                if ($status === self::STATUS_VALID) {
                    foreach (MemberMatcher::signatures($data) as $key => $signature) {
                        $seen[$key][$signature] = $line;
                    }

                    $matches = collect(MemberMatcher::find($organisationId, $data))
                        ->map(fn ($m) => ['uid' => $m['record']->uid, 'name' => $m['record']->name, 'keys' => $m['keys']])
                        ->values()
                        ->all();
                    if ($matches !== []) {
                        $status = self::STATUS_EXISTING;
                    }
                }
            }

            $rows[] = [
                'line' => $line,
                'data' => $data,
                'status' => $status,
                'errors' => $errors,
                'duplicate_of_line' => $duplicateOf,
                'matches' => $matches,
            ];
        }

        $preview = [
            'token' => (string) Str::ulid(),
            'organisation' => $organisation ? ['id' => $organisation->id, 'uid' => $organisation->uid, 'name' => $organisation->name] : null,
            'columns' => [
                ...collect($fields)->whereIn('id', array_values($columns))
                    ->map(fn ($f) => ['id' => $f['id'], 'label' => $f['label']])->values()->all(),
                ...(array_intersect(array_values($columns), array_column(self::SCHOOL_COLUMNS, 'id')) !== []
                    ? [['id' => 'school', 'label' => 'School']] : []),
            ],
            'unknown_columns' => $unknown,
            'missing_columns' => [],
            'rows' => $rows,
            'summary' => $this->summary($rows),
        ];

        Cache::put(self::CACHE_PREFIX.$preview['token'], ['user_id' => $userId] + $preview, now()->addMinutes(self::TTL_MINUTES));

        return $preview;
    }

    /** A preview saved by this user, or null when it expired or belongs to someone else. */
    public function stored(string $token, int $userId): ?array
    {
        $preview = Cache::get(self::CACHE_PREFIX.$token);

        return is_array($preview) && ($preview['user_id'] ?? null) === $userId ? $preview : null;
    }

    /**
     * @return array{created: int, updated: int, skipped: int, failed: int}
     *
     * @throws \InvalidArgumentException when the preview expired
     */
    public function import(string $token, int $userId, string $onDuplicate = self::ON_DUPLICATE_SKIP): array
    {
        $preview = $this->stored($token, $userId);
        if (! $preview) {
            throw new \InvalidArgumentException('This preview has expired. Upload the file again.');
        }

        $fields = collect(DirectoryFields::all())->keyBy('id');
        $organisationId = $preview['organisation']['id'] ?? null;
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        DB::transaction(function () use ($preview, $fields, $organisationId, $userId, $onDuplicate, &$result) {
            foreach ($preview['rows'] as $row) {
                if ($row['status'] === self::STATUS_VALID) {
                    $this->create($row['data'], $fields->all(), $organisationId, $userId);
                    $result['created']++;
                } elseif ($row['status'] === self::STATUS_EXISTING
                    && $onDuplicate === self::ON_DUPLICATE_UPDATE
                    && count($row['matches']) === 1) {
                    $record = MemberRecord::query()->where('uid', $row['matches'][0]['uid'])->first();
                    if ($record) {
                        $this->update($record, $row['data'], $fields->all(), $userId);
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }
                } elseif ($row['status'] === self::STATUS_ERROR) {
                    $result['failed']++;
                } else {
                    $result['skipped']++;
                }
            }
        });

        Cache::forget(self::CACHE_PREFIX.$token);

        return $result;
    }

    /** CSV header for the template download. */
    public function templateHeader(): array
    {
        return [...collect(DirectoryFields::all())->pluck('label')->all(), ...array_column(self::SCHOOL_COLUMNS, 'label')];
    }

    private function create(array $data, array $fields, ?int $organisationId, int $userId): MemberRecord
    {
        $record = MemberRecord::create([
            'organisation_id' => $organisationId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'source' => MemberRecord::SOURCE_BULK_UPLOAD,
            'status' => 'active',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->writeMetas($record, $data, $fields, $userId);
        SchoolLevels::attach($record, (array) ($data['school_records'] ?? []), $userId);

        return $record;
    }

    private function update(MemberRecord $record, array $data, array $fields, int $userId): void
    {
        // Only filled cells change the record: a blank cell keeps what is there.
        $main = array_filter(array_intersect_key($data, array_flip(DirectoryFields::CORE)), fn ($v) => $v !== null);
        $record->fill($main + ['updated_by' => $userId])->save();

        $this->writeMetas($record, $data, $fields, $userId);
        // E.g. the ISC register after the ICSE one: the person gets their second record.
        SchoolLevels::attach($record, (array) ($data['school_records'] ?? []), $userId);
    }

    /** Meta fields with a value; a blank cell writes nothing (and keeps an existing value on update). */
    private function writeMetas(MemberRecord $record, array $data, array $fields, int $userId): void
    {
        foreach ($data as $id => $value) {
            if (! ($fields[$id]['meta'] ?? false) || $value === null) {
                continue;
            }

            $record->metas()->updateOrCreate(
                ['meta_key' => $id],
                ['meta_value' => $value, 'status' => 'active', 'created_by' => $userId, 'updated_by' => $userId]
            );
        }
    }

    /** @return array{0: string[], 1: array<int, array<int, ?string>>} header and data lines */
    private function read(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            throw new \InvalidArgumentException('The file could not be read.');
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                throw new \InvalidArgumentException('The file is empty.');
            }

            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // Excel's UTF-8 BOM
            // Excel in some locales saves with ";".
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = array_map(fn ($h) => trim((string) $h), str_getcsv($first, $delimiter, '"', ''));

            $lines = [];
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue; // blank line
                }
                $lines[] = $cells;
            }
        } finally {
            fclose($handle);
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('The file has a header but no rows.');
        }

        return [$header, $lines];
    }

    /**
     * Column position → field id, matched by field id or label (any case, spaces or underscores).
     *
     * @return array{0: array<int, string>, 1: string[]} the mapping and the unknown column names
     */
    private function mapColumns(array $header, array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$this->columnKey($field['id'])] = $field['id'];
            $byName[$this->columnKey($field['label'])] = $field['id'];
        }

        $columns = [];
        $unknown = [];
        foreach ($header as $position => $name) {
            $id = $byName[$this->columnKey($name)] ?? null;
            if ($id && ! in_array($id, $columns, true)) {
                $columns[$position] = $id;
            } elseif ($name !== '') {
                $unknown[] = $name;
            }
        }

        return [$columns, $unknown];
    }

    private function columnKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_');
    }

    /** @return string[] messages for the row */
    private function validate(array $data, array $fields): array
    {
        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field['id'], $data) && ! $field['required']) {
                continue;
            }
            $rules[$field['id']] = DirectoryFields::rulesFor($field);
            $attributes[$field['id']] = $field['label'];
        }

        return Validator::make($data, $rules, [], $attributes)->errors()->all();
    }

    /**
     * The row's school record: Level (key or label; empty: the first level), Year,
     * Class left (for "Left"), Stream. None when the row has no year and no level.
     *
     * @return array{0: array, 1: string[]}
     */
    private function schoolRecords(?Organisation $organisation, array $cells): array
    {
        $level = $cells['school_level'] ?? null;
        $year = $cells['school_year'] ?? null;
        if (($level === null || $level === '') && ($year === null || $year === '')) {
            return [[], []];
        }
        if ($level === null || $level === '') {
            $level = SchoolLevels::for($organisation)[0]['key'] ?? null;
        }
        if (SchoolLevels::resolve($organisation, (string) $level) === null) {
            $known = collect(SchoolLevels::options($organisation))->pluck('label')->map(fn ($l) => SchoolLevels::shortLabel($l))->implode(', ');

            return [[], ["Level \"{$level}\" isn't one of this school's levels ({$known})."]];
        }

        [$rows, $errors] = SchoolLevels::validate($organisation, [[
            'level' => $level,
            'year' => $year,
            'class_left' => $cells['school_class_left'] ?? null,
            'stream' => $cells['school_stream'] ?? null,
        ]], false);

        return [$rows, array_values($errors)];
    }

    private function summary(array $rows): array
    {
        $counts = collect($rows)->countBy('status');

        return [
            'total' => count($rows),
            'valid' => $counts[self::STATUS_VALID] ?? 0,
            'errors' => $counts[self::STATUS_ERROR] ?? 0,
            'duplicates_in_file' => $counts[self::STATUS_DUPLICATE_IN_FILE] ?? 0,
            'existing' => $counts[self::STATUS_EXISTING] ?? 0,
        ];
    }
}
