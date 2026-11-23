<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Services\Membership\MembershipService;
use Iquesters\Community\Tests\TestCase;

/**
 * Applying and approval, with the default (alumni) directory and proof fields.
 */
class MembershipApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MembershipService::PROOF_DISK);
    }

    private function apply(array $data = [], array $proof = [])
    {
        return $this->post('/community/apply', [
            'data' => $data + ['name' => 'Ann Roy', 'email' => 'ann@example.test', 'batch' => '1962'],
            'proof' => $proof,
        ]);
    }

    public function test_a_user_applies_with_details_and_proof(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser('user');
        $this->actingAs($user);

        $this->get('/community/apply')->assertOk()->assertSee('Apply to TTVians')->assertSee('Batch (passing year)');

        $this->apply([], [
            'registration_number' => ' TTV/1962/12 ',
            'proof_document' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect('/community/apply')->assertSessionHas('success');

        $membership = Membership::firstOrFail();
        $this->assertSame(Membership::STATUS_APPLIED, $membership->status);
        $this->assertSame($organisation->id, $membership->organisation_id);
        $this->assertSame($user->id, (int) $membership->user_id);
        $this->assertSame('1962', $membership->application_data['batch']);
        $this->assertSame(['registration_number' => 'TTV/1962/12'], $membership->proofs->where('kind', 'identifier')->pluck('value', 'field')->all());
        Storage::disk(MembershipService::PROOF_DISK)->assertExists($membership->proofs->firstWhere('kind', 'file')->file_path);
        $this->assertSame([null, 'applied'], [$membership->histories[0]->from_status, $membership->histories[0]->to_status]);

        // Waiting: no form, and a second application is refused.
        $this->get('/community/apply')->assertOk()->assertSee('waiting for a decision')->assertDontSee('name="data[name]"', false);
        $this->apply()->assertSessionHas('error', 'Your application is waiting for a decision.');
        $this->assertSame(1, Membership::count());
    }

    public function test_the_application_is_checked_like_the_directory(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('user'));

        $this->from('/community/apply')->apply(['batch' => '', 'email' => 'nope'])
            ->assertRedirect('/community/apply')
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
        $this->actingAs($admin)->get("/community/applications/{$membership->uid}")->assertOk()
            ->assertSee('Matching records')->assertSee('Same email');

        $this->post("/community/applications/{$membership->uid}/decision", ['decision' => 'approve', 'link_record_uid' => $record->uid])
            ->assertRedirect("/community/applications/{$membership->uid}");

        $membership->refresh();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame($record->id, (int) $membership->member_record_id);
        $this->assertSame($user->id, (int) $record->fresh()->user_id);
        $this->assertSame(1, MemberRecord::count());
        $this->assertSame($admin->id, (int) $membership->decided_by);

        $this->actingAs($user)->get('/community/apply')->assertSee("You're a member.");
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
        $this->actingAs($user)->get('/community/apply')->assertSee('Which section were you in?')->assertSee('Send again');

        $this->apply(['section' => 'C']);
        $membership->refresh();
        $this->assertSame(Membership::STATUS_APPLIED, $membership->status);
        $this->assertSame('C', $membership->application_data['section']);
        $this->assertSame(1, Membership::count());

        $this->actingAs($admin)->post("/community/applications/{$membership->uid}/decision", ['decision' => 'reject', 'note' => 'Not in our records.']);
        $this->assertSame(['applied', 'on_hold', 'applied', 'rejected'], $membership->histories()->pluck('to_status')->all());

        // A rejected person may apply again: a new application.
        $this->actingAs($user)->get('/community/apply')->assertSee('Not in our records.')->assertSee('name="data[name]"', false);
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
        $proof = Membership::firstOrFail()->proofs()->firstOrFail();

        $this->get('/community/applications')->assertForbidden();
        $this->get("/community/proofs/{$proof->uid}")->assertOk();

        $this->actingAs($this->makeUser('user'))->get("/community/proofs/{$proof->uid}")->assertNotFound();

        $this->actingAs($this->makeUser('super-admin'));
        $this->get('/community/applications')->assertOk()->assertSee('Ann Roy');
        $this->get("/community/proofs/{$proof->uid}")->assertOk();
    }
}
