<?php

declare(strict_types=1);

use CorePanel\Contracts\SystemUpdateSettingsAccess;
use CorePanel\Support\Administration\SystemUpdates\SystemUpdateSettingsPayload;
use CorePanel\Tests\FakeUser;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Tenancy;

beforeEach(function (): void {
    if (! corePanelTestbenchDatabaseAvailable()) {
        $this->markTestSkipped('pdo_sqlite is not available in this environment.');
    }

    $this->migrateScaffoldDatabase();
    config()->set('core-panel.administration.system_updates.docker_only', false);
    config()->set('core-panel.administration.system_updates.enabled', true);
    config()->set('core-panel.administration.system_updates.token', 'secret-token');
    config()->set('core-panel.administration.system_updates.updater_url', 'http://system-updater:8080');
});

afterEach(function (): void {
    app(Tenancy::class)->initialized = false;
});

function tenantSystemUpdateAdministrator(): FakeUser
{
    $user = FakeUser::query()->create([
        'email' => 'tenant-updates@example.test',
        'email_verified_at' => now(),
        'first_name' => 'Tenant',
        'last_name' => 'Administrator',
        'password' => Hash::make('password'),
    ]);

    $user->givePermissionTo(Permission::findOrCreate('system-updates.update', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('hides global automatic update settings while tenancy is initialized', function (): void {
    $user = tenantSystemUpdateAdministrator();
    $tenancy = app(Tenancy::class);
    $tenancy->initialized = true;
    app()->instance(Tenancy::class, $tenancy);

    expect(app(SystemUpdateSettingsAccess::class)->allows())->toBeFalse()
        ->and(app(SystemUpdateSettingsPayload::class)->forUser($user))->toBeNull();
});

it('forbids tenant administrators from changing global automatic update settings', function (): void {
    $tenancy = app(Tenancy::class);
    $tenancy->initialized = true;
    app()->instance(Tenancy::class, $tenancy);

    $this->actingAs(tenantSystemUpdateAdministrator())
        ->put(route('core-panel.system-updates.settings.update'), [
            'automatic_enabled' => true,
            'interval' => 'daily',
            'maintenance_window_enabled' => false,
            'mode' => 'check',
            'time' => '02:00',
            'weekday' => null,
            'window_end' => null,
            'window_start' => null,
        ])
        ->assertForbidden();
});
