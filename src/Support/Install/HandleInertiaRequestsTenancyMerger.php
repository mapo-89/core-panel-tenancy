<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class HandleInertiaRequestsTenancyMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(?string $basePath = null): void
    {
        $root = $basePath ?? base_path();
        $middlewarePath = $root.'/app/Http/Middleware/HandleInertiaRequests.php';

        if (! $this->files->exists($middlewarePath)) {
            return;
        }

        $contents = (string) $this->files->get($middlewarePath);

        if (str_contains($contents, 'tenant_switcher')) {
            return;
        }

        $updatedContents = $contents;

        if (! str_contains($updatedContents, 'use CorePanelTenancy\\Support\\Tenancy\\TenantSwitcher;')) {
            $updatedContents = preg_replace(
                '/^use Inertia\\\\Middleware;\n/m',
                "use Inertia\\Middleware;\nuse CorePanelTenancy\\Support\\Tenancy\\TenantSwitcher;\n",
                $updatedContents,
                1,
            ) ?? $updatedContents;
        }

        $updatedContents = preg_replace(
            "/\n\s+'locale' => \[/m",
            "\n            'navigation' => [\n                'tenant_switcher' => fn (): ?array => app(\\CorePanelTenancy\\Support\\Tenancy\\TenantSwitcher::class)->forRequest(\$request),\n            ],\n            'locale' => [",
            $updatedContents,
            1,
        ) ?? $updatedContents;

        if ($updatedContents === $contents) {
            return;
        }

        $this->files->put($middlewarePath, $updatedContents);
    }
}
