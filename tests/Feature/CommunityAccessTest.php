<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Iquesters\Community\Dashboard\MembershipCard;
use Iquesters\Community\Database\Seeders\CommunitySeeder;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Support\CommunityAccess;
use Iquesters\Community\Tests\TestCase;
use Iquesters\Foundation\Models\Module;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationRoles;

/**
 * Who sees what: organisation admins manage only their organisations;
 * members get their home and the directory.
 */
class CommunityAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MembershipService::PROOF_DISK);
    }

    private function applicationIn(Organisation $organisation, string $name = 'Ann Roy'): Membership
    {
        return app(MembershipService::class)->apply($this->makeUser('user'), $organisation, ['name' => $name, 'school_records' => [['level' => 'icse', 'year' => 1962]]]);
    }

    /** An organisation admin: org-admin role in that organisation (organisation module). */
    private function organisationAdmin(Organisation $organisation)
    {
        $admin = $this->makeUser('admin');
        OrganisationRoles::join($admin, $organisation, 'org-admin');

        return $admin;
    }

    public function test_an_organisation_admin_manages_only_their_organisation(): void
    {
        $a = $this->makeOrganisation('Alpha');
        $b = $this->makeOrganisation('Beta');
        $mine = $this->applicationIn($a, 'Ann Alpha');
        $theirs = $this->applicationIn($b, 'Ben Beta');
        $admin = $this->organisationAdmin($a);

        $this->assertSame([$a->id], CommunityAccess::manageableOrganisationIds($admin));

        // The table's rows (data_url): only their organisation's applications.
        $this->actingAs($admin)->getJson('/community/applications/data')->assertOk()
            ->assertJsonPath('response_schema.data.0.name', 'Ann Alpha')
            ->assertJsonCount(1, 'response_schema.data');
        $this->getJson("/community/applications/data/{$theirs->uid}")->assertNotFound();
        $this->get("/community/applications/{$theirs->uid}")->assertNotFound();
        $this->post("/community/applications/{$theirs->uid}/decision", ['decision' => 'approve'])->assertNotFound();

        $this->post("/community/applications/{$mine->uid}/decision", ['decision' => 'approve']);
        $this->assertSame(Membership::STATUS_APPROVED, $mine->fresh()->status);
        $this->assertSame(Membership::STATUS_APPLIED, $theirs->fresh()->status);
    }

    public function test_an_organisation_admin_uploads_only_into_their_organisation(): void
    {
        $this->makeOrganisation('Alpha'); // the default organisation (single mode)
        $b = $this->makeOrganisation('Beta');
        $this->actingAs($this->organisationAdmin($b));

        $file = UploadedFile::fake()->createWithContent('a.csv', "name,batch\nAnn Roy,1962\n");
        $this->post('/community/members/import/preview', ['file' => $file])
            ->assertSessionHas('error', "You don't manage this organisation.");
    }

    public function test_users_and_admins_without_an_organisation_get_no_admin_pages(): void
    {
        $this->makeOrganisation();

        $this->actingAs($this->makeUser('user'))->get('/community/applications')->assertForbidden();
        // "admin" alone, in no organisation, manages nothing.
        $this->actingAs($this->makeUser('admin'))->get('/community/applications')->assertForbidden();
    }

    public function test_proof_is_visible_to_the_admins_of_its_organisation_only(): void
    {
        $a = $this->makeOrganisation('Alpha');
        $b = $this->makeOrganisation('Beta');
        $applicant = $this->makeUser('user');
        $membership = app(MembershipService::class)->apply($applicant, $a, ['name' => 'Ann Roy', 'school_records' => [['level' => 'icse', 'year' => 1962]]], [
            'proof_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
        ]);
        $proof = $membership->proofs()->firstOrFail();

        $this->actingAs($this->organisationAdmin($a))->get("/community/proofs/{$proof->uid}")->assertOk();
        $this->actingAs($this->organisationAdmin($b))->get("/community/proofs/{$proof->uid}")->assertNotFound();
    }

    public function test_a_member_gets_their_home_and_the_directory(): void
    {
        $organisation = $this->makeOrganisation();
        $service = app(MembershipService::class);
        $admin = $this->makeUser('super-admin');

        // Two approved members, one person only in the school's data.
        $ann = $this->makeUser('user');
        $service->approve($service->apply($ann, $organisation, ['name' => 'Ann Roy', 'email' => 'ann@example.test', 'school_records' => [['level' => 'icse', 'year' => 1962]], 'section' => 'B']), $admin->id);
        $bob = $this->makeUser('user');
        $service->approve($service->apply($bob, $organisation, ['name' => 'Bob Sen', 'email' => 'bob@example.test', 'school_records' => [['level' => 'icse', 'year' => 1970]]]), $admin->id);
        MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Only In Records', 'source' => 'bulk_upload']);

        // Membership: their own membership and details only.
        $this->actingAs($ann)->get('/community/membership')->assertOk()
            ->assertSee('Member since')
            ->assertSee('ICSE 1962')
            ->assertDontSee('Bob Sen');

        // Directory (the generic table's rows): approved members only; contact details stay private.
        $names = fn (string $query = '') => array_column($this->getJson('/community/directory/data'.$query)->assertOk()->json('response_schema.data'), 'name');
        $this->assertEqualsCanonicalizing(['Ann Roy', 'Bob Sen'], $names());
        $this->getJson('/community/directory/data')->assertJsonMissing(['email' => 'bob@example.test']);
        $this->assertSame(['Bob Sen'], $names('?search=bob'));
        $this->assertSame(['Bob Sen'], $names('?search=1970')); // a public field's value
        $this->assertSame('ICSE 1962', collect($this->getJson('/community/directory/data')->json('response_schema.data'))->firstWhere('name', 'Ann Roy')['school']);
        // Search by a school year, filter by level and year.
        $this->assertSame(['Ann Roy'], array_column($this->getJson('/community/directory/data?search=1962')->json('response_schema.data'), 'name'));
        $this->assertSame(['Bob Sen'], array_column($this->getJson('/community/directory/data?level=icse&year=1970')->json('response_schema.data'), 'name'));

        // Not a member: no rows, and the page sends them to Membership.
        $this->actingAs($this->makeUser('user'));
        $this->getJson('/community/directory/data')->assertOk()->assertJsonCount(0, 'response_schema.data'); // not a member: empty, not an error
        $this->get('/community/directory')->assertRedirect('/community/membership');
    }

    public function test_someone_not_a_member_gets_the_apply_page_under_membership(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('user'))->get('/community/membership')->assertOk()->assertSee('Apply to TTVians');
    }

    public function test_the_seeder_gives_each_role_its_part(): void
    {
        $this->seed(CommunitySeeder::class);

        $module = Module::where('name', 'community')->firstOrFail();
        $this->assertEqualsCanonicalizing(['super-admin', 'admin', 'user', 'member'], $module->getAssignedRoles()->pluck('name')->all());

        $this->assertTrue($this->makeUser('user')->can(CommunitySeeder::USE_PERMISSION));
        $this->assertFalse($this->makeUser('user')->can(CommunitySeeder::MANAGE_PERMISSION));
        $this->assertTrue($this->makeUser('admin')->can(CommunitySeeder::MANAGE_PERMISSION));
        $this->assertTrue($this->makeUser('super-admin')->can(CommunitySeeder::MANAGE_PERMISSION));
    }

    public function test_organisation_admins_become_members_and_super_admins_do_not(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->organisationAdmin($organisation);

        // Opening Membership makes them a member: record, approved membership, member role.
        $this->actingAs($admin)->get('/community/membership')->assertOk()->assertSee('Member since');
        $membership = Membership::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame('Organisation admin', $membership->decision_note);
        $this->assertSame(MemberRecord::SOURCE_ADMIN, $membership->memberRecord->source);
        $this->assertTrue($admin->fresh()->hasRole('member'));

        // Again: nothing new.
        $this->get('/community/membership');
        $this->assertSame(1, Membership::where('user_id', $admin->id)->count());

        // Super-admin: no card, no member pages, no membership.
        $super = $this->makeUser('super-admin');
        $this->assertNull(app(MembershipCard::class)->render($super));
        $this->actingAs($super)->get('/community/membership')->assertRedirect('/dashboard/community');
        $this->assertSame(0, Membership::where('user_id', $super->id)->count());
    }

    public function test_the_sync_command_makes_existing_admins_members(): void
    {
        $organisation = $this->makeOrganisation();
        $admin = $this->organisationAdmin($organisation);

        $this->artisan('community:sync-admins')->expectsOutputToContain('created 1 memberships')->assertSuccessful();
        $this->assertTrue(Membership::where('user_id', $admin->id)->where('status', 'approved')->exists());
    }

    public function test_super_admin_has_no_membership_but_sees_every_directory(): void
    {
        $this->seed(CommunitySeeder::class);
        $organisation = Organisation::firstOrFail();
        $service = app(MembershipService::class);
        $super = $this->makeUser('super-admin');
        $service->approve($service->apply($this->makeUser('user'), $organisation, ['name' => 'Ann Roy', 'school_records' => [['level' => 'icse', 'year' => 1962]]]), $super->id);

        $this->assertFalse($super->can(CommunitySeeder::USE_PERMISSION));
        $this->assertTrue($super->can(CommunitySeeder::DIRECTORY_PERMISSION));
        $this->assertTrue($super->can(CommunitySeeder::MANAGE_PERMISSION));

        // Read-only, any organisation (the first, or ?organisation=).
        $this->actingAs($super)->getJson('/community/directory/data')->assertOk()->assertJsonPath('response_schema.data.0.name', 'Ann Roy');
        $this->getJson('/community/directory/data?organisation='.$organisation->uid)->assertOk()->assertJsonCount(1, 'response_schema.data');
    }
}
