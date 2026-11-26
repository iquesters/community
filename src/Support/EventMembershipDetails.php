<?php

namespace Iquesters\Community\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Event\Contracts\MembershipDetails;
use Iquesters\Event\Models\Event;

/**
 * Community's answer to the event module (bound only when event is
 * installed): what a member is called (conf member_label, e.g. "Alumnus"),
 * and for a person who is (or joins as) a member of the event's organisation,
 * what they completed there and when: the organisation's school levels (e.g.
 * ICSE / ISC), or the class they left after. Checked like an application;
 * kept with their membership line, so their member record gets it.
 */
class EventMembershipDetails implements MembershipDetails
{
    public function memberLabel(Event $event): string
    {
        return trim((string) DirectoryFields::conf()->member_label) ?: 'Member';
    }

    public function fields(Event $event): array
    {
        return [
            ['id' => 'school_level', 'label' => 'Studied up to', 'type' => 'select', 'required' => true, 'options' => SchoolLevels::options($event->organisation)],
            ['id' => 'school_year', 'label' => 'Year of passing', 'type' => 'number', 'required' => true, 'min' => 1900, 'max' => (int) now()->year + 1, 'placeholder' => 'e.g. 2005'],
            ['id' => 'class_left', 'label' => 'Left after class', 'type' => 'number', 'min' => 1, 'max' => 12, 'required' => true, 'showWhen' => ['field' => 'school_level', 'equals' => SchoolLevels::LEFT]],
        ];
    }

    public function validate(Event $event, array $values): array
    {
        [$rows, $errors] = SchoolLevels::validate($event->organisation, [[
            'level' => $values['school_level'] ?? null,
            'year' => $values['school_year'] ?? null,
            'class_left' => $values['class_left'] ?? null,
        ]]);

        // school_records.0.level → school_level, …
        $byField = [];
        foreach ($errors as $key => $message) {
            $field = match (true) {
                str_ends_with($key, '.year') => 'school_year',
                str_ends_with($key, '.class_left') => 'class_left',
                default => 'school_level',
            };
            $byField[$field] = $message;
        }

        // With a line in words (e.g. "ISC 2005", "Left after Class 8, 2001"), for pages
        // that show the details without knowing them (the payment, the registration).
        return [$byField ? [] : ['school_records' => $rows, 'summary' => SchoolLevels::describe($event->organisation, $rows)], $byField];
    }

    public function prefill(Event $event, Authenticatable $user): array
    {
        $organisationId = $event->organisation_id;
        $member = Membership::query()->where('organisation_id', $organisationId)->where('user_id', $user->getAuthIdentifier())
            ->where('status', Membership::STATUS_APPROVED)->exists();
        $record = MemberRecord::query()->with('schoolRecords')->where('organisation_id', $organisationId)
            ->where('user_id', $user->getAuthIdentifier())->first();
        $school = $record?->schoolRecords->sortByDesc('year')->first();

        return array_filter([
            'member' => $member ?: null,
            'school_level' => $school?->level,
            'school_year' => $school?->year,
            'class_left' => $school?->class_left,
        ], fn ($v) => $v !== null);
    }
}
