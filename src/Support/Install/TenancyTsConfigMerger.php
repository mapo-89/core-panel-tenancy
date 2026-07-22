<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;
use JsonException;

final readonly class TenancyTsConfigMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(?string $basePath = null): void
    {
        $path = ($basePath ?? base_path()).'/tsconfig.json';

        if (! $this->files->exists($path)) {
            return;
        }

        try {
            /** @var array<string, mixed> $config */
            $config = json_decode((string) $this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $paths = (array) data_get($config, 'compilerOptions.paths', []);
        if (isset($paths['@core-panel-tenancy/*'])) {
            return;
        }

        $paths['@core-panel-tenancy/*'] = ['./resources/js/*', './vendor/mapo-89/core-panel-tenancy/resources/js/*'];
        data_set($config, 'compilerOptions.paths', $paths);
        $this->files->put($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
