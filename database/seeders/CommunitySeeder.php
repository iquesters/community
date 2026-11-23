<?php

namespace Iquesters\Community\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\DirectoryPresets;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Organisation\Models\Organisation;

class CommunitySeeder extends BaseSeeder
{
    protected string $moduleName = 'community';

    protected string $description = 'community module: member directory, approval, plans and tags';

    protected array $metas = [
        'module_icon' => 'fas fa-people-group',
        'module_sidebar_menu' => [
            [
                'icon' => 'fas fa-address-book',
                'label' => 'Members',
                'table_schema' => [
                    'slug' => 'community-member-table',
                    'name' => 'Member records',
                    'description' => 'The member directory',
                    'schema' => [
                        'entity' => 'member_records',
                        'create' => ['form' => 'community-member-record', 'label' => 'Add member'],
                        'dt-options' => [
                            'columns' => [
                                [
                                    'data' => 'name',
                                    'title' => 'Name',
                                    'visible' => true,
                                    'link' => true,
                                    'form-schema-uid' => 'community-member-record',
                                ],
                                ['data' => 'email', 'title' => 'Email', 'visible' => true],
                                ['data' => 'phone', 'title' => 'Phone', 'visible' => true],
                                ['data' => 'source', 'title' => 'Source', 'visible' => true],
                                ['data' => 'created_at', 'title' => 'Added', 'visible' => true],
                            ],
                            'options' => [
                                'pageLength' => 25,
                                'order' => [[4, 'desc']],
                                'responsive' => true,
                            ],
                        ],
                        'default_view_mode' => 'table',
                    ],
                ],
            ],
            [
                'icon' => 'fas fa-user-check',
                'label' => 'Applications',
                'route' => 'community.applications.index',
            ],
            [
                'icon' => 'fas fa-file-import',
                'label' => 'Bulk upload',
                'route' => 'community.members.import.index',
            ],
        ],
    ];

    protected array $permissions = [
        'view-member-records',
        'create-member-records',
        'edit-member-records',
        'delete-member-records',
        'import-member-records',
    ];

    protected array $entities = [
        'member_records' => [
            'fields' => [],
            'meta_fields' => [],
            'metas' => [],
        ],
    ];

    protected function seedCustom(): void
    {
        $this->seedDefaultOrganisation();
        $this->seedDirectoryForm();

        // The entity API saves the directory's meta fields (batch, section…) only when listed on the entity.
        DirectoryFields::syncEntity();
        $this->relaxDefaultedColumns();
    }

    /**
     * Single mode: members need an organisation, so create one (named after the
     * app) when none exists. Multiple mode: the admins create them.
     */
    private function seedDefaultOrganisation(): void
    {
        if (OrganisationResolver::isMultiple() || Organisation::query()->where('status', 'active')->exists()) {
            return;
        }

        $name = (string) config('app.name') ?: 'My organisation';
        Organisation::create([
            'uid' => (string) Str::ulid(),
            'name' => $name,
            'description' => "Default organisation of {$name} (created by community:seed)",
            'status' => 'active',
        ]);

        if (app()->runningInConsole()) {
            echo "✅ Default organisation '{$name}' created for community members.\n";
        }
    }

    /**
     * The directory form, from the configured preset, only when it doesn't exist:
     * after that it belongs to the admins (form builder), and a re-seed keeps their changes.
     */
    private function seedDirectoryForm(): void
    {
        $slug = DirectoryFields::formSlug();

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['id', 'schema']);
        if ($existing) {
            $this->addOrganisationField($existing);

            return;
        }

        $preset = (string) DirectoryFields::conf()->directory_preset;

        DB::table('form_schemas')->insert([
            'uid' => (string) Str::ulid(),
            'slug' => $slug,
            'name' => 'Member record',
            'description' => "Member directory fields ({$preset} preset)",
            'schema' => json_encode(DirectoryPresets::formSchema($preset), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Columns the form doesn't send but the database fills (source, status, the
     * audit columns): not required. Older foundation seeders mark every NOT NULL
     * column required, and the entity API then refused the form ("Source is required").
     */
    private function relaxDefaultedColumns(): void
    {
        $entity = DB::table('entities')->where('entity_name', 'member_records')->first(['id', 'fields']);
        $fields = $entity ? json_decode((string) $entity->fields, true) : null;
        if (! is_array($fields)) {
            return;
        }

        foreach (['id', 'uid', 'user_id', 'source', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at'] as $column) {
            if (isset($fields[$column])) {
                $fields[$column]['required'] = false;
            }
        }

        DB::table('entities')->where('id', $entity->id)->update([
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /** Forms seeded before the organisation select existed get it at the top (admin edits are kept). */
    private function addOrganisationField(object $form): void
    {
        $schema = json_decode((string) $form->schema, true) ?: [];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        $index = collect($fields)->search(fn ($f) => ($f['id'] ?? null) === 'organisation_id');

        if ($index === false) {
            $schema['fields'] = [DirectoryPresets::organisationField(), ...$fields];
        } elseif (($fields[$index]['optionsFrom']['url'] ?? null) === DirectoryPresets::ORGANISATIONS_URL
            && isset($fields[$index]['optionsFrom']['empty'])) {
            // The endpoint now gives the first option itself; a static one would show twice.
            unset($fields[$index]['optionsFrom']['empty']);
            $schema['fields'] = $fields;
        } else {
            return;
        }

        DB::table('form_schemas')->where('id', $form->id)->update([
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
}
