<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The approval form on an application (a form schema): the matching record to
 * link (options from /community/applications/{uid}/matches), the decision and
 * the note to the applicant. Posts to /community/applications/{uid}/decision.
 * community:seed rebuilds it.
 */
class DecisionForm
{
    public const SLUG = 'community-application-decision';

    public static function schema(): array
    {
        return [
            'endpoint' => '/community/applications/{uid}/decision',
            'method' => 'POST',
            'submitButtonLabel' => 'Save decision',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'fields' => [
                [
                    'id' => 'link_record_uid',
                    'label' => 'Matching records',
                    'type' => 'radio',
                    'helperText' => 'People in your data who look like this applicant. Approving links their account to the one you pick.',
                    'optionsFrom' => ['url' => '/community/applications/{uid}/matches'],
                ],
                [
                    'id' => 'decision',
                    'label' => 'Decision',
                    'type' => 'radio',
                    'required' => true,
                    'options' => [
                        ['value' => 'approve', 'label' => 'Approve'],
                        ['value' => 'hold', 'label' => 'Hold: ask for more details'],
                        ['value' => 'reject', 'label' => 'Reject'],
                    ],
                ],
                [
                    'id' => 'note',
                    'label' => 'Note to the applicant',
                    'type' => 'textarea',
                    'rows' => 3,
                    'maxLength' => 2000,
                    'helperText' => 'Needed to hold or reject; the applicant sees it.',
                ],
            ],
        ];
    }

    public static function sync(): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', self::SLUG)->first(['uid', 'created_at']);

        DB::table('form_schemas')->updateOrInsert(['slug' => self::SLUG], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Application decision',
            'description' => 'Approve, hold or reject a membership application (community:seed rebuilds it)',
            'schema' => json_encode(self::schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
