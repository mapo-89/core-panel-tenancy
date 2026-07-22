<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class InertiaPageResolverTenancyMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(?string $basePath = null): void
    {
        $root = $basePath ?? base_path();
        $appPath = $root.'/resources/js/app.ts';

        if (! $this->files->exists($appPath)) {
            return;
        }

        $contents = (string) $this->files->get($appPath);

        if (str_contains($contents, 'const tenancyPageModules = import.meta.glob')) {
            return;
        }

        $pageModulesNeedle = "const vendorPageModules = import.meta.glob<{ default: DefineComponent }>(\n";
        $pageResolverNeedle = "    const vendorPage = (\n";

        if (! str_contains($contents, $pageModulesNeedle)
            || ! str_contains($contents, $pageResolverNeedle)) {
            return;
        }

        $updatedContents = str_replace(
            $pageModulesNeedle,
            self::tenancyPageModules().$pageModulesNeedle,
            $contents,
        );
        $updatedContents = str_replace(
            $pageResolverNeedle,
            self::tenancyPageResolution().$pageResolverNeedle,
            $updatedContents,
        );

        $this->files->put($appPath, $updatedContents);
    }

    private static function tenancyPageModules(): string
    {
        return <<<'TS'
const tenancyPageModules = import.meta.glob<{ default: DefineComponent }>(
    '../../vendor/mapo-89/core-panel-tenancy/resources/js/pages/**/*.vue',
    { eager: true },
)

TS;
    }

    private static function tenancyPageResolution(): string
    {
        return <<<'TS'
    const tenancyPage = (
        tenancyPageModules[
            `../../vendor/mapo-89/core-panel-tenancy/resources/js/pages/${name}.vue`
        ] ??
        tenancyPageModules[
            `../../vendor/mapo-89/core-panel-tenancy/resources/js/pages/Admin/${name}.vue`
        ]
    )?.default

    if (tenancyPage !== undefined) {
        return tenancyPage
    }

TS;
    }
}
