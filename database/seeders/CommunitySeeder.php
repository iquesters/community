<?php

namespace Iquesters\Community\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Iquesters\Community\Support\ApplicationForm;
use Iquesters\Community\Support\ApplicationTable;
use Iquesters\Community\Support\CommunityForms;
use Iquesters\Community\Support\DecisionForm;
use Iquesters\Community\Support\DirectoryFields;
use Iquesters\Community\Support\DirectoryPresets;
use Iquesters\Community\Support\DirectoryTable;
use Iquesters\Community\Support\OrganisationResolver;
use Iquesters\Community\Support\SchoolForms;
use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Foundation\Models\Module;
use Iquesters\Organisation\Models\Organisation;
use Spatie\Permission\Models\Role;

class CommunitySeeder extends BaseSeeder
{
    protected string $moduleName = 'community';

    protected string $description = 'community module: member directory, approval, plans and tags';

    protected array $metas = [
        'module_icon' => 'fas fa-people-group',
        // The module's Dashboard: admins get counts and their pages; others their membership.
        'module_dashboard_view' => 'community::dashboard.module',
        'module_sidebar_menu' => [
            [
                'key' => 'membership',
                'icon' => 'fas fa-id-card',
                'label' => 'Membership',
                'route' => 'community.membership',
                // Apply and follow the application; the member's home once approved.
                'permission' => self::USE_PERMISSION,
            ],
            [
                'key' => 'directory',
                'icon' => 'fas fa-users',
                'label' => 'Directory',
                // Opens the directory table (a members-only list, see DirectoryTable).
                'route' => 'community.directory',
                // Approved members (the member role) and admins.
                'permission' => self::DIRECTORY_PERMISSION,
            ],
            [
                // "members": the key it always had, so installs rename the item instead of adding one.
                'key' => 'members',
                'icon' => 'fas fa-address-book',
                'label' => 'Member records',
                'permission' => self::MANAGE_PERMISSION,
                'table_schema' => [
                    'slug' => 'community-member-table',
                    'name' => 'Member records',
                    'description' => self::MEMBER_TABLE_DESCRIPTION,
                    'schema' => [
                        'entity' => 'member_records',
                        // Rows from community (limited to the organisations managed): the generic entity API is super-admin only.
                        'data_url' => '/community/members/data',
                        'create' => ['form' => 'community-member-record', 'label' => 'Add member'],
                        // The record: its details, and what they completed at the school.
                        'record' => self::MEMBER_RECORD_SECTIONS,
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
                                ['data' => 'school', 'title' => 'At school', 'visible' => true],
                                ['data' => 'has_login', 'title' => 'On the app', 'visible' => true],
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
                'key' => 'school-levels',
                'icon' => 'fas fa-graduation-cap',
                'label' => 'School levels',
                'route' => 'community.school-levels.index',
                'permission' => self::MANAGE_PERMISSION,
            ],
            [
                'icon' => 'fas fa-file-import',
                'label' => 'Bulk upload',
                'route' => 'community.members.import.index',
                'permission' => self::MANAGE_PERMISSION,
            ],
        ],
    ];

    /** Sees Members, Applications and Bulk upload (data is still limited to their organisations). */
    public const MANAGE_PERMISSION = 'manage-community';

    /** Sees Membership (apply, then the member's home). */
    public const USE_PERMISSION = 'use-community';

    /** Sees the member directory (given to the member role on approval). */
    public const DIRECTORY_PERMISSION = 'view-member-directory';

    /** Shown under the Member records title. */
    public const MEMBER_TABLE_DESCRIPTION = "Your organisation's register: everyone on record, with or without a login (e.g. from the school's lists), with contact details. Admins only.";

    /** Members table record sections (also written onto an existing table by seedCustom). */
    public const MEMBER_RECORD_SECTIONS = [
        ['form' => 'community-member-record', 'mode' => 'view', 'title' => 'Details'],
        ['html' => '/community/members/{uid}/school', 'title' => 'At school'],
    ];

    /** Who sees the Community module at all (organisation admins use the "admin" role). */
    public const MODULE_ROLES = ['super-admin', 'admin', 'user', 'member'];

    /**
     * The Applications item: the generic table (rows from community, see
     * ApplicationTable), with its status filters as tabs. Added in run(), once
     * the table's uid is known.
     */
    private function applicationsItem(string $tableUid): array
    {
        $tab = fn (string $key, string $icon, string $label, ?string $status) => array_filter([
            'key' => $key,
            'icon' => $icon,
            'label' => $label,
            'default-table-schema-uid' => $tableUid,
            'params' => $status ? ['status' => $status] : null,
        ]);

        return [
            'key' => 'applications',
            'icon' => 'fas fa-user-check',
            'label' => 'Applications',
            'default-table-schema-uid' => $tableUid,
            'permission' => self::MANAGE_PERMISSION,
            // "Waiting" (applied and on hold) opens the table itself, so no "own page" tab is added.
            'children' => [
                $tab('waiting', 'fas fa-hourglass-half', 'Waiting', null),
                $tab('on-hold', 'fas fa-circle-pause', 'On hold', 'on_hold'),
                $tab('approved', 'fas fa-circle-check', 'Approved', 'approved'),
                $tab('rejected', 'fas fa-circle-xmark', 'Rejected', 'rejected'),
                $tab('all', 'fas fa-list', 'All', 'all'),
            ],
        ];
    }

    public function run(): void
    {
        // The member directory table: its sidebar item opens it directly, so the
        // sidebar marks it on the list page (/community/directory still redirects).
        $directoryUid = DirectoryTable::sync();
        if ($directoryUid) {
            $this->metas['module_sidebar_menu'] = array_map(function (array $item) use ($directoryUid) {
                if (($item['key'] ?? null) === 'directory') {
                    unset($item['route']);
                    $item['default-table-schema-uid'] = $directoryUid;
                }

                return $item;
            }, $this->metas['module_sidebar_menu']);
        }

        // The Applications table first: the sidebar item and its tabs link to it.
        $tableUid = ApplicationTable::sync();
        if ($tableUid) {
            $menu = $this->metas['module_sidebar_menu'];
            // After Membership, Directory and Members.
            array_splice($menu, 3, 0, [$this->applicationsItem($tableUid)]);
            $this->metas['module_sidebar_menu'] = $menu;
        }

        parent::run();
    }

    protected array $permissions = [
        self::MANAGE_PERMISSION,
        self::USE_PERMISSION,
        self::DIRECTORY_PERMISSION,
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
        // The role approval gives (conf member_role), so admins can use it before the first approval.
        $memberRole = trim((string) DirectoryFields::conf()->member_role);
        if ($memberRole !== '') {
            Role::findOrCreate($memberRole, 'web');
        }

        $this->seedAccess();
        $this->seedDefaultOrganisation();
        $this->seedDirectoryForm();

        // The entity API saves the directory's meta fields (batch, section…) only when listed on the entity.
        DirectoryFields::syncEntity();
        // The application form follows the directory and proof settings: rebuilt on every seed.
        ApplicationForm::sync();
        CommunityForms::syncAll();
        SchoolForms::syncAll();
        $this->syncMemberTableSections();
        DecisionForm::sync();
        $this->relaxDefaultedColumns();
    }

    /** Existing installs: the Members table gets its record sections and data source, the directory form its endpoints (admins' columns and fields stay). */
    private function syncMemberTableSections(): void
    {
        $form = DB::table('form_schemas')->where('slug', 'community-member-record')->first(['id', 'schema']);
        $formSchema = $form ? json_decode((string) $form->schema, true) : null;
        if (is_array($formSchema)) {
            $formSchema['modes'] = DirectoryPresets::MODES;
            DB::table('form_schemas')->where('id', $form->id)->update([
                'schema' => json_encode($formSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }

        $row = DB::table('table_schemas')->where('slug', 'community-member-table')->first(['id', 'schema']);
        $schema = $row ? json_decode((string) $row->schema, true) : null;
        if (! is_array($schema)) {
            return;
        }
        $schema['record'] = self::MEMBER_RECORD_SECTIONS;
        $schema['data_url'] = '/community/members/data';
        DB::table('table_schemas')->where('id', $row->id)->update([
            'description' => self::MEMBER_TABLE_DESCRIPTION,
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /**
     * Who sees what: the module for super-admin, admin (organisation admins),
     * user and member; "Membership" for everyone of them; the admin pages for
     * super-admin (all permissions) and admin. Which organisations an admin
     * manages is decided per request (CommunityAccess), not by these roles.
     */
    private function seedAccess(): void
    {
        $roles = collect(self::MODULE_ROLES)->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);

        $roles['admin']->givePermissionTo([self::MANAGE_PERMISSION, self::USE_PERMISSION, self::DIRECTORY_PERMISSION]);
        $roles['user']->givePermissionTo(self::USE_PERMISSION);
        $roles['member']->givePermissionTo([self::USE_PERMISSION, self::DIRECTORY_PERMISSION]);
        // Super-admin gets every module permission (BaseSeeder); it manages, it isn't a member.
        // Super-admin isn't a member (no Membership), but sees every organisation's Directory.
        $roles['super-admin']->revokePermissionTo(self::USE_PERMISSION);
        $roles['super-admin']->givePermissionTo(self::DIRECTORY_PERMISSION);

        $module = Module::where('name', $this->moduleName)->first();
        if ($module) {
            $module->assignRoles(collect($roles)->pluck('id')->all());
        }
    }

    /**
     * Single mode: members need an organisation, so create one (named by the
     * conf's default_organisation_name, default "iq-test-org") when none exists. Multiple mode: the admins create them.
     */
    private function seedDefaultOrganisation(): void
    {
        if (OrganisationResolver::isMultiple() || Organisation::query()->where('status', 'active')->exists()) {
            return;
        }

        $name = trim((string) DirectoryFields::conf()->default_organisation_name) ?: 'iq-test-org';
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
