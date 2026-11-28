<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Community\Models\ApplicationProof;
use Iquesters\Organisation\Models\Organisation;

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

    /** Each organisation's copy (its own school levels): community-membership-application-{uid}. */
    public static function slugFor(?Organisation $organisation): string
    {
        return $organisation ? self::SLUG.'-'.strtolower($organisation->uid) : self::SLUG;
    }

    /** The organisation's form, made when missing (the page uses it). */
    public static function ensureFor(?Organisation $organisation): string
    {
        $slug = self::slugFor($organisation);
        if (Schema::hasTable('form_schemas') && ! DB::table('form_schemas')->where('slug', $slug)->exists()) {
            self::syncOne($organisation);
        }

        return $slug;
    }

    /**
     * A member's own details form (community-member-details-{uid}): the same fields
     * as the application (details, school years, proof), without the agreement.
     * An org-admin, made a member without applying, gives them here too.
     */
    public const DETAILS_SLUG = 'community-member-details';

    public static function detailsSlugFor(Organisation $organisation): string
    {
        return self::DETAILS_SLUG.'-'.strtolower($organisation->uid);
    }

    /** The member's details form for the organisation (rebuilt when its levels or the directory change). */
    public static function ensureDetailsFor(Organisation $organisation, bool $rebuild = false): string
    {
        $slug = self::detailsSlugFor($organisation);
        if (! Schema::hasTable('form_schemas') || (! $rebuild && DB::table('form_schemas')->where('slug', $slug)->exists())) {
            return $slug;
        }

        $fields = array_values(array_map(
            // Files already sent stay: a proof file isn't asked for again.
            fn (array $f) => ($f['type'] ?? null) === 'file' ? array_merge($f, ['required' => false]) : $f,
            array_filter(
                self::schema($organisation)['fields'],
                fn (array $f) => ! in_array($f['id'] ?? '', ['organisation_uid', 'consent', 'accept_terms'], true)
            )
        ));
        $schema = [
            'endpoint' => '/community/membership/details/{uid}',
            'method' => 'PUT',
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Save my details',
            'modes' => ['edit' => ['allowDelete' => false]],
            'fields' => $fields,
        ];
        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Member details: '.$organisation->name,
            'description' => 'A member edits their own details (community:seed rebuilds it)',
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return $slug;
    }

    /** The school records part: what the person completed at this school. */
    public static function schoolFields(?Organisation $organisation): array
    {
        return [
            ['id' => 'school', 'type' => 'section', 'label' => 'What you completed at this school',
                'description' => 'One row each: e.g. ICSE and its year, ISC and its year. Left earlier? Choose "Left before finishing", the class you left after, and the year.'],
            [
                'id' => 'school_records',
                'label' => 'Your years at this school',
                'type' => 'repeater',
                'addLabel' => 'Add another',
                'fields' => [
                    ['id' => 'level', 'label' => 'Completed', 'type' => 'select', 'required' => true, 'options' => [['value' => '', 'label' => 'Choose…'], ...SchoolLevels::options($organisation)]],
                    ['id' => 'year', 'label' => 'Year (passed out or left)', 'type' => 'number', 'required' => true, 'min' => SchoolLevels::yearRange($organisation)[0], 'max' => SchoolLevels::yearRange($organisation)[1]],
                    ['id' => 'class_left', 'label' => 'Class left after (only if you left early)', 'type' => 'number', 'min' => 1, 'max' => 12],
                    ['id' => 'stream', 'label' => 'Stream (e.g. Science)', 'type' => 'text', 'maxLength' => 64],
                ],
            ],
        ];
    }

    public static function schema(?Organisation $organisation = null): array
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

        array_push($fields, ...self::schoolFields($organisation));

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

        // The organisation's Terms / Privacy policy (links on the page, above the form).
        $fields[] = ['id' => 'consent', 'type' => 'section', 'label' => 'Agreement'];
        $fields[] = [
            'id' => 'accept_terms',
            'label' => "I have read and agree to this organisation's Terms and conditions and Privacy policy (linked above)",
            'type' => 'checkbox',
            'required' => true,
        ];

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

    /** Create or rebuild the form schemas: the base one and each active organisation's. */
    public static function sync(): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        self::syncOne(null);
        if (Schema::hasTable('organisations')) {
            Organisation::query()->where('status', 'active')->get()->each(fn (Organisation $o) => self::syncOne($o));
        }
    }

    /** One organisation's form (its school levels), or the base form. */
    public static function syncOne(?Organisation $organisation): void
    {
        $slug = self::slugFor($organisation);
        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);

        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => 'Membership application'.($organisation ? ': '.$organisation->name : ''),
            'description' => 'Built from the member directory, the school levels and the proof settings (community:seed rebuilds it)',
            'schema' => json_encode(self::schema($organisation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);

        // The member's own details form follows the same fields and levels.
        if ($organisation) {
            self::ensureDetailsFor($organisation, true);
        }
    }
}
