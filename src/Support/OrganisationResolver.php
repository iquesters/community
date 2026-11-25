<?php

namespace Iquesters\Community\Support;

use Illuminate\Support\Collection;
use Iquesters\Organisation\Models\Organisation;

/**
 * Which organisation a member record belongs to.
 *
 * - single mode: the configured organisation (default_organisation_uid), else
 *   the first active one, else none (records without an organisation);
 * - multiple mode: the one the admin picks, which must be active.
 */
class OrganisationResolver
{
    public static function isMultiple(): bool
    {
        return DirectoryFields::conf()->organisation_mode === 'multiple';
    }

    /** Organisations an admin can pick (multiple mode). */
    public static function choices(): Collection
    {
        return Organisation::query()->where('status', 'active')->orderBy('name')->get(['id', 'uid', 'name']);
    }

    /**
     * @throws \InvalidArgumentException when multiple mode gets no or an unknown organisation, or there is no organisation at all
     */
    public static function resolve(?string $organisationUid): Organisation
    {
        if (self::isMultiple()) {
            $organisation = $organisationUid
                ? Organisation::query()->where('uid', $organisationUid)->where('status', 'active')->first()
                : null;

            if (! $organisation) {
                throw new \InvalidArgumentException('Choose an active organisation.');
            }

            return $organisation;
        }

        $organisation = self::default();
        if (! $organisation) {
            // Records need one: the entity API hides records without an organisation.
            throw new \InvalidArgumentException('Create an organisation first (Organisations → New).');
        }

        return $organisation;
    }

    /** Single mode: the configured organisation, else the first active one. */
    public static function default(): ?Organisation
    {
        $uid = (string) DirectoryFields::conf()->default_organisation_uid;

        return $uid !== ''
            ? Organisation::query()->where('uid', $uid)->first()
            : Organisation::query()->where('status', 'active')->orderBy('id')->first();
    }

    /** Whether a member record can be created now (an organisation to put it in exists). */
    public static function available(): bool
    {
        return self::isMultiple() ? self::choices()->isNotEmpty() : self::default() !== null;
    }

    /**
     * Options for the directory form's organisation select: the default one in
     * single mode, every active one in multiple mode. The first option has no
     * value: "Choose…", or the reason there is nothing to choose.
     *
     * @return array<int, array{value: int|string, label: string}>
     */
    public static function options(): array
    {
        $organisations = self::isMultiple() ? self::choices() : collect([self::default()])->filter();

        if ($organisations->isEmpty()) {
            return [['value' => '', 'label' => 'No organisation yet. Create one first (Organisations → New)']];
        }

        return [
            ['value' => '', 'label' => 'Choose…'],
            ...$organisations->map(fn (Organisation $o) => ['value' => (int) $o->id, 'label' => $o->name])->values()->all(),
        ];
    }
}
