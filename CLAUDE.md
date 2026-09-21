# Muster

Muster is a self-hosted heartbeat and cron monitor for teams running scheduled tasks and background
services. It records an expected reporting cadence and makes late or missing reports visible without
per-monitor pricing. It is a single-tenant Laravel Cloud product in the Built for Cloud catalog.

Muster is not a metrics or log store. MVP does not include public status pages, escalation policies,
on-call schedules, paging integrations, automatic fleet registration, or a client package.

## Workflow

See `.solo/workflow.md` for this repository's merge policy, hard gate, CI, and coordination details.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No Frontend Build Step

Non-negotiable: **no Node, no npm, no Vite, no frontend build step.**

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. A class absent
from that bundle silently does nothing. To use new classes, delete the existing bundle, run
`php artisan tailwind:optimize`, verify the classes landed, and commit the regenerated file.

## Built For Cloud

The canonical app manifest is maintained in `config/built-for-cloud.php` through
`php artisan bfc:starter:configure`. It declares `muster.check-in` as a consumption purpose and
enables the package's human-management, session, and credential affordances. Muster uses the
package's human authentication middleware; credential authorization and check-in ingestion arrive
in a later slice. Verify the configured manifest with
`php .claude/skills/bfc-app-manifest/scripts/manifest.php --check --json`.

Laravel Cloud provisions resource configuration. Do not commit secrets, app IDs, personal config,
machine-specific paths, or hand-written values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose because PHPStan scans the
model helper. `.phpstorm.meta.php` is gitignored because it embeds absolute local paths. Do not make
these files consistent in either direction.

## Static Analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.
