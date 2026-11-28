<?php

namespace Iquesters\Community\Tests\Feature;

use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Community\Tests\TestCase;

/** The year someone passed out or left: not in the future, not before the organisation was founded. */
class SchoolYearsTest extends TestCase
{
    public function test_years_run_from_the_founding_to_this_year(): void
    {
        $organisation = $this->makeOrganisation();
        $now = (int) now()->year;

        // Not set: from 1900.
        $this->assertSame([1900, $now], SchoolLevels::yearRange($organisation));

        // Founded 1962 (the organisation's profile).
        $organisation->setMetaValue('founded_year', 1962);
        $this->assertSame([1962, $now], SchoolLevels::yearRange($organisation->fresh()));

        $level = SchoolLevels::options($organisation->fresh())[0]['value'];
        $check = fn (int $year) => SchoolLevels::validate($organisation->fresh(), [['level' => $level, 'year' => $year]])[1];
        $this->assertSame([], $check(2005));
        $this->assertSame([], $check($now));
        $this->assertSame(['school_records.0.year' => "The year can't be in the future (up to {$now})."], $check($now + 1));
        $this->assertSame(['school_records.0.year' => "Enter the year (1962–{$now})."], $check(1955));

        // The application form's year field says the same.
        $year = collect(collect(ApplicationForm::schoolFields($organisation->fresh()))->firstWhere('id', 'school_records')['fields'])->firstWhere('id', 'year');
        $this->assertSame([1962, $now], [$year['min'], $year['max']]);
    }
}
