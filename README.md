# Muster

Muster is self-hosted heartbeat and cron monitoring for scheduled tasks and background services.

## Who It Is For

Muster is for teams that want to operate heartbeat monitoring in their own Laravel Cloud account
without per-monitor pricing. It answers whether an expected task or service reported in on time; it
is not a metrics or log store.

## What It Does

- Provides authenticated monitor creation, listing, detail, editing, and enable/disable controls.
- Stores each monitor's expected cadence, grace period, enabled state, and current timing fields.
- Reports the enabled-monitor count as its bounded Built for Cloud headline stat.

Check-in ingestion, missed/recovered evaluation, event history, and alerts are not part of this
foundation slice yet.

## Built For Cloud

Muster is a [Built for Cloud catalog product](https://scalpels.app/products/muster). Its canonical
manifest is maintained in `config/built-for-cloud.php`, with `muster.check-in` declared as a
consumption purpose. The check-in endpoint and credential authorization are not implemented yet.

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
