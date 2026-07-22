<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use Illuminate\Support\Carbon;

final readonly class TenancyDatabaseBackupFile
{
    /**
     * @param  list<string>  $storageLocations
     * @param  list<array{id:string,label:string}>  $tenantOptions
     * @param  list<string>  $restoreScopes
     */
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public Carbon $createdAt,
        public bool $encrypted = false,
        public array $storageLocations = ['local'],
        public string $kind = 'legacy',
        public bool $containsTenants = false,
        public int $failedTenantsCount = 0,
        public array $tenantOptions = [],
        public array $restoreScopes = ['central_only'],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contains_tenants' => $this->containsTenants,
            'created_at' => $this->createdAt->toIso8601String(),
            'encrypted' => $this->encrypted,
            'failed_tenants_count' => $this->failedTenantsCount,
            'kind' => $this->kind,
            'name' => $this->name,
            'restore_scopes' => $this->restoreScopes,
            'size' => $this->size,
            'size_for_humans' => $this->sizeForHumans(),
            'source' => $this->source(),
            'storage_locations' => $this->storageLocations,
            'tenant_options' => $this->tenantOptions,
        ];
    }

    public function source(): string
    {
        foreach (['dump', 'zip'] as $extension) {
            if (str_ends_with($this->name, "-automatic.{$extension}") || str_ends_with($this->name, "-automatic.{$extension}.enc")) {
                return 'automatic';
            }

            if (str_ends_with($this->name, "-imported.{$extension}") || str_ends_with($this->name, "-imported.{$extension}.enc")) {
                return 'imported';
            }

            if (str_ends_with($this->name, "-manual.{$extension}") || str_ends_with($this->name, "-manual.{$extension}.enc")) {
                return 'manual';
            }
        }

        return 'custom';
    }

    private function sizeForHumans(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf(
            '%s %s',
            $unitIndex === 0 ? (string) (int) $size : number_format($size, 1),
            $units[$unitIndex],
        );
    }
}
