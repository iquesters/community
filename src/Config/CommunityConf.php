<?php

namespace Iquesters\Community\Config;

use Iquesters\Community\Enums\Module;
use Iquesters\Foundation\Support\BaseConf;

/**
 * Community settings, stored in the DB conf (see docs/developer/configuration.md).
 */
class CommunityConf extends BaseConf
{
    protected ?string $identifier = Module::COMMUNITY;

    /** single: every member record uses one organisation; multiple: pick one per record. */
    protected string $organisation_mode;

    /** single mode: the organisation's uid. Empty: the first active organisation. */
    protected string $default_organisation_uid;

    /** single mode: name of the organisation community:seed creates when there is none. */
    protected string $default_organisation_name;

    /** Wording in the UI, e.g. "Alumnus" / "Alumni record". */
    protected string $member_label;

    protected string $record_label;

    /** Form schema (slug) whose fields are the directory: core columns plus meta fields (batch, section…). */
    protected string $directory_form;

    /** Preset the seeder uses when the directory form doesn't exist yet: alumni, club, association. */
    protected string $directory_preset;

    /**
     * Comma-separated match keys; "+" joins fields that must all match, e.g.
     * "email,phone,school+name". "school": a school record of the same level and
     * year, or of another level within 3 years (the Class 10 and Class 12 registers
     * of one person). "batch" (older settings) means "school".
     */
    protected string $match_keys;

    /** Most rows one bulk upload may have. */
    protected int $import_max_rows;

    /** Whether people can apply for membership themselves (Apply page). */
    protected bool $self_registration;

    /**
     * Someone signing in with a VERIFIED email or phone that matches exactly one
     * record of an organisation (e.g. from the school's lists) becomes a member
     * straight away, without applying.
     */
    protected bool $auto_approve_matches;

    /**
     * A paid membership (e.g. an alumnus's pass with membership at an event):
     * the application is approved when the payment is verified. Off: it waits
     * in Applications for a separate decision. An order line may say otherwise
     * (meta "approval": together | separate).
     */
    protected bool $approve_paid_memberships;

    /** Directory fields members see of each other, besides the name (comma-separated ids). Contact details stay private. */
    protected string $directory_public_fields;

    /** Role given on approval (added to the user's roles; sign-up keeps its own, e.g. "user"). Empty: none. */
    protected string $member_role;

    /**
     * Proof asked for when applying. Each: id, label, kind (identifier | file),
     * required, and for files: types (comma-separated extensions) and max_kb.
     */
    protected array $proof_fields;

    /**
     * Default school levels (an organisation can set its own: Community → School levels).
     * Each: key, label, class, from_year (first year it existed). Alumni who left
     * earlier give the class and year ("left").
     */
    protected array $school_levels;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->organisation_mode = 'single';
        $default_values->default_organisation_uid = '';
        $default_values->default_organisation_name = 'iq-test-org';
        $default_values->member_label = 'Member';
        $default_values->record_label = 'Member record';
        $default_values->directory_form = 'community-member-record';
        $default_values->directory_preset = 'alumni';
        $default_values->match_keys = 'email,phone,school+name';
        $default_values->import_max_rows = 5000;
        $default_values->self_registration = true;
        $default_values->auto_approve_matches = true;
        $default_values->approve_paid_memberships = true;
        $default_values->member_role = 'member';
        // Besides the name and the school years (ICSE 2003 · ISC 2005), which members always see.
        $default_values->directory_public_fields = 'section';
        $default_values->school_levels = [
            ['key' => 'icse', 'label' => 'ICSE (Class 10)', 'class' => 10, 'from_year' => null],
            ['key' => 'isc', 'label' => 'ISC (Class 12)', 'class' => 12, 'from_year' => 2005],
        ];
        $default_values->proof_fields = [
            ['id' => 'registration_number', 'label' => 'School registration number', 'kind' => 'identifier', 'required' => false],
            ['id' => 'proof_document', 'label' => 'Proof (e.g. leaving certificate or old ID card)', 'kind' => 'file', 'required' => false, 'types' => 'pdf,jpg,jpeg,png', 'max_kb' => 5120],
        ];
    }

    protected function rules(): array
    {
        return [
            'organisation_mode' => 'in:single,multiple',
            'default_organisation_uid' => 'nullable|string|max:26',
            'default_organisation_name' => 'string|max:255',
            'member_label' => 'string|max:60',
            'record_label' => 'string|max:60',
            'directory_form' => 'string|max:255',
            'directory_preset' => 'in:alumni,club,association',
            'match_keys' => 'string|max:255',
            'import_max_rows' => 'integer|min:1|max:50000',
            'self_registration' => 'boolean',
            'auto_approve_matches' => 'boolean',
            'approve_paid_memberships' => 'boolean',
            'member_role' => 'nullable|string|max:125',
            'directory_public_fields' => 'nullable|string|max:255',
            'proof_fields' => 'array|max:10',
            'school_levels' => 'array|max:10',
        ];
    }
}
