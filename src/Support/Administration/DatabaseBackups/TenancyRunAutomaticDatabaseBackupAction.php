<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class TenancyRunAutomaticDatabaseBackupAction
{
    public function __construct(
        private TenancyDatabaseBackupService $backups,
        private TenancyDatabaseBackupSettings $settings,
    ) {}

    /**
     * @return array{message:string,status:string}
     */
    public function execute(): array
    {
        if (! $this->backups->enabled()) {
            return ['message' => 'disabled', 'status' => 'skipped'];
        }

        $settings = $this->settings->toArray();

        if (! $settings['automatic_enabled']) {
            return ['message' => 'automatic backups disabled', 'status' => 'skipped'];
        }

        $slot = $this->currentScheduledSlot($settings);

        if (! $slot instanceof Carbon) {
            return ['message' => 'not scheduled backup time', 'status' => 'skipped'];
        }

        $slotKey = $this->slotCacheKey(
            $slot,
            $settings['timezone'],
        );

        return Cache::lock('core-panel:database-backups:auto', 3600)->block(1, function () use ($settings, $slotKey): array {
            if (Cache::has($slotKey)) {
                return ['message' => 'backup already created for scheduled slot', 'status' => 'skipped'];
            }

            $scope = $settings['automatic_scope'];

            if (! $this->backups->supportsBackupScope($scope)) {
                throw new RuntimeException("Automatic backup scope [{$scope}] is not supported.");
            }

            $backup = $this->backups->create('automatic', $scope);

            Cache::put($slotKey, $backup->name, now()->addDays(8));

            Log::info('Automatic tenancy database backup created.', [
                'name' => $backup->name,
                'scope' => $scope,
            ]);

            return [
                'message' => $backup->name,
                'status' => 'created',
            ];
        });
    }

    /**
     * @param  array{
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
     * }  $settings
     */
    private function currentScheduledSlot(array $settings): ?Carbon
    {
        $timezone = $settings['timezone'];
        $now = now($timezone);
        $scheduledTime = (
            $settings['time_mode'] === 'system'
                ? $settings['system_time']
                : $settings['time']
        );
        $slot = $this->timeToday($scheduledTime, $now);

        if ($slot->format('H:i') !== $now->format('H:i')) {
            return null;
        }

        if (
            $settings['schedule_mode'] === 'custom'
            && ! in_array(strtolower($now->englishDayOfWeek), $settings['weekdays'], true)
        ) {
            return null;
        }

        return $slot;
    }

    private function slotCacheKey(Carbon $slot, string $timezone): string
    {
        return sprintf(
            'core-panel:database-backups:auto:%s:%s',
            $timezone,
            $slot->format('Y-m-d-H-i'),
        );
    }

    private function timeToday(string $time, Carbon $now): Carbon
    {
        try {
            [$hour, $minute] = array_map('intval', explode(':', $time, 2));

            return $now->copy()->setTime($hour, $minute, 0);
        } catch (Throwable) {
            return $now->copy()->setTime(2, 0, 0);
        }
    }
}
