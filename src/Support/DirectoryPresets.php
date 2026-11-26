<?php

namespace Iquesters\Community\Support;

/**
 * Ready-made directory forms. The seeder creates the directory form from the
 * configured preset once; after that admins edit the form in the form builder.
 *
 * Core fields (name, email, phone) are member_records columns; the others are
 * meta fields ("meta": true), saved in member_record_metas.
 */
class DirectoryPresets
{
    public const PRESETS = ['alumni', 'club', 'association'];

    public const ORGANISATIONS_URL = '/api/community/v1/organisations';

    /** Where the directory form saves (community's member record endpoints). */
    public const MODES = [
        'create' => ['endpoint' => '/community/members', 'method' => 'POST'],
        'edit' => ['endpoint' => '/community/members/{uid}', 'method' => 'PUT', 'allowDelete' => false],
        'view' => ['allowDelete' => false],
    ];

    public static function coreFields(): array
    {
        return [
            ['id' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
            ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'maxLength' => 255],
            ['id' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'maxLength' => 32],
        ];
    }

    /** Gender (a meta field): also asked for everyone in a paid event registration. */
    public static function genderField(): array
    {
        return ['id' => 'gender', 'label' => 'Gender', 'type' => 'select', 'meta' => true, 'options' => [
            ['value' => 'male', 'label' => 'Male'],
            ['value' => 'female', 'label' => 'Female'],
            ['value' => 'other', 'label' => 'Other'],
        ]];
    }

    public static function fields(string $preset): array
    {
        $extra = match ($preset) {
            'club' => [
                ['id' => 'membership_number', 'label' => 'Membership number', 'type' => 'text', 'maxLength' => 64, 'meta' => true],
                ['id' => 'joined_year', 'label' => 'Joined (year)', 'type' => 'number', 'min' => 1900, 'max' => 2100, 'meta' => true],
            ],
            'association' => [
                ['id' => 'registration_number', 'label' => 'Registration number', 'type' => 'text', 'maxLength' => 64, 'meta' => true],
                ['id' => 'designation', 'label' => 'Designation', 'type' => 'text', 'maxLength' => 255, 'meta' => true],
                ['id' => 'employer', 'label' => 'Employer', 'type' => 'text', 'maxLength' => 255, 'meta' => true],
            ],
            default => [
                // The batches (ICSE 2003, ISC 2005, or the class left after) are school records, not a field.
                ['id' => 'section', 'label' => 'Section', 'type' => 'text', 'maxLength' => 32, 'meta' => true],
                ['id' => 'roll_number', 'label' => 'Roll number', 'type' => 'text', 'maxLength' => 64, 'meta' => true],
                ['id' => 'house', 'label' => 'House', 'type' => 'text', 'maxLength' => 64, 'meta' => true],
            ],
        };

        return [...self::coreFields(), self::genderField(), ...$extra];
    }

    /**
     * The organisation select of the directory form. A record needs an
     * organisation: the entity API scopes member_records by organisation_id.
     * Not a bulk upload column: the upload picks the organisation itself.
     */
    public static function organisationField(): array
    {
        return [
            'id' => 'organisation_id',
            'label' => 'Organisation',
            'type' => 'select',
            'required' => true,
            // The endpoint adds the first option itself ("Choose…", or why there is nothing to choose).
            'optionsFrom' => ['url' => self::ORGANISATIONS_URL],
        ];
    }

    /** The directory form schema for a preset (saved through the entity API). */
    public static function formSchema(string $preset): array
    {
        return [
            'entity' => 'member_records',
            // Saved through community (limited to the organisations managed), not the generic entity API.
            'modes' => self::MODES,
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Save',
            'fields' => [self::organisationField(), ...self::fields($preset)],
        ];
    }
}
