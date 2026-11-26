<?php

namespace Iquesters\Community\Tests\Feature;

use Iquesters\Community\Enums\Module;
use Iquesters\Community\Support\CommunityForms;
use Iquesters\Community\Tests\TestCase;
use Iquesters\Foundation\Support\ConfProvider;

/**
 * Multiple-organisation mode (the alumni app: one organisation per school).
 * Regression: the Membership page crashed (500) before a school was chosen:
 * the organisation choice form inherited the page's $formData array.
 */
class MultipleOrganisationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CommunityForms::syncAll();
        ConfProvider::from(Module::COMMUNITY)->update(['organisation_mode' => 'multiple']);
        ConfProvider::forget();
    }

    public function test_the_membership_page_asks_for_the_school_first_then_shows_its_form(): void
    {
        $ttv = $this->makeOrganisation('TTV Alumni');
        $this->makeOrganisation('Second School');
        $this->actingAs($this->makeUser('user'));

        $this->get('/community/membership')->assertOk()
            ->assertSee('id="'.CommunityForms::ORGANISATION_CHOICE.'"', false);

        $this->getJson('/community/membership/organisations')->assertOk()->assertJsonCount(3, 'data');

        $this->get('/community/membership?organisation='.$ttv->uid)->assertOk()
            ->assertSee('Apply to TTV Alumni')
            ->assertSee('id="community-membership-application-'.strtolower($ttv->uid).'"', false);
    }
}
