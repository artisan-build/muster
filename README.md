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
and Oh Dear. One self-hosted Muster instance monitors as many tasks as you register, with no
per-monitor billing.

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

Everything below runs Muster on your own machine, connects a real application to it, and then
deploys it to your own Laravel Cloud account.

### Prerequisites

- **Git.**
- **PHP 8.3 or newer, within the PHP 8 series** (`composer.json` requires `^8.3`, which excludes
  PHP 9). It needs the `pdo_sqlite` extension, which the default local database uses. Check with
  `php -m | grep pdo_sqlite`.
- **[Composer](https://getcomposer.org).**
- Nothing for the frontend. **Muster has no frontend build step** — no Node, no npm, no Vite. The
  CSS is committed to the repository already.

For the deployment section you also need a [Laravel Cloud](https://cloud.laravel.com) account and
Laravel's `cloud` CLI.

### 1. Get the code

Muster's repository is private while the product is pre-launch, so
[Scalpels](https://scalpels.app) is how most people get it. If you have repository access:

```bash
git clone git@github.com:artisan-build/muster.git
cd muster
```

Every command from here runs in that directory.

### 2. Install and set up

```bash
composer setup
```

That runs `composer install`, copies `.env.example` to `.env`, generates an application key, and
runs the migrations against a local SQLite file at `database/database.sqlite`.

**What you should see:** a list of migrations, each ending in `DONE`. The last ones are Muster's own
(`create_monitors_table`, `create_check_in_events_table`, and so on).

### 3. Create your account

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

### 4. Start the three processes Muster needs

Muster is three things running at once, so open **three terminals**. Running only the first gives
you a web UI that records check-ins and never alerts on anything.

**Terminal 1 — the web server:**

```bash
composer dev
```

This serves the app at <http://localhost:8000>. It stays in the foreground.

**Terminal 2 — the scheduler:**

```bash
php artisan schedule:work
```

This is the one that does the actual monitoring. Every minute it runs `monitors:evaluate`, which
marks overdue monitors `late` and then `missed`, and `digests:publish-weekly`. Without it Muster
never raises an alert about anything.

**What you should see:** within a minute,
`Running ['artisan' monitors:evaluate] .. DONE`, repeating every minute.

**Terminal 3 — the queue worker:**

```bash
php artisan queue:work
```

A **queue worker** is a process that picks up background jobs and runs them. Muster puts every alert
email, webhook call and digest on the queue, so without a worker running, alerts are recorded and
never delivered.

**What you should see:** nothing at all until a job arrives, then a pair of lines per job —
`App\Jobs\DeliverTransitionAlert .. RUNNING` followed by the duration and `DONE` or `FAIL`.

Now open <http://localhost:8000>. The front page shows Muster's name and description and an **Open
application** link. Sign in at `/bfc/login` with the account from step 3. After signing in you land
on `/bfc/ui`, the account screen, where you manage members, sessions and access tokens. Your
monitors are at `/monitors`, which will say *No monitors have been created yet.*

### 5. Run the tests

In a fourth terminal, or after stopping the processes above:

```bash
php artisan test
```

**What you should see:** every test passing. For the full quality gate — code style, static
analysis, tests and a dependency audit in one go — run `composer ready`.

### 6. Connect your first application

A monitor needs something to check in. The client package reports every scheduled command in a
Laravel application without you touching a single command definition.

> **The client package is not published yet.** `artisan-build/muster-client` is built inside this
> repository and will be released as its own package, but it is not on Packagist today. Until it is,
> install it from your Muster checkout with a Composer **path repository** — a local directory
> Composer treats as a package source.

In the Laravel application you want monitored, using the absolute path to your Muster checkout:

```bash
composer config repositories.muster-client path /absolute/path/to/muster/packages/muster-client
composer require artisan-build/muster-client:@dev
```

Then connect it:

```bash
php artisan muster:install --url=https://your-muster.example/api/schedule-events
php artisan config:clear
```

`muster:install` prints an authorization URL and opens it in your browser. Sign in to Muster, check
that the page is asking for the `muster.check-in` permission, and approve it. That hands the
application a **bearer token** — a secret string it sends in an `Authorization: Bearer …` header to
prove who it is. The token is written to that application's `.env` as `MUSTER_TOKEN` and is never
printed to your screen. The package does nothing at all unless both `MUSTER_URL` and `MUSTER_TOKEN`
are set.

**What you should see:** the first time that application's scheduler runs a command, a monitor for
it appears in Muster's `/monitors` list, already enabled and `healthy`, named after the command, and
carrying the command's real **cron expression** — the five-field schedule string such as
`* * * * *` (minute, hour, day of month, month, day of week) that says when the task is meant to
run. Muster derives the expected cadence from it, so you do not set one by hand.

### 7. Deploy to Laravel Cloud

Muster is built to be deployed to Laravel Cloud, one copy per team, into your own account. These
commands use Laravel's `cloud` CLI. Flags change between CLI releases, so run `cloud <command> -h`
rather than trusting a signature you copied.

**7.1 — Create the application.**

```bash
cloud ship
```

Walk the prompts: region, application name, repository, resources. Let it create and attach the
**database** — that is the reliable path. Say **yes** to enabling the **scheduler**; Muster does not
work without it.

> **Decline the offer to sync your local `.env`.** `cloud ship` asks whether to copy your `.env` up.
> Say no. Your local file contains `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`,
> `CACHE_STORE=database`, `SESSION_DRIVER=database` and `FILESYSTEM_DISK=local` — exactly the
> variables Cloud is about to set for you, and copying them up breaks the resources you just
> provisioned. See the rule below.

**7.2 — Bind the repository.** `cloud ship` does not write the file that tells the CLI which
application this checkout deploys to, so do it explicitly:

```bash
cloud repo:config
```

**What you should see:** a `.cloud/config.json` holding an `organization_id` and an
`application_id`. Both are opaque identifiers, not credentials.

**7.3 — Add a queue.** Alerts and the weekly digest are queued, so the deployed app needs a real
one. Use a Cloud **managed queue** — a queue Cloud runs and scales for you — rather than a
hand-scaled worker instance:

```bash
cloud managed-queue:create <env> --name muster --size <size> -n --json
```

Run `cloud managed-queue:create -h` for the sizes your account offers, then make that queue the
default with `cloud managed-queue:set-default` — check its arguments with `-h` first, because they
vary between CLI versions. Because the queue is a Cloud-provisioned resource, Cloud sets
`QUEUE_CONNECTION` itself — you do not.

**7.4 — Set the application variables.** Only these. Nothing about a resource.

```bash
cloud environment:variables --json -n --action=set --key=APP_ENV   --value=production
cloud environment:variables --json -n --action=set --key=APP_DEBUG --value=false
cloud environment:variables --json -n --action=set --key=APP_NAME  --value=Muster
cloud environment:variables --json -n --action=set --key=APP_URL   --value=https://your-muster.example
```

Add your `MAIL_*` values the same way. Let Cloud generate and keep `APP_KEY`.

**7.5 — Deploy and migrate.**

```bash
cloud deploy -n
cloud deploy:monitor -n
cloud command:run <env> --cmd="php artisan migrate --force" -n --no-monitor
```

**7.6 — Create your account on the deployed app.** This is the one command in this document that is
*supposed* to reach Laravel Cloud, so it takes `--environment` instead of `--local`:

```bash
php artisan create-admin --environment=<env> --email=you@example.com --name="Your Name"
```

It prompts for the password on your machine, hashes it on your machine, and sends only the hash to
the environment through `cloud command:run`. The plaintext password never travels and never appears
in a command line.

**7.7 — Check it actually works.** Do not assume; read the resolved configuration back:

```bash
curl -sI https://your-muster.example            # expect 200 and a valid certificate
cloud tinker <env> --code="echo config('database.default');"
cloud tinker <env> --code="echo config('queue.default');"
cloud tinker <env> --code="echo App\Models\Monitor::count();"
```

The database value should name the engine Cloud attached, and the queue value should be Cloud's own
managed-queue connection name — **not** `database` and not `sync`. If either looks like a value from
your local `.env`, you set a variable you should not have; remove it and redeploy. Then sign in and
confirm `/monitors` loads.

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

## How A Monitor Works

A monitor moves between these states:

| Status | Meaning |
| --- | --- |
| `pending` | Created, but has never been checked in to. It has no due time yet, so it cannot go missed. |
| `running` | A run started and has not reported finishing. |
| `healthy` | The last run finished cleanly, on time. |
| `failed` | The last run finished with a non-zero exit code. It can alert, if you subscribed a channel to non-zero exits. |
| `late` | The check-in is past due, but still inside the grace period. |
| `missed` | Past due *and* past the grace period. This is what alerts when a check-in never arrives. |

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

The digest needs a **durable** queue — one that stores jobs somewhere a separate worker process can
pick them up later, such as a database or a Cloud managed queue. Muster refuses to publish digests
on the in-process drivers `sync`, `deferred`, `background` and `null`, because those run a job
inside the request or command that created it and leave nothing for a worker.

## Configuration

Muster adds no environment variables of its own. It reads the standard Laravel ones:

| Variable | Why Muster cares |
| --- | --- |
| `APP_URL` | Used for the link in the weekly digest email. Alert emails carry no links. |
| `DB_CONNECTION` and friends | Where monitors and check-in history live. **Cloud-injected in production.** |
| `QUEUE_CONNECTION` | Alerts and digests are queued. Must be durable, or the weekly digest never sends. **Cloud-injected in production.** |
| `MAIL_MAILER` and the other `MAIL_*` values | How email alerts and the digest are delivered. Muster only sends through a transport that really delivers — `smtp`, `sendmail`, `ses`, `ses-v2`, `mailgun`, `postmark` or `resend`, or a `failover`/`roundrobin` that resolves to one. `.env.example` ships `log`, which is **not** one of them, so email alerts fail locally until you change it. |
| `SESSION_DRIVER` | Signing in to the UI. **Cloud-injected in production.** |
| `CACHE_STORE` | **Cloud-injected in production.** |

Muster's catalog identity — its name, description and which permissions it grants — lives in
`config/built-for-cloud.php`. It is generated by a build command from a JSON spec, not hand-edited,
and nothing in normal operation requires you to change it. You can check it is intact with:

```bash
php .claude/skills/bfc-app-manifest/scripts/manifest.php --check --json
```

**What you should see:** `{"ok":true,"status":"configured",…}`.

The two permissions Muster grants are `muster.check-in` (report a scheduled run) and
`muster.history.read` (read a monitor's event history). A token is bound to exactly one of them,
and to one installation. A token issued for check-ins cannot read history, and a token issued for
one Muster installation is useless against another.

On the connected application's side, the client package reads `MUSTER_URL`, `MUSTER_TOKEN`,
`MUSTER_CONNECT_TIMEOUT` (default `0.5` seconds) and `MUSTER_TIMEOUT` (default `1.0` second).

## The HTTP API

Three routes, all requiring a bearer token in an `Authorization` header. Every one of them answers
`401` if the token is missing, wrong, or bound to a different permission or installation.

| Route | Permission | Purpose |
| --- | --- | --- |
| `POST /api/schedule-events` | `muster.check-in` | Report a scheduled command starting, finishing, failing or being skipped. Registers the monitor on first use. This is what the client package calls. |
| `POST /api/monitors/{id}/check-ins` | `muster.check-in` | A plain heartbeat for a monitor you created by hand. |
| `GET /api/monitors/{id}/check-ins` | `muster.history.read` | A monitor's recent event history as JSON. **Not usable yet** — see below. |

**Muster issues only `muster.check-in` tokens today.** `muster:install` always asks for that one
permission, and the credentials screens in `/bfc/ui` offer no permission to choose, so there is
currently no supported way to obtain a `muster.history.read` token. The history route exists and is
correctly guarded; it just cannot be called yet. Read a monitor's history in the web UI instead.

### Sending a heartbeat by hand

The check-in token lives in the connected application's `.env`. Load it into your shell without
putting it on a command line, where it would land in your shell history:

```bash
MUSTER_TOKEN=$(grep '^MUSTER_TOKEN=' /path/to/your-app/.env | cut -d= -f2-)

curl -sS -X POST https://your-muster.example/api/monitors/1/check-ins \
  -H "Authorization: Bearer $MUSTER_TOKEN"
```

**What you should see:** `{"status":"ok"}`. The monitor goes `healthy` and its next due time moves
forward by its cadence. If the monitor was `missed`, Muster also records a `recovered` event and
sends the recovery alert. An empty or wrong token gives `{"message":"Unauthenticated."}` and `401`.

Put that `curl` at the end of a script you want watched, and give the matching monitor a cadence and
grace period that suit how often the script runs.

Muster keeps roughly the last hundred events per monitor, plus recent failures, and prunes older
ones. It is a monitor, not an archive.

> Muster stores the exact command string of every scheduled command it registers, because that
> string is how it recognises the same task next time. It shows those commands in the UI and in the
> weekly digest. **Do not put passwords or API keys in command-line arguments** of scheduled
> commands you connect to Muster. Muster never reads or stores command *output*.

## Troubleshooting

**I cannot sign in — there is no sign-up link.** There is no public sign-up. Create the first
account with `php artisan create-admin --local` (step 3).

**A monitor is stuck on `pending` and never goes missed.** A monitor you created by hand has no due
time until its first check-in arrives. Send it one heartbeat and the clock starts.

**Nothing ever goes `late` or `missed`.** `monitors:evaluate` is not running. Locally that means
`php artisan schedule:work` is not running (step 4, terminal 2); on Laravel Cloud it means the
scheduler is not enabled for the environment. Run `php artisan monitors:evaluate` by hand to confirm
the logic works — it prints how many transitions it recorded.

**Monitors go `missed` but no email or webhook arrives.** Either nothing drained the queue — start
`php artisan queue:work` (step 4, terminal 3) — or the mail transport cannot deliver. `.env.example`
ships `MAIL_MAILER=log`, which Muster refuses for alerts: the job fails immediately with
`A delivery-capable mail transport is not configured`, retries three times, and the delivery ends
`failed`. Nothing is written to `storage/logs/laravel.log` either. To see alert email locally, set
`MAIL_MAILER=smtp` and point `MAIL_HOST`/`MAIL_PORT` at a local mail catcher. Webhook alerts need no
mail configuration and work as soon as a worker is running.

**My webhook endpoint is called but Muster still says the alert failed.** Muster treats any
non-success HTTP response as a failed delivery and retries three times before giving up. Return
`200` from the endpoint.

**The weekly digest never arrives.** Check the queue driver. Muster refuses to publish digests on
`sync`, `deferred`, `background` and `null`, and needs a worker actually draining the queue.

**A connected application registered no monitors.** The client is inert unless both `MUSTER_URL` and
`MUSTER_TOKEN` are set and non-empty — run `php artisan config:clear` in that application after
installing. Note that the client reports *scheduled* commands only, on their next scheduled run, so
that application needs its own scheduler running. Running the command by hand reports nothing.

**`composer require artisan-build/muster-client` cannot find the package.** It is not published yet.
Use the path repository in step 6.

**I edited a scheduled command and now there are two monitors.** A monitor is identified by the
command string alone. Changing only the schedule updates the existing monitor; changing the command
itself is a new task as far as Muster is concerned, so it gets a new monitor and the old one stays
behind. Disable the old one.

**An artisan command asked me which Laravel Cloud environment to use.** You left off `--local`. Say
no, and run it again with `--local`. The single exception is step 7.6, where reaching Cloud is the
point.

**A deployed resource behaves as though it is not attached.** Check whether you set an environment
variable for it — including by letting `cloud ship` sync your local `.env`. Remove the variable and
redeploy.

## License

MIT, as declared in `composer.json`.
