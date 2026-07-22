<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CorePanelTenancyHostTest extends TestCase
{
    public function test_it_registers_the_tenancy_configuration_in_the_host_application(): void
    {
        $this->assertFileExists(base_path('config/tenancy.php'));
        $this->assertSame('pgsql', config('tenancy.database.central_connection'));
        $this->assertIsArray(config('tenancy.central_domains'));
    }

    public function test_it_registers_the_tenant_core_panel_routes(): void
    {
        $this->assertTrue(Route::has('tenant.core-panel.users.index'));
        $this->assertTrue(Route::has('tenant.impersonate'));
        $this->assertTrue(Route::has('tenant.leave-impersonation'));
    }
}
