<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use Illuminate\Filesystem\Filesystem;

final readonly class CorePanelTypesTenancyMerger
{
    public function __construct(private Filesystem $files) {}

    public function merge(): void
    {
        $typesPath = resource_path('js/types/core-panel.ts');

        if (! $this->files->exists($typesPath)) {
            return;
        }

        $contents = (string) $this->files->get($typesPath);

        if (str_contains($contents, 'export type CorePanelTenancyContext = {')) {
            return;
        }

        $updatedContents = preg_replace(
            '/export type CorePanelUploadConfig = \{/m',
            $this->typeContents()."\n\nexport type CorePanelUploadConfig = {",
            $contents,
            1,
        );

        if (! is_string($updatedContents) || $updatedContents === $contents) {
            return;
        }

        $this->files->put($typesPath, $updatedContents);
    }

    private function typeContents(): string
    {
        return trim((string) $this->files->get(
            __DIR__.'/../../../stubs/merge/core-panel-tenancy-context.stub',
        ));
    }
}
