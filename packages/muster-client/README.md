# muster-client

`muster-client` connects a Laravel application to its self-hosted Muster instance. Once connected,
every scheduled command reports its start and completion automatically. The first start creates an
enabled monitor from Laravel's real command, cron expression, and timezone; no per-command setup is
required.

This repository is a read-only split of the
[`artisan-build/muster`](https://github.com/artisan-build/muster) monorepo. Open issues and pull
requests on the monorepo.

## Install

```bash
composer require artisan-build/muster-client
php artisan muster:install
php artisan config:clear
```

The installer asks for the full schedule-event endpoint, normally
`https://muster.example/api/schedule-events`. It then binds a temporary loopback listener, prints the
Muster authorization URL, and opens it in the browser where supported. Sign in to Muster, review the
`muster.check-in` grant, and approve it. The one-time callback and PKCE exchange happen locally; the
bearer is never printed. The installer writes `MUSTER_URL` and `MUSTER_TOKEN` to the application's
environment only after authorization succeeds. The package is inert unless both values are non-empty.

For a manually issued protocol-bound bearer, run `php artisan muster:install --manual-token` and use
the hidden prompt. This is a fallback, not the recommended connection path. Do not pass a real bearer
with `--token`: command arguments can be retained in shell history, process listings, and transcripts.
A generic credential minted by `bfc:credential:mint` is deliberately not interchangeable with the
bound bearer issued by the browser ceremony.

Never commit a bearer or pass it through chat. `muster-client` also carries
`artisan-build/bfc-client`, which adds the source installation's opaque `X-BfC-Client-Id` label to
requests. That identity is not a credential.

## Behaviour

- `ScheduledTaskStarting` sends a unique execution ID and auto-registers the command.
- Foreground completion comes from `ScheduledTaskFinished`.
- Background dispatch is deliberately ignored. The execution ID is passed to Laravel's
  `schedule:finish` child, and only `ScheduledBackgroundTaskFinished` completes the execution.
- Non-zero exits and thrown failures are reported as failures.
- Filtered and overlap-prevented runs are reported as skips, never as failures or missed runs.
- The monitor key is the SHA-256 hash of the command only. Changing a cron expression updates the
  existing monitor rather than creating a new one.
- Delivery fails open with short timeouts so Muster cannot fail the scheduled command.
- Command output is never read, transmitted, or logged.

Muster necessarily sends and stores the exact scheduled command to provide stable identity. Treat
command strings as sensitive data: do not put credentials or other secrets in command-line arguments.
Muster may show exact commands in its authenticated UI and in the account's weekly digest.

`MUSTER_CONNECT_TIMEOUT` defaults to `0.5` seconds and `MUSTER_TIMEOUT` defaults to `1.0` second.
Publish `muster-config` only when tracked configuration changes are needed.
