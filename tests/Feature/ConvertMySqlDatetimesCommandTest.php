<?php

declare(strict_types=1);

it('uses the configured central connection for MySQL central conversions by default', function (): void {
    $command = file_get_contents(__DIR__.'/../../src/Console/ConvertMySqlDatetimesCommand.php');

    expect($command)->not->toBeFalse()
        ->toContain("config('tenancy.database.central_connection')")
        ->toContain("\$options['--database'] = trim(\$database);");
});

it('uses separate MySQL datetime datasets for central, tenancy, and tenant conversions', function (): void {
    $command = file_get_contents(__DIR__.'/../../src/Console/ConvertMySqlDatetimesCommand.php');
    $provider = file_get_contents(__DIR__.'/../../src/CorePanelTenancyServiceProvider.php');

    expect($command)->not->toBeFalse()
        ->toContain("runCoreConverter(\$this->centralOptions(), 'central')")
        ->toContain("runCoreConverter(\$this->centralOptions(), 'tenancy')")
        ->toContain("runCoreConverter([], 'tenant')")
        ->and($provider)->not->toBeFalse()
        ->toContain("\$databaseConfig['mysql_datetime_conversion'] = \$mysqlConversionConfig;");
});
