<?php

namespace Iquesters\Community\Tests\Feature;

use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Support\SchoolLevels;
use Iquesters\Community\Tests\TestCase;
use Iquesters\Organisation\Support\OrganisationRoles;

/** Bug #27: an organisation admin's Member records come from community, limited to their organisations. */
class MemberRecordsTest extends TestCase
{
    public function test_an_org_admin_lists_adds_and_edits_their_organisations_records_only(): void
    {
        $mine = $this->makeOrganisation('Mine');
        $other = $this->makeOrganisation('Other');
        $admin = $this->makeUser('admin');
        OrganisationRoles::join($admin, $mine, 'org-admin');

        $ann = MemberRecord::create(['organisation_id' => $mine->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'source' => 'bulk_upload']);
        SchoolLevels::attach($ann, [['level' => 'icse', 'year' => 2003]]);
        $theirs = MemberRecord::create(['organisation_id' => $other->id, 'name' => 'Ben Other', 'source' => 'bulk_upload']);
        $this->actingAs($admin);

        $this->getJson('/community/members/data')->assertOk()
            ->assertJsonCount(1, 'response_schema.data')
            ->assertJsonPath('response_schema.data.0.name', 'Ann Roy')
            ->assertJsonPath('response_schema.data.0.school', 'ICSE 2003')
            ->assertJsonPath('response_schema.data.0.has_login', 'No');
        $this->getJson('/community/members/data?search=2003')->assertJsonCount(1, 'response_schema.data');
        $this->getJson("/community/members/data/{$theirs->uid}")->assertNotFound();

        $uid = $this->postJson('/community/members', ['organisation_id' => $mine->id, 'name' => 'Cid Das', 'section' => 'B'])
            ->assertCreated()->json('data.uid');
        $this->assertSame('B', MemberRecord::where('uid', $uid)->sole()->getMetaValue('section'));
        $this->postJson('/community/members', ['organisation_id' => $other->id, 'name' => 'Nope'])
            ->assertStatus(422)->assertJsonValidationErrors('organisation_id');

        $this->putJson("/community/members/{$ann->uid}", ['organisation_id' => $mine->id, 'name' => 'Ann Roy', 'phone' => '+91 98300-12345'])->assertOk();
        $this->assertSame('+919830012345', $ann->fresh()->phone);
        $this->putJson("/community/members/{$theirs->uid}", ['organisation_id' => $mine->id, 'name' => 'Nope'])->assertNotFound();
    }
}
