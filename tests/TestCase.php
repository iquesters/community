<?php

namespace Iquesters\Community\Tests;

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\ServiceProvider as BreadcrumbsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Iquesters\Commerce\CommerceServiceProvider;
use Iquesters\Community\CommunityServiceProvider;
use Iquesters\Community\Tests\Fixtures\User;
use Iquesters\Foundation\FoundationServiceProvider;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\OrganisationServiceProvider;
use Iquesters\UserInterface\UserInterfaceServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are cached before the test's transaction starts: start clean.
        Cache::flush();
        ConfProvider::forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            SanctumServiceProvider::class,
            BreadcrumbsServiceProvider::class,
            FoundationServiceProvider::class,
            UserInterfaceServiceProvider::class,
            OrganisationServiceProvider::class,
            CommerceServiceProvider::class,
            CommunityServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Breadcrumbs' => Breadcrumbs::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function makeUser(?string $role = null): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(6),
            'status' => 'active',
            'email' => Str::random(10).'@example.test',
            'password' => bcrypt(Str::random(20)),
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    protected function makeOrganisation(string $name = 'TTVians'): Organisation
    {
        return Organisation::create([
            'uid' => (string) Str::ulid(),
            'name' => $name,
            'description' => "{$name} organisation",
            'status' => 'active',
        ]);
    }

    /** A CSV file on disk with the given lines. */
    protected function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'community-test-').'.csv';
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }
}
