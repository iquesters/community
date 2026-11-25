<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Organisation\Models\Organisation;

/**
 * Form schemas for school records and levels (community:seed and saving the
 * levels rebuild them):
 * - community-school-levels: an organisation's levels (repeater);
 * - community-member-school-records-{org uid}: a member's records, with that
 *   organisation's levels to choose from.
 */
class SchoolForms
{
    public const LEVELS = 'community-school-levels';

    public const RECORDS = 'community-member-school-records';

    public static function recordsSlug(?Organisation $organisation): string
    {
        return $organisation ? self::RECORDS.'-'.strtolower($organisation->uid) : self::RECORDS;
    }

    public static function syncAll(): void
    {
        self::upsert(self::LEVELS, 'School levels', self::levels());
        self::syncRecords(null);
        if (Schema::hasTable('organisations')) {
            Organisation::query()->where('status', 'active')->get()->each(fn (Organisation $o) => self::syncRecords($o));
        }
    }

    public static function syncRecords(?Organisation $organisation): void
    {
        self::upsert(self::recordsSlug($organisation), 'School records'.($organisation ? ': '.$organisation->name : ''), self::records($organisation));
    }

    public static function levels(): array
    {
        return [
            'endpoint' => '/community/school-levels/{uid}',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save levels',
            'defaultFieldSize' => 12,
            // Opens read-only; Edit switches to the form, Cancel or Save back.
            'modes' => ['view' => ['allowDelete' => false], 'edit' => ['allowDelete' => false]],
            'fields' => [
                ['id' => 'about', 'type' => 'section', 'label' => 'Levels people pass out with',
                    'description' => 'E.g. ICSE (Class 10) and ISC (Class 12, from 2005). People who left earlier give the class and year instead; that option is always there.'],
                [
                    'id' => 'levels',
                    'label' => 'Levels (lowest class first)',
                    'type' => 'repeater',
                    'addLabel' => 'Add level',
                    'fields' => [
                        ['id' => 'key', 'label' => 'Short code (e.g. icse)', 'type' => 'text', 'required' => true, 'maxLength' => 32],
                        ['id' => 'label', 'label' => 'Name people see (e.g. ICSE (Class 10))', 'type' => 'text', 'required' => true, 'maxLength' => 64],
                        ['id' => 'class', 'label' => 'Class', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12],
                        ['id' => 'from_year', 'label' => 'First year (empty: always)', 'type' => 'number', 'min' => 1800, 'max' => 2100],
                    ],
                ],
            ],
        ];
    }

    public static function records(?Organisation $organisation): array
    {
        $school = ApplicationForm::schoolFields($organisation)[1];

        return [
            'endpoint' => '/community/members/{uid}/school-records',
            'method' => 'PUT',
            'submitButtonLabel' => 'Save school records',
            'defaultFieldSize' => 12,
            'modes' => ['view' => ['allowDelete' => false], 'edit' => ['allowDelete' => false]],
            'fields' => [array_merge($school, ['label' => 'What they completed at this school'])],
        ];
    }

    private static function upsert(string $slug, string $name, array $schema): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Community module form ({$slug}); community:seed rebuilds it",
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
