<?php

declare(strict_types=1);

namespace CorePanelTenancy\Console;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Throwable;

final class ConvertMySqlDatetimesCommand extends Command
{
    protected $signature = 'core-panel:convert-mysql-datetimes
        {--central : Convert central CorePanel tables}
        {--tenancy : Convert tenancy metadata tables}
        {--tenant : Convert tenant CorePanel tables for all tenants}
        {--tenant-id=* : Restrict tenant conversion to specific tenant ids}
        {--database= : Database connection for central and tenancy datasets. Defaults to the configured central connection or the current default connection}
        {--from=UTC : Source timezone of the existing values}
        {--to= : Target timezone; defaults to APP_TIMEZONE}
        {--dry-run : Report affected values without changing data}
        {--force : Run without confirmation}';

    protected $description = 'Convert legacy MySQL datetime values for central, tenancy, and tenant databases.';

    public function __construct(private readonly TenantModelResolver $models)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $targets = array_values(array_filter(
            ['central', 'tenancy', 'tenant'],
            fn (string $target): bool => (bool) $this->option($target),
        ));

        if ($targets === []) {
            $this->components->error('Choose at least one target: [--central], [--tenancy], or [--tenant].');

            return self::INVALID;
        }

        try {
            if (in_array('central', $targets, true)) {
                $this->runCoreConverter($this->centralOptions(), 'central');
            }

            if (in_array('tenancy', $targets, true)) {
                $this->runCoreConverter($this->centralOptions(), 'tenancy');
            }

            if (in_array('tenant', $targets, true)) {
                foreach ($this->tenants() as $tenant) {
                    tenancy()->initialize($tenant);

                    try {
                        $this->components->info('Tenant ['.$this->tenantLabel($tenant).']');
                        $this->runCoreConverter([], 'tenant');
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
            $this->components->error($exception->getMessage() !== '' ? $exception->getMessage() : 'MySQL datetime conversion failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function centralOptions(): array
    {
        $options = [];
        $database = $this->option('database');

        if (! is_string($database) || trim($database) === '') {
            $database = config('tenancy.database.central_connection');
        }

        if (is_string($database) && trim($database) !== '') {
            $options['--database'] = trim($database);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runCoreConverter(array $options, string $dataset): void
    {
        $options['--dataset'] = $dataset;
        $options['--from'] = (string) $this->option('from');
        $options['--to'] = (string) $this->option('to');
        $options['--dry-run'] = (bool) $this->option('dry-run');
        $options['--force'] = (bool) $this->option('force');

        $exitCode = $this->call('core-panel:convert-mysql-datetimes-central', $options);

        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException('CorePanel MySQL datetime conversion failed.');
        }
    }

    /**
     * @return iterable<Model&TenantContract>
     */
    private function tenants(): iterable
    {
        $tenantIds = array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? trim($id) : '', (array) $this->option('tenant-id')),
            static fn (string $id): bool => $id !== '',
        ));
        $model = $this->models->tenantModelClass();
        $query = $model::query()->orderBy('id');

        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        foreach ($query->cursor() as $tenant) {
            if (! $tenant instanceof TenantContract) {
                throw new \InvalidArgumentException('Configured tenant model must implement the tenancy tenant contract.');
            }

            yield $tenant;
        }
    }

    private function tenantLabel(Model $tenant): string
    {
        $displayName = $tenant->getAttribute('display_name');

        return is_string($displayName) && trim($displayName) !== ''
            ? trim($displayName)
            : (string) $tenant->getKey();
    }
}
