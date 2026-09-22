# Laravel CorePanel Tenancy

[![Latest Stable Version](https://img.shields.io/packagist/v/mapo-89/core-panel-tenancy.svg)](https://packagist.org/packages/mapo-89/core-panel-tenancy)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/mapo-89/core-panel-tenancy.svg)](https://packagist.org/packages/mapo-89/core-panel-tenancy)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20.svg?logo=laravel&logoColor=white)](https://laravel.com/)
[![GitHub stars](https://img.shields.io/github/stars/mapo-89/core-panel-tenancy.svg?style=flat)](https://github.com/mapo-89/core-panel-tenancy/stargazers)
[![Last commit](https://img.shields.io/github/last-commit/mapo-89/core-panel-tenancy.svg)](https://github.com/mapo-89/core-panel-tenancy/commits/main)

<a href="https://buymeacoffee.com/mapo"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me a Coffee" height="60"></a>

`mapo-89/core-panel-tenancy` is the optional `stancl/tenancy` addon for Laravel CorePanel.

> Read-only split repository: this package repository is automatically synchronized from `mapo-89/core-panel-monorepo`.
> Do not open pull requests or make direct changes here. All development happens in the monorepo.

The tenancy addon extends the core package with tenant-aware routes, settings, migrations, assets, and user workflows while keeping the core package tenancy-neutral.

## Install

Existing Laravel app with CorePanel already installed:

```bash
composer require mapo-89/core-panel-tenancy
php artisan core-panel-tenancy:install
```

If you install through the CorePanel installer, the addon can also be pulled in during `php artisan core-panel:install`.

## Timestamp Conversion

When the tenancy addon is installed, the CorePanel timestamp conversion command also supports tenancy metadata tables and tenant databases:

```bash
php artisan core-panel:convert-timestamps-tz --tenancy --dry-run
php artisan core-panel:convert-timestamps-tz --tenant --dry-run
php artisan core-panel:convert-timestamps-tz --central --tenancy --tenant --force
```

Host applications can extend the conversion lists for addon-specific or project-specific tables in `config/core-panel.php` under:

- `core-panel.database.timestamp_tz_conversion.datasets.tenancy`
- `core-panel.database.timestamp_tz_conversion.datasets.tenant`

The conversion uses the configured source timezone from `core-panel.database.timestamp_tz_conversion.legacy_timezone` and converts directly to `timestamptz`, so the stored instant does not depend on the PostgreSQL session timezone.

## Update

### Upgrading To 1.6.0 (Breaking)

CorePanel Tenancy 1.6.0 must be updated together with CorePanel 1.6.0 because central and tenant migration ownership, generated route handling, and package/host composition change.

Back up the central and tenant databases and commit or back up the host application before running:

```bash
composer update mapo-89/core-panel mapo-89/core-panel-tenancy
php artisan core-panel:update --force --breaking-changes --with-addon-updates
npm install
php artisan wayfinder:generate --no-interaction
npm run build
php artisan optimize:clear
```

The addon now owns its central migrations and tenant-specific package overrides. Existing migration ledger entries remain valid because migration basenames are preserved. Recognized unchanged host copies are backed up and removed; locally modified migrations remain in the host as conflicts and require manual review. A preserved migration under `database/migrations/tenant` overrides a package migration with the same basename, while unrelated custom tenant migration paths remain configured.

After updating, verify the central login, tenant provisioning, `tenants:migrate`, tenant authentication logs, generated central and tenant Wayfinder routes, and the frontend build. Use `--breaking-changes` only for this one-time 1.6.0 transition. Later 1.6.x updates use the normal commands below.

Update the addon inside an installed application:

```bash
composer update mapo-89/core-panel-tenancy
php artisan core-panel:tenancy:update --force
```

If you usually update CorePanel and the addon together, prefer:

```bash
composer update mapo-89/core-panel mapo-89/core-panel-tenancy
php artisan core-panel:update --force --with-addon-updates
```

That path refreshes core and addon assets first and then runs the host application's outstanding migrations once.

Typical update runbook for an existing installation with the addon:

```bash
composer update mapo-89/core-panel mapo-89/core-panel-tenancy
php artisan core-panel:update --force --with-addon-updates
npm install
npm run build
php artisan optimize:clear
```

If generated assets such as `resources/js/actions`, `resources/js/routes`, `resources/js/wayfinder`, `public/build`, or `public/hot` were previously committed, remove them from the Git index once after adopting the new `.gitignore`:

```bash
git rm -r --cached -- resources/js/actions resources/js/routes resources/js/wayfinder public/build public/hot
```

## Local Package Development

For local development from the monorepo:

```bash
composer config repositories.core-panel '{"type":"path","url":"/home/manue/projects/packages/core-panel/packages/core-panel","options":{"symlink":true,"versions":{"mapo-89/core-panel":"dev-main"}}}'
composer config repositories.core-panel-tenancy '{"type":"path","url":"/home/manue/projects/packages/core-panel/packages/core-panel-tenancy","options":{"symlink":true,"versions":{"mapo-89/core-panel-tenancy":"dev-main"}}}'
composer require mapo-89/core-panel:dev-main mapo-89/core-panel-tenancy:dev-main
```

## License

CorePanel Tenancy is released under the [MIT license](./LICENSE.md).
