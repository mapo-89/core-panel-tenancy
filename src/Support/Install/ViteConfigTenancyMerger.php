<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class ViteConfigTenancyMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(?string $basePath = null): void
    {
        $root = $basePath ?? base_path();
        $viteConfigPath = $root.'/vite.config.ts';

        if (! $this->files->exists($viteConfigPath)) {
            return;
        }

        $contents = (string) $this->files->get($viteConfigPath);

        if (str_contains($contents, 'function resolveCorePanelTenancyImport(')) {
            return;
        }

        $packagePagesNeedle = "const packagePagesPath = path.resolve(\n";
        $corePanelResolverNeedle = "function resolveCorePanelImport(importee: string): string | null {\n";
        $pluginResolverNeedle = "            return resolveCorePanelImport(importee)\n";

        if (! str_contains($contents, $packagePagesNeedle)
            || ! str_contains($contents, $corePanelResolverNeedle)
            || ! str_contains($contents, $pluginResolverNeedle)) {
            return;
        }

        $updatedContents = str_replace(
            $packagePagesNeedle,
            self::tenancyPackagePath().$packagePagesNeedle,
            $contents,
        );
        $updatedContents = str_replace(
            $corePanelResolverNeedle,
            self::tenancyResolver().$corePanelResolverNeedle,
            $updatedContents,
        );
        $updatedContents = str_replace(
            $pluginResolverNeedle,
            self::pluginResolver(),
            $updatedContents,
        );

        $this->files->put($viteConfigPath, $updatedContents);
    }

    private static function tenancyPackagePath(): string
    {
        return <<<'TS'
const tenancyPackageJsPath = path.resolve(
    __dirname,
    'vendor/mapo-89/core-panel-tenancy/resources/js',
)

TS;
    }

    private static function tenancyResolver(): string
    {
        return <<<'TS'
function resolveCorePanelTenancyImport(importee: string): string | null {
    if (!importee.startsWith('@core-panel-tenancy/')) {
        return null
    }

    const relativePath = importee.replace('@core-panel-tenancy/', '')

    return resolveImportTarget(path.resolve(tenancyPackageJsPath, relativePath))
}

TS;
    }

    private static function pluginResolver(): string
    {
        return <<<'TS'
            return (
                resolveCorePanelTenancyImport(importee) ??
                resolveCorePanelImport(importee)
            )

TS;
    }
}
