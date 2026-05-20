<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Settings;

use CorePanel\Contracts\SettingsLogoUrlGenerator;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Tenancy;

final class TenantAwareSettingsLogoUrlGenerator implements SettingsLogoUrlGenerator
{
    public function generate(string $path): string
    {
        if (
            app()->bound(Tenancy::class)
            && app(Tenancy::class)->initialized
            && Route::has('stancl.tenancy.asset')
        ) {
            return route('stancl.tenancy.asset', ['path' => $path]);
        }

        return asset('storage/'.$path);
    }
}
