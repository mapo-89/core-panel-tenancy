<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use CorePanel\Support\Administration\DatabaseBackups\DatabaseBackupSettings;
use CorePanel\Support\Settings\SettingsRepository;

final readonly class TenancyDatabaseBackupSettings
{
    public function __construct(
        private DatabaseBackupSettings $coreSettings,
        private SettingsRepository $settings,
    ) {}

    /**
     * @return array{
     *     automatic_enabled: bool,
     *     automatic_scope: string,
     *     cloud_backup_enabled: bool,
     *     cloud_backup_path: string,
     *     encryption_code: string,
     *     encryption_enabled: bool,
     *     retention_count: int,
     *     retention_days: int,
     *     retention_mode: string,
     *     schedule_mode: string,
     *     system_time: string,
     *     time: string,
     *     time_mode: string,
     *     timezone: string,
     *     weekdays: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            ...$this->coreSettings->toArray(),
            'automatic_scope' => $this->automaticScope(),
        ];
    }

    public function automaticScope(): string
    {
        $scope = $this->settings->get(DatabaseBackupSettings::GROUP, 'automatic_scope', 'full_set');

        return in_array($scope, ['central_only', 'full_set'], true)
            ? (string) $scope
            : 'full_set';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(array $payload): void
    {
        $this->coreSettings->update($payload);
        $this->settings->set(
            DatabaseBackupSettings::GROUP,
            'automatic_scope',
            (string) ($payload['automatic_scope'] ?? $this->automaticScope()),
            'string',
            false,
            false,
        );
    }
}
