<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (function_exists('global_asset')) {
            Vite::createAssetPathsUsing(
                static fn (string $path, ?bool $secure): string => global_asset($path),
            );
        }

        $wayfinderRouteUrlNormalizer = 'CorePanelTenancy\\Support\\Wayfinder\\WayfinderRouteUrlNormalizer';

        Event::listen(static function (CommandFinished $event) use ($wayfinderRouteUrlNormalizer): void {
            if (
                $event->command !== 'wayfinder:generate'
                || ! class_exists($wayfinderRouteUrlNormalizer)
            ) {
                return;
            }

            app($wayfinderRouteUrlNormalizer)->normalize();
        });
    }
}
