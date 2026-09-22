<p align="center">
  <img src="art/icon.png" alt="muster icon" width="128">
</p>

# Muster

Muster watches your scheduled tasks and tells you when one stops reporting in.

## What It Does

A scheduled task — a nightly backup, an hourly import, a queue sweeper — is supposed to run on a
rhythm. When it silently stops running, nothing complains. Muster is the thing that complains.

Each task gets a **monitor**: a record of how often that task is expected to report in. Every time
the task runs, it sends Muster a short message (a **check-in**). If a check-in does not arrive
inside its window, Muster marks the monitor `late`, and then `missed`, and can email you or call a
webhook.

Muster replaces the per-monitor pricing of hosted cron monitors such as Healthchecks.io, Cronitor
and Oh Dear. You run one copy, and it watches as many tasks as you like.

What Muster is **not**: it is not a metrics store and not a log store. It never reads or stores your
task's output. It answers one question — did the thing report in on time?

Today Muster has:

- A web UI to create, view, edit, enable and disable monitors.
- Automatic registration: connect a Laravel app and every one of its scheduled commands becomes a
  monitor on its first run, using the command's real cron expression and timezone.
- Tracking for starts, finishes, non-zero exits, skipped runs and runs that never finished.
- Per-monitor email and webhook alerts, chosen per failure type.
- A weekly email digest of the account's failures, sent every week — including a clean week, which
  sends an all-clear.

## The Easy Way: Scalpels

You do not have to run Muster yourself. Muster is part of the [Scalpels](https://scalpels.app)
catalog, which installs it into your own Laravel Cloud account and keeps it up to date for you. The
app still runs in your infrastructure and your data stays in your database — Scalpels handles the
provisioning, the upgrades and the billing.

If that is what you want, start at [scalpels.app](https://scalpels.app) and skip the rest of this
document.

## Run It Yourself

Everything below runs Muster on your own machine and then on your own Laravel Cloud account.

### Prerequisites

- PHP 8.3 or newer.
- [Composer](https://getcomposer.org).
- Nothing else. **Muster has no frontend build step** — no Node, no npm, no Vite. The CSS is
  committed to the repository already.

For the deployment steps you also need a [Laravel Cloud](https://cloud.laravel.com) account and the
`cloud` CLI.

### 1. Install and set up locally

From a clone of this repository:

```bash
composer setup
```

That runs `composer install`, copies `.env.example` to `.env`, generates an application key, and
runs the migrations against a local SQLite file at `database/database.sqlite`.

**What you should see:** a list of migrations, each ending in `DONE`. The last ones are Muster's own
(`create_monitors_table`, `create_check_in_events_table`, and so on).

### 2. Create your account

Muster has no public sign-up. The first account is created from the command line:

```bash
php artisan create-admin --local --email=you@example.com --name="Your Name"
```

The command prompts for a password. The first account is always the **Owner**; run it again and it
refuses, unless you pass `--force`, which creates an Admin instead.

> **Always pass `--local`.** `--local` means "this machine's database". Leave it off and
> `create-admin` asks Laravel Cloud for a list of your environments and offers to create the account
> on one of them — on a real deployed app, not on your machine. The `bfc:credential:*` commands also
> require `--local`; they refuse to run without it rather than guessing.

**What you should see:** `Admin user you@example.com created.` — it says "Admin" for the Owner too.

### 3. Start it

```bash
composer dev
```

This serves the app at <http://localhost:8000>.

Open it. The front page shows Muster's name and description and an **Open application** link. Sign
in at `/bfc/login` with the account from step 2. After signing in you land on `/bfc/ui`, the account
screen, where you manage members, sessions and access tokens. Your monitors are at `/monitors`,
which will say *No monitors have been created yet.*

### 4. Run the tests

```bash
php artisan test
```

**What you should see:** every test passing. For the full quality gate — code style, static
analysis, tests and a dependency audit in one go — run `composer ready`.

### 5. Deploy to Laravel Cloud

Muster is built to be deployed to Laravel Cloud, one copy per team, into your own account.

1. **Create the app.** Run `cloud ship` from the project directory and follow the prompts. It
   creates the application and an environment, connects this repository, and creates and attaches a
   database. Let it do the database — that is the reliable path.
2. **Add a queue.** Muster's weekly digest is queued, so it needs a real queue. Create a Laravel
   Cloud *managed queue* and make it the default. Do not run a manually scaled worker instance.
3. **Turn on the scheduler.** This is not optional. Muster's `monitors:evaluate` command runs every
   minute and is the thing that actually marks a monitor late or missed. Without the scheduler,
   Muster records check-ins and never raises an alert.
4. **Set only app variables.** `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and
   your mail settings.
5. **Deploy**, then run the migrations: `cloud command:run <env> --cmd="php artisan migrate --force"`.
6. **Create your account on the deployed app.** Use Laravel Cloud's own command runner rather than
   running `create-admin` from your machine:
   `cloud command:run <env> --cmd="php artisan create-admin --local --execute --email=you@example.com --name='Your Name' --password-hash='<hash>'"`.
   Generate the hash locally so the plaintext password never travels.

> ### The one rule that will bite you
>
> **Never set an environment variable for a resource Laravel Cloud provisioned for you.**
>
> When you attach a database, a cache, a queue or a storage bucket, Cloud writes all of that
> resource's configuration into a managed environment file your app reads automatically — not just
> the credentials, but the connection selectors too: `DB_CONNECTION`, `DB_*`, `CACHE_STORE`,
> `QUEUE_CONNECTION`, `SESSION_DRIVER`, `AWS_*`, `FILESYSTEM_DISK`.
>
> If you also set one of those yourself, your value shadows the injected one and the resource
> breaks. Provision it, attach it, and leave its variables alone.

### 6. Connect your first application

A monitor needs something to check in. The easiest way is the client package, which reports every
scheduled command in a Laravel application without you touching a single command definition.

The client lives in this repository at [`packages/muster-client`](packages/muster-client/README.md)
and is released separately as `artisan-build/muster-client`. Its README has the current install
instructions.

Once installed, `php artisan muster:install` in the connected application asks for your Muster
schedule-event URL (`https://your-muster.example/api/schedule-events`), opens Muster in your
browser, and asks you to approve the connection. Approving it issues the application a bearer token
bound to that one permission. The token is written to that application's `.env` as `MUSTER_TOKEN`
and is never printed.

**What you should see:** the first time that application's scheduler runs a command, a monitor for
it appears in Muster's `/monitors` list, already enabled, with the command's real cron expression.

## How A Monitor Works

A monitor moves between these states:

| Status | Meaning |
| --- | --- |
| `pending` | Created, but has never been checked in to. It has no due time yet, so it cannot go missed. |
| `running` | A run started and has not reported finishing. |
| `healthy` | The last run finished cleanly, on time. |
| `failed` | The last run finished with a non-zero exit code. |
| `late` | The check-in is past due, but still inside the grace period. |
| `missed` | Past due *and* past the grace period. This is the one that alerts. |

Two numbers control that, and you set both when you create a monitor:

- **Cadence** — how many seconds between expected check-ins. For an auto-registered monitor, Muster
  derives this from the cron expression instead.
- **Grace period** — how many extra seconds to tolerate before calling it missed. A job that usually
  takes four minutes needs a grace period longer than four minutes.

`monitors:evaluate` walks enabled monitors every minute and applies those two thresholds. A monitor
can pass through `late` into `missed` in a single pass if both thresholds are already behind it.

### Alerts

Each monitor has two independent alert channels, email and webhook. For each channel you pick which
failures you want — *missed and recovered*, *non-zero exits*, both, or neither — and a destination.
Muster sends one alert per channel per incident, and one `recovered` alert when the incident ends.
It does not re-alert every minute while a monitor stays missed.

### The weekly digest

Once you have at least one monitor, Muster emails the account a digest every Monday covering the
previous UTC week. A week with no failures still gets an email, saying so. The digest goes to every
active account user with a real email address.

The digest needs a durable queue. The in-process `sync`, `deferred`, `background` and `null` drivers
are refused — with any of those, digests are never published.

## Configuration

Muster adds no environment variables of its own. It reads the standard Laravel ones:

| Variable | Why Muster cares |
| --- | --- |
| `APP_URL` | Used for the link in the weekly digest email. Alert emails carry no links. |
| `DB_CONNECTION` and friends | Where monitors and check-in history live. **Cloud-injected in production.** |
| `QUEUE_CONNECTION` | Alerts and digests are queued. Must be durable, or the weekly digest never sends. **Cloud-injected in production.** |
| `MAIL_MAILER` and the other `MAIL_*` values | How email alerts and the digest are delivered. Locally this defaults to `log`, which writes the email into `storage/logs/laravel.log` instead of sending it. |
| `SESSION_DRIVER` | Signing in to the UI. **Cloud-injected in production.** |
| `CACHE_STORE` | **Cloud-injected in production.** |

Muster's catalog identity — its name, description and which permissions it grants — lives in
`config/built-for-cloud.php`. It is generated, not hand-edited; change it with
`php artisan bfc:starter:configure`.

The two permissions Muster grants are `muster.check-in` (report a scheduled run) and
`muster.history.read` (read a monitor's event history). A token is bound to exactly one of them,
and to one installation. A token issued for check-ins cannot read history, and a token issued for
one Muster installation is useless against another.

On the connected application's side, the client package reads `MUSTER_URL`, `MUSTER_TOKEN`,
`MUSTER_CONNECT_TIMEOUT` and `MUSTER_TIMEOUT`.

## The HTTP API

Three routes, all requiring a bearer token in an `Authorization` header. Every one of them answers
`401` if the token is missing, wrong, or bound to a different permission or installation.

| Route | Permission | Purpose |
| --- | --- | --- |
| `POST /api/schedule-events` | `muster.check-in` | Report a scheduled command starting, finishing, failing or being skipped. Registers the monitor on first use. This is what the client package calls. |
| `POST /api/monitors/{id}/check-ins` | `muster.check-in` | A plain heartbeat for a monitor you created by hand. |
| `GET /api/monitors/{id}/check-ins` | `muster.history.read` | That monitor's recent event history as JSON. |

Tokens come from the browser authorization in step 6. Muster does not currently offer a
mint-a-token button in its UI, so run `php artisan muster:install` from a Laravel application even
if all you want is the token it writes to that application's `.env`.

With a check-in token, a heartbeat is just:

```bash
curl -X POST https://your-muster.example/api/monitors/1/check-ins \
  -H "Authorization: Bearer $MUSTER_TOKEN"
```

A successful call returns `{"status":"ok"}`, sets the monitor to `healthy`, and moves its next due
time forward by its cadence. Put that line at the end of the script you want watched.

Muster keeps roughly the last hundred events per monitor, plus recent failures, and prunes older
ones. It is a monitor, not an archive.

> Muster stores the exact command string of every scheduled command it registers, because that
> string is how it recognises the same task next time. It shows those commands in the UI and in the
> weekly digest. **Do not put passwords or API keys in command-line arguments** of scheduled
> commands you connect to Muster. Muster never reads or stores command *output*.

## Troubleshooting

**I cannot sign in — there is no sign-up link.** There is no public sign-up. Create the first
account with `php artisan create-admin --local` (step 2).

**A monitor is stuck on `pending` and never goes missed.** A monitor you created by hand has no due
time until its first check-in arrives. Send it one heartbeat and the clock starts.

**Nothing ever goes `late` or `missed`.** `monitors:evaluate` is not running. Locally, that means
`php artisan schedule:work` is not running; on Laravel Cloud, the scheduler is not enabled for the
environment. Run `php artisan monitors:evaluate` by hand to confirm the logic works — it prints how
many transitions it recorded.

**The weekly digest never arrives.** Check the queue. Muster refuses to publish digests on the
`sync`, `deferred`, `background` and `null` drivers, and needs a worker actually draining the
queue.

**A connected application registered no monitors.** The client is inert unless both `MUSTER_URL` and
`MUSTER_TOKEN` are set and non-empty. Run `php artisan config:clear` in that application after
installing. Note that the client reports *scheduled* commands only, on their next scheduled run —
running the command by hand reports nothing.

**I edited a scheduled command and now there are two monitors.** A monitor is identified by the
command string alone. Changing only the schedule updates the existing monitor; changing the command
itself is a new task as far as Muster is concerned, so it gets a new monitor and the old one stays
behind. Disable the old one.

**An artisan command asked me which Laravel Cloud environment to use.** You left off `--local`. Say
no, and run it again with `--local`.

## License

MIT, as declared in `composer.json`.
