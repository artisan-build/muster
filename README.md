# Muster

Muster is self-hosted heartbeat and cron monitoring for scheduled tasks and background services.

## Who It Is For

Muster is for teams that want to operate heartbeat monitoring in their own Laravel Cloud account
without per-monitor pricing. It answers whether an expected task or service reported in on time; it
is not a metrics or log store.

## What It Does

- Provides authenticated monitor creation, listing, detail, editing, and enable/disable controls.
- Auto-registers a connected Laravel application's scheduled commands from their real expressions.
- Tracks distinct starts, finishes, non-zero exits, skips, and unfinished executions.
- Sends a weekly account-level digest of missed and failed commands.
- Reports the enabled-monitor count as its bounded Built for Cloud headline stat.

Install [`artisan-build/muster-client`](packages/muster-client/README.md) in a Laravel application to
connect its scheduler without adding per-command check-in calls.

## Built For Cloud

Muster is a [Built for Cloud catalog product](https://scalpels.app/products/muster). Its canonical
manifest is maintained in `config/built-for-cloud.php`, with `muster.check-in` declared as a
consumption purpose. The client authenticates schedule events with a package-issued bearer credential.
Exact scheduled command strings are sensitive stored data. Do not put credentials in command-line
arguments; Muster stores commands for identity and may show them in its authenticated UI and weekly
digest.

Built for Cloud provides the human authentication used by Muster's monitor routes through the
package-owned `bfc.auth` middleware.

## Local Development

```bash
composer setup
composer dev
```

Muster is nodeless by design. Static assets are committed under `public/`; do not add Node, npm,
Vite, or a frontend build step.

## Tests And Quality

```bash
php artisan test tests/Feature/MonitorManagementTest.php tests/Feature/MonitorHeadlineTest.php
composer ready
```

`composer ready` regenerates IDE helpers, applies Rector and Pint, runs PHPStan and Pest, and audits
Composer dependencies.

## Deployment

Muster is designed for Laravel Cloud. Provision resources in Cloud and let Cloud inject their
configuration; do not add environment values that shadow Cloud-managed resource variables. No
deployment environment or resource identifiers are committed to this repository.

Weekly failure digests require a durable queue connection and a continuously running queue worker in
production. In-process `sync`, `deferred`, `background`, and `null` queue drivers are not supported for
digest publication.

## Repository

[artisan-build/muster](https://github.com/artisan-build/muster)
