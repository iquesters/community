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

    /** Wording in the UI, e.g. "Alumnus" / "Alumni record". */
    protected string $member_label;

    protected string $record_label;

    /** Form schema (slug) whose fields are the directory: core columns plus meta fields (batch, section…). */
    protected string $directory_form;

    /** Preset the seeder uses when the directory form doesn't exist yet: alumni, club, association. */
    protected string $directory_preset;

    /** Comma-separated match keys; "+" joins fields that must all match, e.g. "email,phone,batch+name". */
    protected string $match_keys;

    /** Most rows one bulk upload may have. */
    protected int $import_max_rows;

    /** Whether people can apply for membership themselves (Apply page). */
    protected bool $self_registration;

    /**
     * Proof asked for when applying. Each: id, label, kind (identifier | file),
     * required, and for files: types (comma-separated extensions) and max_kb.
     */
    protected array $proof_fields;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->organisation_mode = 'single';
        $default_values->default_organisation_uid = '';
        $default_values->member_label = 'Member';
        $default_values->record_label = 'Member record';
        $default_values->directory_form = 'community-member-record';
        $default_values->directory_preset = 'alumni';
        $default_values->match_keys = 'email,phone,batch+name';
        $default_values->import_max_rows = 5000;
        $default_values->self_registration = true;
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
            'member_label' => 'string|max:60',
            'record_label' => 'string|max:60',
            'directory_form' => 'string|max:255',
            'directory_preset' => 'in:alumni,club,association',
            'match_keys' => 'string|max:255',
            'import_max_rows' => 'integer|min:1|max:50000',
            'self_registration' => 'boolean',
            'proof_fields' => 'array|max:10',
        ];
    }
}
