<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Auth\Events\Login;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Support\AutoMembership;
use Iquesters\Community\Tests\TestCase;

/** Bug #26: uploaded people become members when they sign in with a matching verified email or phone. */
class AutoMembershipTest extends TestCase
{
    public function test_a_verified_email_matching_one_record_makes_an_approved_member(): void
    {
        $organisation = $this->makeOrganisation();
        $record = MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'source' => 'bulk_upload']);
        $user = $this->makeUser('user');
        $user->forceFill(['email' => 'ANN@example.test', 'email_verified_at' => now()])->save();

        event(new Login('web', $user, false));

        $membership = Membership::where('user_id', $user->id)->sole();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame($record->id, (int) $membership->member_record_id);
        $this->assertSame($user->id, (int) $record->fresh()->user_id);
        $this->assertTrue($user->fresh()->hasRole('member'));
        $this->assertTrue($user->fresh()->hasOrganisation($organisation));
        $this->assertSame("Matched the school's records", $membership->histories()->first()->note);

        // Signing in again changes nothing.
        $this->assertSame([], AutoMembership::check($user->fresh()));
    }

    public function test_nothing_happens_without_a_verified_identifier_or_with_several_matches(): void
    {
        $organisation = $this->makeOrganisation();
        MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'source' => 'bulk_upload']);

        // Not verified.
        $unverified = $this->makeUser('user');
        $unverified->forceFill(['email' => 'ann@example.test', 'email_verified_at' => null])->save();
        $this->assertSame([], AutoMembership::check($unverified));
        $unverified->delete(); // emails are unique: the next user takes the address

        // Two records with that email in the organisation: an admin decides.
        MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann R.', 'email' => 'ann@example.test', 'source' => 'bulk_upload']);
        $verified = $this->makeUser('user');
        $verified->forceFill(['email' => 'ann@example.test', 'email_verified_at' => now()])->save();
        $this->assertSame([], AutoMembership::check($verified));
        $this->assertSame(0, Membership::count());
    }

    public function test_a_record_linked_to_someone_else_is_never_taken(): void
    {
        $organisation = $this->makeOrganisation();
        $owner = $this->makeUser('user');
        MemberRecord::create(['organisation_id' => $organisation->id, 'user_id' => $owner->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test', 'source' => 'registration']);
        $user = $this->makeUser('user');
        $user->forceFill(['email' => 'ann@example.test', 'email_verified_at' => now()])->save();

        $this->assertSame([], AutoMembership::check($user));
    }
}
