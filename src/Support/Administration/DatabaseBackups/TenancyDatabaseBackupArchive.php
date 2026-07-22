<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

final readonly class TenancyDatabaseBackupArchive
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public string $directory,
        public array $manifest,
    ) {}
}
