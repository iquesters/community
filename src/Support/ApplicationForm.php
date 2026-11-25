<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Community\Models\ApplicationProof;

/**
 * The membership application form, a form schema rendered by user-interface's
 * form engine: the directory's fields, then the proof fields, posted to
 * /community/membership.
 *
 * It is built from the directory form and the proof settings, so it isn't
 * edited by hand: change the directory form (form builder) or proof_fields,
 * then run community:seed to rebuild it.
 */
class ApplicationForm
{
    public const SLUG = 'community-membership-application';

    /** Proof fields' ids in the form: proof__{id} (directory field ids can't clash with them). */
    public const PROOF_PREFIX = 'proof__';

    public static function schema(): array
    {
        $fields = [
            // Which organisation (prefilled by the page; multiple-organisation mode).
            ['id' => 'organisation_uid', 'type' => 'hidden'],
            ['id' => 'details', 'type' => 'section', 'label' => 'Your details', 'description' => 'As the school would have them, so we can find your record.'],
        ];

        foreach (DirectoryFields::all() as $field) {
            $definition = $field['field'];
            $definition['id'] = $field['id'];
            $definition['meta'] = $field['meta'];
            unset($definition['optionsFrom']); // admin-only endpoints; static options stay
            $fields[] = $definition;
        }

        if (ProofFields::all() !== []) {
            $fields[] = ['id' => 'proof', 'type' => 'section', 'label' => 'Proof', 'description' => 'Helps when the school\'s records don\'t have you. Only administrators see it.'];
        }

        foreach (ProofFields::all() as $proof) {
            $isFile = $proof['kind'] === ApplicationProof::KIND_FILE;
            $fields[] = array_filter([
                'id' => self::PROOF_PREFIX.$proof['id'],
                'label' => $proof['label'],
                'type' => $isFile ? 'file' : 'text',
                'required' => $proof['required'],
                'maxLength' => $isFile ? null : 255,
                'helperText' => $isFile
                    ? strtoupper(implode(', ', $proof['types'])).', up to '.round($proof['max_kb'] / 1024, 1).' MB. Only administrators see it.'
                    : 'Only administrators see it.',
            ], fn ($value) => $value !== null);
        }

        return [
            'endpoint' => '/community/membership',
            'method' => 'POST',
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Apply',
            'allowCancel' => false,
            'modes' => ['edit' => ['allowCancel' => false]],
            // The page shows the outcome (status card); a toast on top would repeat it.
            'disableSnackbar' => true,
            'fields' => $fields,
        ];
    }

    /** Create or rebuild the form schema. */
    public static function sync(): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', self::SLUG)->first(['uid', 'created_at']);

        DB::table('form_schemas')->updateOrInsert(['slug' => self::SLUG], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Membership application',
            'description' => 'Built from the member directory and the proof settings (community:seed rebuilds it)',
            'schema' => json_encode(self::schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
