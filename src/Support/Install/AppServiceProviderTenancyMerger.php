<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class AppServiceProviderTenancyMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(): void
    {
        $appServiceProviderPath = app_path('Providers/AppServiceProvider.php');
        $stubPath = __DIR__.'/../../../stubs/app/Providers/AppServiceProvider.php';

        if (! $this->files->exists($appServiceProviderPath)) {
            $this->files->ensureDirectoryExists(dirname($appServiceProviderPath));
            $this->files->copy($stubPath, $appServiceProviderPath);

            return;
        }

        $contents = (string) $this->files->get($appServiceProviderPath);

        if (str_contains($contents, 'CorePanelTenancy\\Support\\Wayfinder\\WayfinderRouteUrlNormalizer')) {
            return;
        }

        $updatedContents = $contents;

        foreach ($this->requiredImports() as $import) {
            if (str_contains($updatedContents, $import)) {
                continue;
            }

            $updatedContents = preg_replace(
                '/^namespace\s+App\\\\Providers;\n/m',
                "namespace App\\Providers;\n\n".$import,
                $updatedContents,
                1,
            ) ?? $updatedContents;
        }

        $updatedContents = preg_replace(
            '/public function boot\(\): void\s*\{\n/',
            "public function boot(): void\n    {\n".$this->hookContents(),
            $updatedContents,
            1,
        ) ?? $updatedContents;

        if ($updatedContents === $contents) {
            return;
        }

        $this->files->put($appServiceProviderPath, $updatedContents);
    }

    /**
     * @return list<string>
     */
    private function requiredImports(): array
    {
        return [
            'use Illuminate\\Console\\Events\\CommandFinished;',
            'use Illuminate\\Support\\Facades\\Event;',
            'use Illuminate\\Support\\Facades\\Vite;',
        ];
    }

    private function hookContents(): string
    {
        return (string) $this->files->get(
            __DIR__.'/../../../stubs/merge/app-service-provider.tenancy-hook.stub',
        );
    }
}
