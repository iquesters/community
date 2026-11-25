<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Iquesters\Community\Dashboard\MembershipCard;
use Iquesters\Community\Database\Seeders\CommunitySeeder;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Tests\TestCase;
use Iquesters\Foundation\Models\NavigationItem;

/**
 * Applying and approval, with the default (alumni) directory and proof fields.
 */
class MembershipApplicationTest extends TestCase
{
    /** The application form (form schema) on the page. */
    private const FORM = 'id="community-membership-application"';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MembershipService::PROOF_DISK);
    }

    private function apply(array $data = [], array $proof = [])
    {
        // As the application form (form schema) sends it: field ids, proof__{id}.
        $payload = $data + ['name' => 'Ann Roy', 'email' => 'ann@example.test', 'batch' => '1962'];
        foreach ($proof as $id => $value) {
            $payload['proof__'.$id] = $value;
        }

        return $this->post('/community/membership', $payload);
    }

    public function test_a_user_applies_with_details_and_proof(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser('user');
        $this->actingAs($user);

        $this->get('/community/membership')->assertOk()->assertSee('Apply to TTVians')->assertSee(self::FORM, false);

        $this->apply([], [
            'registration_number' => ' TTV/1962/12 ',
            'proof_document' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect('/community/membership')->assertSessionHas('success');

        $membership = Membership::firstOrFail();
        $this->assertSame(Membership::STATUS_APPLIED, $membership->status);
        $this->assertSame($organisation->id, $membership->organisation_id);
        $this->assertSame($user->id, (int) $membership->user_id);
        $this->assertSame('1962', $membership->application_data['batch']);
        $this->assertSame(['registration_number' => 'TTV/1962/12'], $membership->proofs->where('kind', 'identifier')->pluck('value', 'field')->all());
        Storage::disk(MembershipService::PROOF_DISK)->assertExists($membership->proofs->firstWhere('kind', 'file')->file_path);
        $this->assertSame([null, 'applied'], [$membership->histories[0]->from_status, $membership->histories[0]->to_status]);

        // Waiting: no form, and a second application is refused.
        $this->get('/community/membership')->assertOk()->assertSee('waiting for a decision')->assertDontSee(self::FORM, false);
        $this->apply()->assertSessionHas('error', 'Your application is waiting for a decision.');
        $this->assertSame(1, Membership::count());
    }

    public function test_the_application_is_checked_like_the_directory(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('user'));

        $this->from('/community/membership')->apply(['batch' => '', 'email' => 'nope'])
            ->assertRedirect('/community/membership')
            ->assertSessionHasErrors(['batch', 'email']);
        $this->assertSame(0, Membership::count());
    }

    public function test_approving_links_the_user_to_an_existing_record(): void
    {
        $organisation = $this->makeOrganisation();
        $record = MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'source' => 'bulk_upload']);
        $user = $this->makeUser('user');
        $this->actingAs($user)->apply();
        $membership = Membership::firstOrFail();

        $admin = $this->makeUser('super-admin');
        // The record the table opens: the application's values, and the one clear match picked already.
        $this->actingAs($admin)->getJson("/community/applications/data/{$membership->uid}")->assertOk()
            ->assertJsonPath('response_schema.data.batch', '1962')
            ->assertJsonPath('response_schema.data.status', 'applied')
            ->assertJsonPath('response_schema.data.link_record_uid', $record->uid);
        $this->get("/community/applications/{$membership->uid}/history")->assertOk()->assertSee('Applied');

        // The decision form's "Matching records": the record (one match: preselected on the page), and "none of these".
        $this->getJson("/community/applications/{$membership->uid}/matches")->assertOk()
            ->assertJsonPath('data.0.value', $record->uid)
            ->assertJsonPath('data.0.label', 'Ann Roy (ann@example.test): same email')
            ->assertJsonPath('data.1.value', '');

        // The form engine posts JSON: a missing note for hold comes back under the field.
        $this->postJson("/community/applications/{$membership->uid}/decision", ['decision' => 'hold'])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        // The form engine gets JSON (the record reloads in place).
        $this->postJson("/community/applications/{$membership->uid}/decision", ['decision' => 'approve', 'link_record_uid' => $record->uid])
            ->assertOk()->assertJsonPath('message', 'Approved.');

        $membership->refresh();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame($record->id, (int) $membership->member_record_id);
        $this->assertSame($user->id, (int) $record->fresh()->user_id);
        $this->assertSame(1, MemberRecord::count());
        $this->assertSame($admin->id, (int) $membership->decided_by);
        // Approved: the member role is added; the sign-up role stays.
        $this->assertTrue($user->fresh()->hasRole('member'));
        $this->assertTrue($user->fresh()->hasRole('user'));

        $this->actingAs($user)->get('/community/membership')->assertSee('Member since');
    }

    public function test_approving_without_a_match_creates_a_record_from_the_application(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser('user');
        $this->actingAs($user)->apply(['phone' => '+91 98300-12345', 'section' => 'B']);
        $membership = Membership::firstOrFail();

        $this->actingAs($this->makeUser('super-admin'))
            ->post("/community/applications/{$membership->uid}/decision", ['decision' => 'approve']);

        $record = MemberRecord::firstOrFail();
        $this->assertSame(MemberRecord::SOURCE_REGISTRATION, $record->source);
        $this->assertSame($user->id, (int) $record->user_id);
        $this->assertSame($organisation->id, $record->organisation_id);
        $this->assertSame('+919830012345', $record->phone);
        $this->assertSame(['batch' => '1962', 'section' => 'B'], $record->metaValues());
    }

    public function test_hold_lets_the_applicant_send_it_again_and_reject_needs_a_reason(): void
    {
        $this->makeOrganisation();
        $user = $this->makeUser('user');
        $this->actingAs($user)->apply();
        $membership = Membership::firstOrFail();
        $admin = $this->makeUser('super-admin');

        $this->actingAs($admin)->post("/community/applications/{$membership->uid}/decision", ['decision' => 'hold'])
            ->assertSessionHasErrors('note');

        $this->post("/community/applications/{$membership->uid}/decision", ['decision' => 'hold', 'note' => 'Which section were you in?']);
        $this->actingAs($user)->get('/community/membership')->assertSee('Which section were you in?')->assertSee(self::FORM, false);

        $this->apply(['section' => 'C']);
        $membership->refresh();
        $this->assertSame(Membership::STATUS_APPLIED, $membership->status);
        $this->assertSame('C', $membership->application_data['section']);
        $this->assertSame(1, Membership::count());

        $this->actingAs($admin)->post("/community/applications/{$membership->uid}/decision", ['decision' => 'reject', 'note' => 'Not in our records.']);
        $this->assertSame(['applied', 'on_hold', 'applied', 'rejected'], $membership->histories()->pluck('to_status')->all());

        // A rejected person may apply again: a new application.
        $this->actingAs($user)->get('/community/membership')->assertSee('Not in our records.')->assertSee(self::FORM, false);
        $this->apply();
        $this->assertSame(2, Membership::count());
    }

    public function test_a_decided_application_cannot_be_decided_again(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('user'))->apply();
        $membership = Membership::firstOrFail();
        $this->actingAs($this->makeUser('super-admin'));
        $this->post("/community/applications/{$membership->uid}/decision", ['decision' => 'reject', 'note' => 'No.']);

        $this->post("/community/applications/{$membership->uid}/decision", ['decision' => 'approve'])
            ->assertSessionHas('error', "This application is rejected: it can't be approved now.");
        $this->assertSame(0, MemberRecord::count());
    }

    public function test_a_record_linked_to_someone_else_cannot_be_linked(): void
    {
        $organisation = $this->makeOrganisation();
        $other = $this->makeUser('user');
        $record = MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'user_id' => $other->id]);
        $this->actingAs($this->makeUser('user'))->apply();
        $membership = Membership::firstOrFail();

        $this->actingAs($this->makeUser('super-admin'))
            ->post("/community/applications/{$membership->uid}/decision", ['decision' => 'approve', 'link_record_uid' => $record->uid])
            ->assertSessionHas('error', 'That record is already linked to another person.');
        $this->assertSame(Membership::STATUS_APPLIED, $membership->fresh()->status);
    }

    public function test_the_queue_is_admin_only_and_proofs_are_private(): void
    {
        $this->makeOrganisation();
        $applicant = $this->makeUser('user');
        $this->actingAs($applicant)->apply([], ['proof_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')]);
        $membership = Membership::firstOrFail();
        $proof = $membership->proofs()->firstOrFail();

        $this->get('/community/applications')->assertForbidden();
        $this->get("/community/proofs/{$proof->uid}")->assertOk();

        $this->actingAs($this->makeUser('user'))->get("/community/proofs/{$proof->uid}")->assertNotFound();

        $this->actingAs($this->makeUser('super-admin'));
        $this->getJson('/community/applications/data')->assertOk()->assertJsonPath('response_schema.data.0.name', 'Ann Roy');
        $this->get("/community/applications/{$membership->uid}/proofs")->assertOk()->assertSee('id.pdf');
        $this->get("/community/proofs/{$proof->uid}")->assertOk();
    }

    public function test_the_dashboard_card_offers_membership_while_the_module_is_on(): void
    {
        $this->seed(CommunitySeeder::class);
        $user = $this->makeUser('user');
        $card = app(MembershipCard::class);

        $this->assertStringContainsString('Apply for membership', (string) $card->render($user));

        // Applied: the card shows where it is, with no second Apply.
        $this->actingAs($user)->apply();
        $html = (string) $card->render($user);
        $this->assertStringContainsString('waiting for a decision', $html);
        $this->assertStringNotContainsString('Apply for membership', $html);

        // Module off: no card.
        DB::table('modules')->where('name', 'community')->update(['status' => 'inactive']);
        $this->assertNull($card->render($user));
    }

    public function test_the_card_is_one_of_the_dashboard_cards(): void
    {
        $this->assertContains(
            MembershipCard::class,
            array_map('get_class', iterator_to_array(app()->tagged('dashboard-cards')))
        );
    }

    public function test_the_form_engine_gets_json_with_errors_under_its_fields(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('user'));

        $this->postJson('/community/membership', ['name' => 'Ann Roy', 'batch' => '', 'proof__registration_number' => str_repeat('x', 300)])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['batch', 'proof__registration_number']);

        // Meta fields arrive under "meta", as the form engine sends them.
        $this->postJson('/community/membership', ['name' => 'Ann Roy', 'meta' => ['batch' => '1962', 'section' => 'B']])
            ->assertOk()
            ->assertJsonPath('redirect_url', url('/community/membership'));
        $this->assertSame('B', Membership::firstOrFail()->application_data['section']);
    }

    public function test_the_old_apply_address_redirects_to_membership(): void
    {
        $this->actingAs($this->makeUser('user'))->get('/community/apply')->assertRedirect('/community/membership');
    }

    public function test_the_seeder_builds_the_application_form_from_the_directory(): void
    {
        $this->seed(CommunitySeeder::class);

        $schema = json_decode(DB::table('form_schemas')->where('slug', 'community-membership-application')->value('schema'), true);
        $ids = array_column($schema['fields'], 'id');

        $this->assertSame('/community/membership', $schema['endpoint']);
        $this->assertContains('batch', $ids);
        $this->assertContains('proof__registration_number', $ids);
        $this->assertContains('proof__proof_document', $ids);
        $this->assertNotContains('organisation_id', $ids);
        $this->assertTrue(collect($schema['fields'])->firstWhere('id', 'batch')['meta']);
    }

    public function test_the_applications_list_filters_by_status_tab_and_search(): void
    {
        $organisation = $this->makeOrganisation();
        $service = app(MembershipService::class);
        $admin = $this->makeUser('super-admin');
        $waiting = $service->apply($this->makeUser('user'), $organisation, ['name' => 'Ann Roy', 'batch' => '1962']);
        $held = $service->hold($service->apply($this->makeUser('user'), $organisation, ['name' => 'Bob Sen', 'batch' => '1970']), $admin->id, 'More?');
        $service->approve($service->apply($this->makeUser('user'), $organisation, ['name' => 'Cid Das', 'batch' => '1980']), $admin->id);
        $this->actingAs($admin);

        $names = fn (string $query) => array_column($this->getJson('/community/applications/data'.$query)->assertOk()->json('response_schema.data'), 'name');

        $this->assertEqualsCanonicalizing(['Ann Roy', 'Bob Sen'], $names(''));            // Waiting: applied and on hold
        $this->assertSame(['Bob Sen'], $names('?status=on_hold'));
        $this->assertSame(['Cid Das'], $names('?status=approved'));
        $this->assertCount(3, $names('?status=all'));
        $this->assertSame(['Ann Roy'], $names('?status=all&search=ann'));
        $this->getJson('/community/applications/data?status=all&offset=0&length=2')->assertJsonPath('response_schema.meta.pagination.total', 3);
        $this->assertSame($held->uid, $this->getJson('/community/applications/data?status=on_hold')->json('response_schema.data.0.uid'));
        $this->assertNotNull($waiting);
    }

    public function test_the_seeder_makes_the_applications_table_with_status_tabs(): void
    {
        $this->seed(CommunitySeeder::class);

        $table = DB::table('table_schemas')->where('slug', 'community-application-table')->first();
        $schema = json_decode($table->schema, true);
        $this->assertSame('/community/applications/data', $schema['data_url']);
        // Sections: the application (view), the proof files, the decision (while open), the history.
        $this->assertSame(['community-membership-application', 'community-application-decision'], array_column($schema['record'], 'form'));
        $this->assertCount(4, $schema['record']);

        $tabs = NavigationItem::where('key', 'like', 'community.applications.%')->orderBy('sort')->get();
        $this->assertSame(['Waiting', 'On hold', 'Approved', 'Rejected', 'All'], $tabs->pluck('label')->all());
        $this->assertStringEndsWith("/list/{$table->uid}?status=on_hold", $tabs[1]->href());

        $this->actingAs($this->makeUser('super-admin'))->get('/community/applications?status=approved')
            ->assertRedirect("/list/{$table->uid}?status=approved");
    }
}
