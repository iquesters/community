<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The member directory: a generic table (search, paging, table and split
 * modes) whose rows come from community (data_url): approved members of the
 * viewer's organisation, name and public fields only.
 */
class DirectoryTable
{
    public const SLUG = 'community-directory-table';

    public const DATA_URL = '/community/directory/data';

    public static function schema(): array
    {
        $public = array_filter(array_map('trim', explode(',', (string) DirectoryFields::conf()->directory_public_fields)));
        $columns = [['data' => 'name', 'title' => 'Name', 'visible' => true]];
        foreach (DirectoryFields::all() as $field) {
            if (in_array($field['id'], $public, true) && ! in_array($field['id'], ['email', 'phone'], true)) {
                $columns[] = ['data' => $field['id'], 'title' => $field['label'], 'visible' => true];
            }
        }

        return [
            'entity' => 'member_records',
            'data_url' => self::DATA_URL,
            'dt-options' => [
                'columns' => $columns,
                'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
            ],
            'default_view_mode' => 'table',
        ];
    }

    /** Create or update (columns follow the public fields); returns the table's uid. */
    public static function sync(): ?string
    {
        if (! Schema::hasTable('table_schemas')) {
            return null;
        }

        $existing = DB::table('table_schemas')->where('slug', self::SLUG)->first(['uid', 'created_at']);
        DB::table('table_schemas')->updateOrInsert(['slug' => self::SLUG], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Directory',
            'description' => 'Approved members (name and public fields)',
            'schema' => json_encode(self::schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
}
