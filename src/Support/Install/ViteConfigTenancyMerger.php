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

        $contents = preg_replace(
            '/(\bpath\.(?:resolve|join|relative)\(\s*)__dirname\b/',
            '$1import.meta.dirname',
            $contents,
        ) ?? $contents;
        $contents = $this->removeObsoleteDirnameCompatibility($contents);
        $contents = $this->mergeLanguagePath($contents);

        if (str_contains($contents, 'function resolveCorePanelTenancyImport(')) {
            $this->files->put($viteConfigPath, $contents);

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

    private function removeObsoleteDirnameCompatibility(string $contents): string
    {
        $withoutDeclaration = preg_replace(
            '/^[ \t]*const\s+__dirname\s*=\s*dirname\(\s*fileURLToPath\(\s*import\.meta\.url\s*\)\s*\)\s*;?[ \t]*\R?/m',
            '',
            $contents,
            1,
            $replacements,
        ) ?? $contents;

        $usesFilenameCompatibility = false;

        if ($replacements !== 1) {
            $withoutDeclaration = preg_replace(
                '/^[ \t]*const\s+__dirname\s*=\s*dirname\(\s*__filename\s*\)\s*;?[ \t]*\R?/m',
                '',
                $contents,
                1,
                $replacements,
            ) ?? $contents;
            $usesFilenameCompatibility = $replacements === 1;
        }

        if ($replacements !== 1 || preg_match('/\b__dirname\b/', $withoutDeclaration) === 1) {
            return $contents;
        }

        if ($usesFilenameCompatibility
            && preg_match_all('/\b__filename\b/', $withoutDeclaration) === 1) {
            $withoutDeclaration = preg_replace(
                '/^[ \t]*const\s+__filename\s*=\s*fileURLToPath\(\s*import\.meta\.url\s*\)\s*;?[ \t]*\R?/m',
                '',
                $withoutDeclaration,
                1,
            ) ?? $withoutDeclaration;
        }

        foreach (['node:path', 'path'] as $pathSource) {
            $withoutDeclaration = $this->removeUnusedNamedImport($withoutDeclaration, $pathSource, 'dirname');
        }

        foreach (['node:url', 'url'] as $urlSource) {
            $withoutDeclaration = $this->removeUnusedNamedImport($withoutDeclaration, $urlSource, 'fileURLToPath');
        }

        return $withoutDeclaration;
    }

    private function removeUnusedNamedImport(string $contents, string $source, string $identifier): string
    {
        if (preg_match_all('/(?<![A-Za-z0-9_$.])'.preg_quote($identifier, '/').'\b/', $contents) !== 1) {
            return $contents;
        }

        $pattern = '/^(?<indent>[ \t]*)import\s+(?:(?<default>[A-Za-z_$][A-Za-z0-9_$]*)\s*,\s*)?'
            .'\{\s*(?<named>[^}]*)\s*\}\s+from\s+(?<quote>[\'\"])'.preg_quote($source, '/').'\k<quote>\s*;?[ \t]*(?<newline>\R|$)/m';

        return preg_replace_callback($pattern, static function (array $matches) use ($identifier, $source): string {
            $namedImports = array_values(array_filter(
                array_map('trim', explode(',', $matches['named'])),
                static fn (string $namedImport): bool => $namedImport !== $identifier,
            ));

            if (count($namedImports) === count(array_filter(array_map('trim', explode(',', $matches['named']))))) {
                return $matches[0];
            }

            $defaultImport = $matches['default'] ?? '';

            if ($namedImports === []) {
                if ($defaultImport === '') {
                    return '';
                }

                return $matches['indent'].'import '.$defaultImport.' from '.$matches['quote']
                    .$source.$matches['quote'].$matches['newline'];
            }

            return $matches['indent'].'import '
                .($defaultImport !== '' ? $defaultImport.', ' : '')
                .'{ '.implode(', ', $namedImports).' } from '.$matches['quote']
                .$source.$matches['quote'].$matches['newline'];
        }, $contents, 1) ?? $contents;
    }

    private function mergeLanguagePath(string $contents): string
    {
        $tenancyLanguagePath = <<<'TS'
    path.resolve(
        import.meta.dirname,
        'vendor/mapo-89/core-panel-tenancy/resources/lang',
    ),
TS;
        $tenancyLanguagePath .= "\n";

        if (str_contains($contents, "'vendor/mapo-89/core-panel-tenancy/resources/lang'")) {
            return $contents;
        }

        $corePanelLanguagePaths = [
            <<<'TS'
    path.resolve(
        import.meta.dirname,
        'vendor/mapo-89/core-panel/resources/lang',
    ),
TS."\n",
            "    path.resolve(import.meta.dirname, 'vendor/mapo-89/core-panel/resources/lang'),\n",
        ];

        foreach ($corePanelLanguagePaths as $corePanelLanguagePath) {
            if (str_contains($contents, $corePanelLanguagePath)) {
                return str_replace(
                    $corePanelLanguagePath,
                    $corePanelLanguagePath.$tenancyLanguagePath,
                    $contents,
                );
            }
        }

        return $contents;
    }

    private static function tenancyPackagePath(): string
    {
        return <<<'TS'
const tenancyPackageJsPath = path.resolve(
    import.meta.dirname,
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
