<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Install;

use CorePanel\Support\Publishing\PublishedAssetManifest;
use Illuminate\Filesystem\Filesystem;

final readonly class TenancyAdministrationPageMigrator
{
    private const RELATIVE_PATH = 'resources/js/pages/Admin/Administration/Index.vue';

    public function __construct(
        private Filesystem $files,
        private PublishedAssetManifest $manifest,
    ) {}

    /**
     * @return array{tag:string,status:string,source:string,destination:string,reason:string}
     */
    public function migrate(?string $basePath = null, bool $dryRun = false): array
    {
        $root = rtrim($basePath ?? base_path(), '/');
        $destination = $root.'/'.self::RELATIVE_PATH;

        if (! $this->files->exists($destination)) {
            return $this->change('pruned', $destination, 'no legacy CorePanel administration page is present');
        }

        $publishedAssets = $this->manifest->read($root);
        $entry = $publishedAssets['files'][$destination] ?? null;

        if (! $this->isManagedCoreAdministrationPage($entry, $destination)) {
            return $this->change('kept', $destination, 'host administration page is not an unchanged CorePanel publish');
        }

        if ($dryRun) {
            return $this->change('delete', $destination, 'unchanged CorePanel page will be removed so the tenancy vendor page resolves');
        }

        $this->files->delete($destination);
        unset($publishedAssets['files'][$destination]);
        $this->manifest->write($root, $publishedAssets);

        return $this->change('delete', $destination, 'removed unchanged CorePanel page so the tenancy vendor page resolves');
    }

    private function isManagedCoreAdministrationPage(mixed $entry, string $destination): bool
    {
        if (! is_array($entry) || ($entry['tag'] ?? null) !== 'core-panel-components') {
            return false;
        }

        $source = str_replace('\\', '/', (string) ($entry['source'] ?? ''));

        return str_ends_with($source, '/'.self::RELATIVE_PATH)
            && hash_equals((string) ($entry['destination_hash'] ?? ''), md5((string) $this->files->get($destination)));
    }

    /**
     * @return array{tag:string,status:string,source:string,destination:string,reason:string}
     */
    private function change(string $status, string $destination, string $reason): array
    {
        return [
            'tag' => 'core-panel-components',
            'status' => $status,
            'source' => self::RELATIVE_PATH,
            'destination' => $destination,
            'reason' => $reason,
        ];
    }
}
