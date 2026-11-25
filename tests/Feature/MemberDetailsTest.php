<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Community\Tests\TestCase;

/** Bug #22: members edit their own details; #17: the school pages give their forms the record's uid. */
class MemberDetailsTest extends TestCase
{
    private function member(): array
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser('user');
        $service = app(MembershipService::class);
        $membership = $service->approve(
            $service->apply($user, $organisation, ['name' => 'Ann Roy', 'school_records' => [['level' => 'icse', 'year' => 2003]]]),
            $this->makeUser('super-admin')->id
        );

        return [$organisation, $user, $membership];
    }

    public function test_a_member_edits_their_details_and_school_records(): void
    {
        [$organisation, $user, $membership] = $this->member();
        $this->actingAs($user);

        $this->get('/community/membership')->assertOk()->assertSee('Edit my details');
        $this->get("/community/membership/details/{$membership->uid}")->assertOk()
            ->assertSee('data-entity-uid="'.$membership->uid.'"', false)
            ->assertSee('community-member-details-', false);

        // ISC before 2005 is refused, with the other checks.
        $this->putJson("/community/membership/details/{$membership->uid}", ['name' => 'Ann Roy', 'school_records' => [['level' => 'isc', 'year' => 2003]]])
            ->assertStatus(422)->assertJsonValidationErrors('school_records.0.year');

        $this->putJson("/community/membership/details/{$membership->uid}", [
            'name' => 'Ann Roy',
            'phone' => '+91 98300-12345',
            'section' => 'B',
            'school_records' => [['level' => 'icse', 'year' => 2003], ['level' => 'isc', 'year' => 2005, 'stream' => 'Science']],
        ])->assertOk()->assertJsonPath('success', true);

        $record = MemberRecord::where('user_id', $user->id)->sole();
        $this->assertSame('+919830012345', $record->phone);
        $this->assertSame('B', $record->getMetaValue('section'));
        $this->assertSame('ICSE 2003 · ISC 2005 (Science)', SchoolLevels::describe($organisation, $record->schoolRecords));
        // Noted in the membership's history for the admins.
        $this->assertTrue($membership->histories()->where('note', 'Details updated by the member')->exists());
    }

    public function test_only_the_member_edits_and_only_once_approved(): void
    {
        [, , $membership] = $this->member();

        $this->actingAs($this->makeUser('user'))->get("/community/membership/details/{$membership->uid}")->assertNotFound();

        $membership->update(['status' => Membership::STATUS_ON_HOLD]);
        $this->actingAs($membership->user)->get("/community/membership/details/{$membership->uid}")->assertNotFound();
    }

    public function test_a_member_without_school_records_is_asked_to_complete_them(): void
    {
        [$organisation, $user, $membership] = $this->member();
        $membership->memberRecord->schoolRecords()->delete();

        $this->actingAs($user)->get('/community/membership')->assertOk()->assertSee('Complete your details');
        $this->assertNotNull($organisation);
    }

    public function test_the_school_pages_give_their_forms_the_uid_and_open_read_only(): void
    {
        [$organisation, , $membership] = $this->member();
        $this->actingAs($this->makeUser('super-admin'));
        $record = $membership->memberRecord;

        $this->get("/community/school-levels/{$organisation->uid}")->assertOk()
            ->assertSee('data-entity-uid="'.$organisation->uid.'"', false)
            ->assertSee('data-form-mode="view"', false);
        $this->get("/community/members/{$record->uid}/school/edit")->assertOk()
            ->assertSee('data-entity-uid="'.$record->uid.'"', false);

        // The address the form sends to (bug #17 sent PUT /community/school-levels).
        $this->putJson("/community/school-levels/{$organisation->uid}", ['levels' => [
            ['key' => 'icse', 'label' => 'ICSE (Class 10)', 'class' => 10],
            ['key' => 'isc', 'label' => 'ISC (Class 12)', 'class' => 12, 'from_year' => 2005],
        ]])->assertOk();
    }

    public function test_the_details_form_has_the_proof_fields_too(): void
    {
        [, $user, $membership] = $this->member();
        $this->actingAs($user);

        // Same fields as the application (an org-admin, made a member without applying, gives them here).
        $this->get("/community/membership/details/{$membership->uid}")->assertOk();
        $schema = json_decode(DB::table('form_schemas')->where('slug', 'like', 'community-member-details-%')->value('schema'), true);
        $ids = array_column($schema['fields'], 'id');
        $this->assertContains('proof__registration_number', $ids);
        $this->assertContains('proof__proof_document', $ids);
        $this->assertNotContains('accept_terms', $ids);

        $this->putJson("/community/membership/details/{$membership->uid}", [
            'name' => 'Ann Roy',
            'school_records' => [['level' => 'icse', 'year' => 2003]],
            'proof__registration_number' => 'TTV/2003/12',
        ])->assertOk();
        $this->assertSame('TTV/2003/12', $membership->proofs()->where('field', 'registration_number')->value('value'));
    }
}
