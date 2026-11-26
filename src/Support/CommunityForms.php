<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Community's other form schemas (the application and decision forms have
 * their own classes): bulk upload, its confirm step, and the organisation
 * choice on Membership. community:seed rebuilds them; the module owns them.
 */
class CommunityForms
{
    public const IMPORT = 'community-member-import';

    public const IMPORT_CONFIRM = 'community-member-import-confirm';

    public const ORGANISATION_CHOICE = 'community-organisation-choice';

    public const MEMBERSHIP_PAY = 'community-membership-pay';

    /** The same, with an optional donation (the organisation offers it with every paid order). */
    public const MEMBERSHIP_PAY_DONATION = 'community-membership-pay-donation';

    public static function syncAll(): void
    {
        self::upsert(self::IMPORT, 'Bulk upload of members', self::import());
        self::upsert(self::IMPORT_CONFIRM, 'Confirm bulk upload', self::importConfirm());
        self::upsert(self::ORGANISATION_CHOICE, 'Choose an organisation', self::organisationChoice());
        self::upsert(self::MEMBERSHIP_PAY_DONATION, 'Pay membership (with a donation)', [
            'endpoint' => '/community/membership/{uid}/pay',
            'method' => 'POST',
            'submitButtonLabel' => 'Pay membership',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'disableSnackbar' => true,
            'defaultFieldSize' => 12,
            'fields' => [
                ['id' => 'donation', 'label' => 'Add a donation, ₹ (optional)', 'type' => 'number', 'min' => 1, 'step' => '1', 'placeholder' => 'No donation', 'helperText' => 'Leave empty for none.'],
            ],
        ]);
        self::upsert(self::MEMBERSHIP_PAY, 'Pay membership', [
            'endpoint' => '/community/membership/{uid}/pay',
            'method' => 'POST',
            'submitButtonLabel' => 'Pay membership',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false, 'allowDelete' => false]],
            'disableSnackbar' => true,
            'fields' => [],
        ]);
    }

    public static function import(): array
    {
        return [
            'endpoint' => '/community/members/import/preview',
            'method' => 'POST',
            'submitButtonLabel' => 'Preview',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'disableSnackbar' => true,
            'fields' => [
                ['id' => 'upload', 'type' => 'section', 'label' => 'Upload a CSV file', 'description' => 'One row per person. You see a preview with errors and duplicates before anything is saved.'],
                ['id' => 'organisation_uid', 'label' => 'Organisation', 'type' => 'select', 'required' => true, 'optionsFrom' => ['url' => '/community/members/import/organisations']],
                ['id' => 'file', 'label' => 'CSV file', 'type' => 'file', 'required' => true, 'accept' => '.csv,text/csv', 'helperText' => 'From Excel: File → Save As → "CSV UTF-8". Download the template for the columns.'],
            ],
        ];
    }

    public static function importConfirm(): array
    {
        return [
            'endpoint' => '/community/members/import/{uid}',
            'method' => 'POST',
            'submitButtonLabel' => 'Import',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'disableSnackbar' => true,
            'fields' => [
                ['id' => 'on_duplicate', 'label' => 'People already in your data', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'skip', 'label' => 'Skip them'],
                    ['value' => 'update', 'label' => 'Update their record with the new values (one match only)'],
                ]],
            ],
            // One row: drop this preview and choose another file, or import it.
            'actions' => [
                [
                    'type' => 'link',
                    'route' => '/community/members/import',
                    'text' => 'Upload another file',
                    'element' => ['type' => 'a', 'variant' => 'outline', 'color' => 'secondary', 'size' => 'sm'],
                    'align' => 'end',
                    'row' => 0,
                ],
                [
                    'type' => 'submit',
                    'route' => '#',
                    'text' => 'Import',
                    'element' => ['type' => 'button', 'color' => 'primary', 'size' => 'sm'],
                    'align' => 'end',
                    'row' => 0,
                ],
            ],
        ];
    }

    public static function organisationChoice(): array
    {
        return [
            'endpoint' => '/community/membership/organisation',
            'method' => 'POST',
            'submitButtonLabel' => 'Continue',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            'defaultFieldSize' => 12,
            'disableSnackbar' => true,
            'fields' => [
                ['id' => 'organisation_uid', 'label' => 'Which organisation do you want to join?', 'type' => 'select', 'required' => true, 'optionsFrom' => ['url' => '/community/membership/organisations']],
            ],
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
