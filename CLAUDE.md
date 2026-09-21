# {{FILL: app name}}

> **Built for Cloud app scaffold.** This app was created with
> `artisan-build/built-for-cloud-starter`. Replace every `{{FILL: ...}}` marker with facts about this
> app, then remove this blockquote. This repository is the app, not the starter kit.

{{FILL: Describe the problem this app solves, who it serves, and how it fits the Built for Cloud
ecosystem. Include explicit non-goals so contributors and agents do not expand the product by guess.}}

## Workflow

See `.solo/workflow.md` for this app's repository, merge policy, hard gate, CI, and coordination
details. Complete that file before delegating work.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No Frontend Build Step

Non-negotiable, inherited from the starter kit: **no Node, no npm, no Vite, no frontend build step.**
Do not introduce one.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. A class absent
from that bundle silently does nothing. To use new classes, delete the existing bundle, run
`php artisan tailwind:optimize`, verify the classes landed, and commit the regenerated file. The
optimizer uses a standalone Tailwind binary and does not introduce Node tooling.

## Built For Cloud

{{FILL: Describe the app's Built for Cloud integration and where its app manifest is maintained.
Keep this section accurate as capabilities are added.}}

Laravel Cloud provisions resource configuration. Do not commit secrets, app IDs, personal config,
machine-specific paths, or hand-written values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose because PHPStan scans the
model helper. `.phpstorm.meta.php` is gitignored because it embeds absolute local paths. Do not make
these files consistent in either direction.

## Static Analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.
