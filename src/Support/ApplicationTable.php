<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Applications list: a generic table (table and split modes) whose rows
 * come from community's endpoint (data_url, limited to the organisations the
 * admin manages) and whose record shows as sections: the application (form,
 * view mode), the proof files, the decision form while open, and the history.
 */
class ApplicationTable
{
    public const SLUG = 'community-application-table';

    public const DATA_URL = '/community/applications/data';

    public static function schema(): array
    {
        return [
            'entity' => 'memberships',
            'data_url' => self::DATA_URL,
            'record' => [
                ['form' => ApplicationForm::SLUG, 'mode' => 'view', 'title' => 'Application'],
                ['html' => '/community/applications/{uid}/proofs'],
                ['form' => DecisionForm::SLUG, 'mode' => 'edit', 'title' => 'Decision', 'when' => ['status' => ['applied', 'on_hold']]],
                ['html' => '/community/applications/{uid}/history', 'title' => 'History'],
            ],
            'dt-options' => [
                'columns' => [
                    ['data' => 'name', 'title' => 'Name', 'visible' => true, 'link' => true, 'form-schema-uid' => ApplicationForm::SLUG],
                    ['data' => 'email', 'title' => 'Account', 'visible' => true],
                    ['data' => 'school', 'title' => 'At school', 'visible' => true],
                    ['data' => 'organisation', 'title' => 'Organisation', 'visible' => true],
                    ['data' => 'status_label', 'title' => 'Status', 'visible' => true],
                    ['data' => 'created_at', 'title' => 'Applied', 'visible' => true],
                ],
                'options' => [
                    'pageLength' => 25,
                    'order' => [[5, 'desc']],
                    'responsive' => true,
                ],
            ],
            'default_view_mode' => 'inbox',
        ];
    }

    /** The table's uid (creating it when missing): sidebar items and tabs link to /list/{uid}. */
    public static function sync(): ?string
    {
        if (! Schema::hasTable('table_schemas')) {
            return null;
        }

        $existing = DB::table('table_schemas')->where('slug', self::SLUG)->first(['uid', 'schema', 'created_at']);
        $schema = self::schema();

        if ($existing) {
            // Admins may change columns and options; the data source and record sections are the module's.
            $stored = json_decode((string) $existing->schema, true) ?: [];
            $schema = array_merge($stored, array_intersect_key($schema, array_flip(['entity', 'data_url', 'record'])));
        }

        DB::table('table_schemas')->updateOrInsert(['slug' => self::SLUG], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Applications',
            'description' => 'People asking to join your organisation. Open one to see their details, proof and matching records, then approve, hold or reject.',
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return DB::table('table_schemas')->where('slug', self::SLUG)->value('uid');
    }

    public static function uid(): ?string
    {
        return Schema::hasTable('table_schemas') ? DB::table('table_schemas')->where('slug', self::SLUG)->value('uid') : null;
    }

    /** The application form's uid (list page record URLs name the form by uid). */
    public static function applicationFormUid(): ?string
    {
        return Schema::hasTable('form_schemas') ? DB::table('form_schemas')->where('slug', ApplicationForm::SLUG)->value('uid') : null;
    }
}
