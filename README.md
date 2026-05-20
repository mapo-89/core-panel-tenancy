# Laravel CorePanel Tenancy

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

## Local Package Development

For local development from the monorepo:

```bash
composer config repositories.core-panel '{"type":"path","url":"/home/manue/projects/packages/core-panel/packages/core-panel","options":{"symlink":true,"versions":{"mapo-89/core-panel":"dev-main"}}}'
composer config repositories.core-panel-tenancy '{"type":"path","url":"/home/manue/projects/packages/core-panel/packages/core-panel-tenancy","options":{"symlink":true,"versions":{"mapo-89/core-panel-tenancy":"dev-main"}}}'
composer require mapo-89/core-panel:dev-main mapo-89/core-panel-tenancy:dev-main
```

## License

CorePanel Tenancy is released under the [MIT license](./LICENSE.md).
