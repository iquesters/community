<?php

namespace Iquesters\Community\Tests\Feature;

use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Models\Membership;
use Iquesters\Community\Models\MembershipPeriod;
use Iquesters\Community\Support\AutoMembership;
use Iquesters\Community\Tests\TestCase;
use Iquesters\Organisation\Models\Organisation;

/** Paid membership lines (commerce OrderPaid) → membership periods and memberships. */
class OrderMembershipsTest extends TestCase
{
    private function membershipProduct(Organisation $organisation): Product
    {
        return Product::create([
            'organisation_id' => $organisation->id,
            'name' => 'TTVians membership 2027',
            'type' => 'membership',
            'price' => 300,
            'currency' => 'INR',
            'validity' => Product::VALIDITY_FIXED,
            'valid_from' => '2027-01-01',
            'valid_until' => '2027-12-31',
        ]);
    }

    /** One membership line per person (meta as the event module writes it), paid. */
    private function paidOrder(Organisation $organisation, array $people, ?int $buyerId = null)
    {
        $product = $this->membershipProduct($organisation);
        $orders = app(OrderService::class);
        $order = $orders->create($organisation, ['user_id' => $buyerId, 'name' => $people[0]['person'] ?? null], array_map(fn ($meta) => ['product' => $product, 'meta' => $meta], $people));

        return $orders->markPaid($order, 'Verified', 1);
    }

    public function test_paying_with_together_approves_the_registrant_and_records_the_period(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser();

        $this->paidOrder($organisation, [['person' => 'Rahul Sen', 'email' => 'rahul@example.test', 'gender' => 'Male', 'user_id' => $user->id, 'role' => 'registrant', 'approval' => 'together']], $user->id);

        $membership = Membership::query()->where('user_id', $user->id)->sole();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame('male', $membership->memberRecord->getMetaValue('gender'));
        $period = MembershipPeriod::query()->sole();
        $this->assertSame([$membership->id, '2027-01-01', '2027-12-31', '300.00'], [$period->membership_id, $period->valid_from->toDateString(), $period->valid_until->toDateString(), $period->amount]);
        $this->assertTrue($user->fresh()->hasRole('member'));
    }

    public function test_separate_leaves_the_application_for_an_admin(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser();

        $this->paidOrder($organisation, [['person' => 'Rahul Sen', 'user_id' => $user->id, 'role' => 'registrant', 'approval' => 'separate']], $user->id);

        $membership = Membership::query()->where('user_id', $user->id)->sole();
        $this->assertSame(Membership::STATUS_APPLIED, $membership->status);
        $this->assertSame($membership->id, MembershipPeriod::query()->sole()->membership_id);
    }

    public function test_an_existing_member_keeps_their_membership(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser();
        $this->paidOrder($organisation, [['person' => 'Rahul', 'user_id' => $user->id, 'role' => 'registrant', 'approval' => 'together']], $user->id);
        $membership = Membership::query()->sole();

        // Paying the next period.
        $this->paidOrder($organisation, [['person' => 'Rahul', 'user_id' => $user->id, 'role' => 'registrant', 'approval' => 'together']], $user->id);

        $this->assertSame(1, Membership::query()->count());
        $this->assertSame([$membership->id, $membership->id], MembershipPeriod::query()->pluck('membership_id')->all());
    }

    public function test_an_alumnus_guest_without_an_account_is_linked_when_they_sign_in(): void
    {
        $organisation = $this->makeOrganisation();
        $buyer = $this->makeUser();

        $this->paidOrder($organisation, [
            ['person' => 'Rahul', 'user_id' => $buyer->id, 'role' => 'registrant', 'approval' => 'together'],
            ['person' => 'Priya Sen', 'email' => 'Priya@Example.test', 'phone' => '98000 00002', 'gender' => 'Female', 'role' => 'guest', 'approval' => 'together'],
        ], $buyer->id);

        $record = MemberRecord::query()->where('source', MemberRecord::SOURCE_EVENT)->sole();
        $this->assertSame(['Priya Sen', 'priya@example.test', null, 'female'], [$record->name, $record->email, $record->user_id, $record->getMetaValue('gender')]);
        $guestPeriod = MembershipPeriod::query()->where('member_record_id', $record->id)->sole();
        $this->assertNull($guestPeriod->membership_id);

        // Priya signs up and verifies her email: she is a member, and the period is hers.
        $priya = $this->makeUser();
        $priya->forceFill(['email' => 'priya@example.test', 'email_verified_at' => now()])->save();
        AutoMembership::check($priya);

        $membership = Membership::query()->where('user_id', $priya->id)->sole();
        $this->assertSame(Membership::STATUS_APPROVED, $membership->status);
        $this->assertSame([$membership->id, $priya->id], [$guestPeriod->fresh()->membership_id, (int) $guestPeriod->fresh()->user_id]);
    }

    public function test_a_guest_with_a_verified_account_gets_their_own_membership(): void
    {
        $organisation = $this->makeOrganisation();
        $buyer = $this->makeUser();
        $priya = $this->makeUser();
        $priya->forceFill(['email' => 'priya@example.test', 'email_verified_at' => now()])->save();

        $this->paidOrder($organisation, [
            ['person' => 'Rahul', 'user_id' => $buyer->id, 'role' => 'registrant', 'approval' => 'together'],
            ['person' => 'Priya Sen', 'email' => 'priya@example.test', 'role' => 'guest', 'approval' => 'together'],
        ], $buyer->id);

        $this->assertSame(Membership::STATUS_APPROVED, Membership::query()->where('user_id', $priya->id)->sole()->status);
        $this->assertSame(2, Membership::query()->where('status', Membership::STATUS_APPROVED)->count());
    }

    public function test_a_member_pays_the_membership_on_its_own(): void
    {
        $organisation = $this->makeOrganisation();
        $user = $this->makeUser();
        $product = $this->membershipProduct($organisation);

        $first = $this->actingAs($user)->postJson('/community/membership/'.$organisation->uid.'/pay')->assertOk()->assertJsonPath('success', true);
        $order = Order::query()->sole();
        $this->assertSame(['MEM-0001', '300.00', $product->id], [$order->reference, $order->total, $order->lines->first()->product_id]);
        $this->assertSame('separate', $order->lines->first()->meta['approval']);

        // Again: the same unpaid order, not a second one.
        $this->actingAs($user)->postJson('/community/membership/'.$organisation->uid.'/pay')->assertOk();
        $this->assertSame(1, Order::query()->count());

        app(OrderService::class)->markPaid($order, 'Verified', 1);
        $this->assertSame($user->id, (int) MembershipPeriod::query()->sole()->user_id);
        $this->actingAs($user)->postJson('/community/membership/'.$organisation->uid.'/pay')->assertStatus(422)->assertJsonPath('message', 'You have already paid this membership.');
    }
}
