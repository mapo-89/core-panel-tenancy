<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanel\Support\Database\TimestampTzConverter;
use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Throwable;

final class ConvertTimestampsToTimestamptzCommand extends Command
{
    protected $signature = 'core-panel:convert-timestamps-tz
        {--central : Convert central CorePanel tables}
        {--tenancy : Convert tenancy metadata tables}
        {--tenant : Convert tenant CorePanel tables for all tenants}
        {--tenant-id=* : Restrict tenant conversion to specific tenant ids}
        {--database= : Database connection for central and tenancy datasets. Defaults to the configured central connection or the current default connection}
        {--dry-run : Only report columns that would be converted}
        {--force : Run without confirmation}';

    protected $description = 'Convert legacy PostgreSQL timestamp columns from local timestamps to UTC-based timestamptz columns.';

    /**
     * @var list<string>
     */
    protected $aliases = ['core-panel:convert-timestamps-to-timestamptz'];

    public function __construct(private readonly TenantModelResolver $models)
    {
        parent::__construct();
    }

    public function handle(TimestampTzConverter $converter): int
    {
        $targets = $this->targets();

        if ($targets === []) {
            $this->components->error('Choose at least one target: [--central], [--tenancy], or [--tenant].');

            return self::INVALID;
        }

        if (! $this->shouldProceed($targets)) {
            $this->components->warn('Timestamp conversion aborted.');

            return self::INVALID;
        }

        try {
            if (in_array('central', $targets, true)) {
                $this->renderSection('central', $converter->convertDataset('central', $this->databaseConnection(), $this->dryRun()));
            }

            if (in_array('tenancy', $targets, true)) {
                $this->renderSection('tenancy', $converter->convertDataset('tenancy', $this->databaseConnection(), $this->dryRun()));
            }

            if (in_array('tenant', $targets, true)) {
                $tenants = $this->tenants();

                if ($tenants->isEmpty()) {
                    $this->components->warn('No tenant records matched the requested selection.');

                    return self::SUCCESS;
                }

                foreach ($tenants as $tenant) {
                    tenancy()->initialize($tenant);

                    try {
                        $this->renderSection(
                            'tenant:'.(string) $tenant->getKey(),
                            $converter->convertDataset('tenant', null, $this->dryRun()),
                            $this->tenantLabel($tenant),
                        );
                    } finally {
                        tenancy()->end();
                    }
                }
            }
        } catch (Throwable $exception) {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            report($exception);
            $this->components->error($exception->getMessage() !== '' ? $exception->getMessage() : 'Timestamp conversion failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targets(): array
    {
        $targets = [];

        foreach (['central', 'tenancy', 'tenant'] as $target) {
            if ((bool) $this->option($target)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * @param  list<string>  $targets
     */
    private function shouldProceed(array $targets): bool
    {
        if ($this->dryRun() || (bool) $this->option('force')) {
            return true;
        }

        return $this->components->confirm(
            sprintf(
                'Convert legacy timestamp columns for [%s] using source timezone [%s] and store them as timestamptz instants? ',
                implode(', ', $targets),
                (string) config('core-panel.database.timestamp_tz_conversion.legacy_timezone', 'Europe/Berlin'),
            ),
            false,
        );
    }

    private function dryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    private function databaseConnection(): ?string
    {
        $database = $this->option('database');

        if (is_string($database) && trim($database) !== '') {
            return trim($database);
        }

        $centralConnection = config('tenancy.database.central_connection');

        if (is_string($centralConnection) && trim($centralConnection) !== '') {
            return trim($centralConnection);
        }

        $defaultConnection = config('database.default', 'pgsql');

        return is_string($defaultConnection) && trim($defaultConnection) !== ''
            ? trim($defaultConnection)
            : null;
    }

    /**
     * @return Collection<int, Model&TenantContract>
     */
    private function tenants()
    {
        $tenantModel = $this->models->tenantModelClass();
        $tenantIds = array_values(array_filter(
            array_map(
                static fn (mixed $tenantId): string => is_string($tenantId) ? trim($tenantId) : '',
                (array) $this->option('tenant-id'),
            ),
            static fn (string $tenantId): bool => $tenantId !== '',
        ));

        $query = $tenantModel::query()->orderBy('id');

        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        return new Collection(
            $query->get()
                ->map(fn (Model $tenant): Model => $this->requireTenantContract($tenant))
                ->all(),
        );
    }

    private function tenantLabel(Model $tenant): string
    {
        $displayName = $tenant->getAttribute('display_name');

        return is_string($displayName) && trim($displayName) !== ''
            ? trim($displayName)
            : (string) $tenant->getKey();
    }

    /**
     * @return Model&TenantContract
     */
    private function requireTenantContract(Model $tenant): Model
    {
        if (! $tenant instanceof TenantContract) {
            throw new \InvalidArgumentException('Configured tenant model must implement the tenancy tenant contract.');
        }

        return $tenant;
    }

    /**
     * @param  array{tables_scanned:int, columns_scanned:int, converted:list<array{table:string,column:string}>, pending:list<array{table:string,column:string}>, skipped:list<array{table:string,column:string,reason:string}>}  $result
     */
    private function renderSection(string $scope, array $result, ?string $label = null): void
    {
        $this->newLine();
        $this->components->info($label === null ? strtoupper($scope) : strtoupper($scope).' ['.$label.']');
        $this->components->twoColumnDetail('Tables scanned', (string) $result['tables_scanned']);
        $this->components->twoColumnDetail('Columns scanned', (string) $result['columns_scanned']);
        $this->components->twoColumnDetail(
            $this->dryRun() ? 'Would convert' : 'Converted',
            (string) count($this->dryRun() ? $result['pending'] : $result['converted']),
        );

        $rows = $this->dryRun() ? $result['pending'] : $result['converted'];

        if ($rows === []) {
            return;
        }

        $this->table(
            ['Table', 'Column'],
            array_map(
                static fn (array $row): array => [$row['table'], $row['column']],
                $rows,
            ),
        );
    }
}
