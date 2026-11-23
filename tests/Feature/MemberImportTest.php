<?php

namespace Iquesters\Community\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Iquesters\Community\Models\MemberRecord;
use Iquesters\Community\Services\Import\MemberImportService;
use Iquesters\Community\Tests\TestCase;

/**
 * Bulk upload with the default (alumni) directory: name, email, phone, batch, section, roll number, house.
 */
class MemberImportTest extends TestCase
{
    private function service(): MemberImportService
    {
        return app(MemberImportService::class);
    }

    public function test_the_preview_checks_rows_and_finds_duplicates(): void
    {
        $organisation = $this->makeOrganisation();
        MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Old Record', 'email' => 'old@example.test']);

        $preview = $this->service()->preview($this->csv([
            'Full name,Email,Phone,Batch (passing year),Section,Notes',
            'Ann Roy,ANN@example.test,+91 98300-12345,1962,B,first',
            'Bob Sen,not-an-email,,1970,,',
            ',x@example.test,,1980,,',
            'Ann Roy,ann@example.test,,1962,B,again',
            'Someone,old@example.test,,1990,,',
            'No Batch,nb@example.test,,,,',
        ]), $organisation, 1);

        $statuses = array_column($preview['rows'], 'status');
        $this->assertSame([
            MemberImportService::STATUS_VALID,
            MemberImportService::STATUS_ERROR,          // bad email
            MemberImportService::STATUS_ERROR,          // no name
            MemberImportService::STATUS_DUPLICATE_IN_FILE,
            MemberImportService::STATUS_EXISTING,
            MemberImportService::STATUS_ERROR,          // batch is required
        ], $statuses);

        $this->assertSame('ann@example.test', $preview['rows'][0]['data']['email']);
        $this->assertSame('+919830012345', $preview['rows'][0]['data']['phone']);
        $this->assertSame(2, $preview['rows'][3]['duplicate_of_line']);
        $this->assertSame(['Notes'], $preview['unknown_columns']);
        $this->assertSame(['email'], $preview['rows'][4]['matches'][0]['keys']);
    }

    public function test_a_file_without_a_required_column_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch');

        $this->service()->preview($this->csv(['name,email', 'Ann Roy,ann@example.test']), null, 1);
    }

    public function test_import_creates_valid_rows_with_their_meta_fields(): void
    {
        $organisation = $this->makeOrganisation();
        $preview = $this->service()->preview($this->csv([
            'name;email;batch;roll_number',  // ";" and field ids work too
            'Ann Roy;ann@example.test;1962;12',
            'Bad;bad;1962;',
        ]), $organisation, 7);

        $result = $this->service()->import($preview['token'], 7);

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1], $result);
        $record = MemberRecord::where('email', 'ann@example.test')->firstOrFail();
        $this->assertSame($organisation->id, $record->organisation_id);
        $this->assertSame(MemberRecord::SOURCE_BULK_UPLOAD, $record->source);
        $this->assertSame(7, (int) $record->created_by);
        $this->assertSame(['batch' => '1962', 'roll_number' => '12'], $record->metaValues());
    }

    public function test_existing_records_are_skipped_or_updated(): void
    {
        $organisation = $this->makeOrganisation();
        $record = MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy', 'email' => 'ann@example.test']);
        $record->setMetaValue('batch', '1962');
        $file = ['name,email,batch,section', 'Ann Roy,ann@example.test,1962,C'];

        $skip = $this->service()->preview($this->csv($file), $organisation, 1);
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1, 'failed' => 0], $this->service()->import($skip['token'], 1));
        $this->assertNull($record->getMetaValue('section'));

        $update = $this->service()->preview($this->csv($file), $organisation, 1);
        $this->assertSame(1, $this->service()->import($update['token'], 1, MemberImportService::ON_DUPLICATE_UPDATE)['updated']);
        $this->assertSame('C', $record->getMetaValue('section'));
        $this->assertSame(1, MemberRecord::count());
    }

    public function test_a_preview_belongs_to_its_user_and_is_used_once(): void
    {
        $preview = $this->service()->preview($this->csv(['name,batch', 'Ann Roy,1962']), null, 1);

        $this->assertNull($this->service()->stored($preview['token'], 2));
        $this->service()->import($preview['token'], 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->import($preview['token'], 1);
    }

    public function test_the_upload_pages_are_admin_only(): void
    {
        $this->actingAs($this->makeUser('user'))->get('/community/members/import')->assertForbidden();

        $admin = $this->makeUser('super-admin');
        $this->actingAs($admin)->get('/community/members/import')->assertOk()->assertSee('Batch (passing year)');
        $this->get('/community/members/import/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_upload_preview_and_import_through_the_pages(): void
    {
        $this->makeOrganisation();
        $this->actingAs($this->makeUser('super-admin'));

        $file = UploadedFile::fake()->createWithContent('batch-1962.csv', "name,email,batch\nAnn Roy,ann@example.test,1962\n");
        $response = $this->post('/community/members/import/preview', ['file' => $file])->assertOk()->assertSee('1 new');

        $token = $response->viewData('preview')['token'];
        $this->post("/community/members/import/{$token}", ['on_duplicate' => 'skip'])
            ->assertRedirect('/community/members/import')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('member_records', ['email' => 'ann@example.test', 'source' => 'bulk_upload']);
    }

    public function test_the_api_previews_and_imports(): void
    {
        $organisation = $this->makeOrganisation();
        $this->actingAs($this->makeUser('super-admin'));

        // The directory form's organisation select.
        $this->getJson('/api/community/v1/organisations')->assertOk()
            ->assertJsonPath('response_schema.data', [
                ['value' => '', 'label' => 'Choose…'],
                ['value' => $organisation->id, 'label' => 'TTVians'],
            ]);

        $this->getJson('/api/community/v1/directory/fields')->assertOk()
            ->assertJsonPath('response_schema.data.organisation_mode', 'single')
            ->assertJsonPath('response_schema.data.match_keys', ['email', 'phone', 'batch+name']);

        $file = UploadedFile::fake()->createWithContent('a.csv', "name,batch\nAnn Roy,1962\n");
        $token = $this->postJson('/api/community/v1/member-records/import/preview', ['file' => $file])
            ->assertOk()->assertJsonPath('response_schema.data.summary.valid', 1)->json('response_schema.data.token');

        $this->postJson("/api/community/v1/member-records/import/{$token}")->assertOk()->assertJsonPath('response_schema.data.created', 1);
        $this->postJson("/api/community/v1/member-records/import/{$token}")->assertStatus(410);
    }

    public function test_an_upload_needs_an_organisation(): void
    {
        $this->actingAs($this->makeUser('super-admin'));

        $file = UploadedFile::fake()->createWithContent('a.csv', "name,batch\nAnn Roy,1962\n");
        $this->postJson('/api/community/v1/member-records/import/preview', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Create an organisation first (Organisations → New).');
    }

    public function test_records_are_listed_through_the_entity_api_by_organisation(): void
    {
        // Without organisation's scope resolver the entity API refuses tables with organisation_id.
        $this->seed(\Iquesters\Community\Database\Seeders\CommunitySeeder::class);
        $organisation = $this->makeOrganisation();
        MemberRecord::create(['organisation_id' => $organisation->id, 'name' => 'Ann Roy']);

        $this->actingAs($this->makeUser('super-admin'));
        $this->getJson('/api/foundation/v1/entity/list/member_records')->assertOk()
            ->assertJsonFragment(['name' => 'Ann Roy']);
    }

    public function test_the_directory_form_has_the_organisation_select_but_the_upload_does_not(): void
    {
        $this->seed(\Iquesters\Community\Database\Seeders\CommunitySeeder::class);

        $form = json_decode(\Illuminate\Support\Facades\DB::table('form_schemas')->where('slug', 'community-member-record')->value('schema'), true);
        $this->assertSame('organisation_id', $form['fields'][0]['id']);
        $this->assertNotContains('organisation_id', array_column(\Iquesters\Community\Support\DirectoryFields::all(), 'id'));
    }

    public function test_without_an_organisation_the_pages_explain_it(): void
    {
        $this->actingAs($this->makeUser('super-admin'));

        // Upload page: a notice instead of the form.
        $this->get('/community/members/import')->assertOk()
            ->assertSee('No organisation yet')
            ->assertDontSee('name="file"', false);

        // The form's organisation select: one option saying why.
        $this->getJson('/api/community/v1/organisations')->assertOk()
            ->assertJsonPath('response_schema.data.0.value', '')
            ->assertJsonPath('response_schema.data.0.label', 'No organisation yet. Create one first (Organisations → New)')
            ->assertJsonCount(1, 'response_schema.data');

        $this->makeOrganisation();
        $this->get('/community/members/import')->assertOk()->assertSee('name="file"', false);
    }

    public function test_the_seeder_creates_a_default_organisation_in_single_mode_only_when_none_exists(): void
    {
        config(['app.name' => 'TTVians Alumni']);
        $this->seed(\Iquesters\Community\Database\Seeders\CommunitySeeder::class);
        $this->assertSame(['TTVians Alumni'], \Iquesters\Organisation\Models\Organisation::pluck('name')->all());

        // Seeding again doesn't add another.
        $this->seed(\Iquesters\Community\Database\Seeders\CommunitySeeder::class);
        $this->assertSame(1, \Iquesters\Organisation\Models\Organisation::count());
    }
}
