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
Laravel's `cloud` CLI:

```bash
composer global require laravel/cloud-cli
cloud auth -n
```

`cloud auth` opens a browser to sign you in. On a machine with no browser, set `LARAVEL_CLOUD_TOKEN`
in the environment instead, or save a token with `echo "$TOKEN" | cloud auth:token --add -n`.

**What you should see:** a confirmation that you are authenticated. Every `cloud` command below takes
`-n` so it never stops to ask a question you cannot see.

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

Then fix one value `.env.example` gets wrong for local use. It ships `APP_URL=http://localhost`,
with no port, but you will be serving on port 8000. `APP_URL` is what Muster puts in the weekly
digest's link, so leave it alone and that link points at the wrong place:

```bash
sed -i '' 's|^APP_URL=.*|APP_URL=http://localhost:8000|' .env   # macOS; drop the '' on Linux
php artisan config:clear
```

**What you should see:** `php artisan tinker --execute="echo route('monitors.index');"` now prints
`http://localhost:8000/monitors` rather than `http://localhost/monitors`.

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
you a web UI that can never notice a task that stopped reporting in.

**Terminal 1 — the web server:**

```bash
composer dev
```

This serves the app at <http://localhost:8000>. It stays in the foreground.

**Terminal 2 — the scheduler:**

```bash
php artisan schedule:work
```

This is the one that notices absence. Every minute it runs `monitors:evaluate`, which marks overdue
monitors `late` and then `missed`, and `digests:publish-weekly`. Without it Muster cannot detect a
missing check-in and cannot generate a weekly digest. A run that *does* report in and fails — a
non-zero exit sent to `/api/schedule-events` — still alerts without the scheduler, as long as a
queue worker is running.

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
Laravel application without you touching a single command definition. Do this against the **local**
Muster from step 4, before you deploy anything — it is the fastest way to see Muster work.

You need a second Laravel application. Any existing one will do; if you have none, make a throwaway:

```bash
composer create-project laravel/laravel ~/muster-demo
cd ~/muster-demo
```

> **The client package is not published yet.** `artisan-build/muster-client` is built inside the
> Muster repository and will be released as its own package, but it is not on Packagist today. Until
> it is, install it with a Composer **path repository** — a local directory Composer treats as a
> package source.

Using the absolute path to your Muster checkout:

```bash
composer config repositories.muster-client path /absolute/path/to/muster/packages/muster-client
composer require artisan-build/muster-client:@dev
```

Now connect it to the Muster you have running on port 8000:

```bash
php artisan muster:install --url=http://localhost:8000/api/schedule-events
php artisan config:clear
```

`muster:install` prints an authorization URL and opens it in your browser. Sign in to Muster with
the account from step 3, check that the page is asking for the `muster.check-in` permission, and
approve it. That hands this application a **bearer token** — a secret string it sends in an
`Authorization: Bearer …` header to prove who it is. The token is written to this application's
`.env` as `MUSTER_TOKEN` and is never printed to your screen. The package does nothing at all unless
both `MUSTER_URL` and `MUSTER_TOKEN` are set.

**What you should see:** `Muster connection settings updated.`

The client reports *scheduled* commands, so give this application something to schedule. Add one line
to its `routes/console.php`:

```php
Illuminate\Support\Facades\Schedule::command('inspire')->everyMinute();
```

Then run its scheduler once, by hand:

```bash
php artisan schedule:run
```

**What you should see:** `Running ['artisan' inspire] .. DONE` here, and then — in Muster at
<http://localhost:8000/monitors> — a **new monitor that you did not create**. It is already enabled,
its status is `healthy`, it is named after the command that ran, and it carries that command's real
**cron expression**: the five-field schedule string, here `* * * * *` (minute, hour, day of month,
month, day of week), that says when the task is meant to run. Muster derives the expected cadence
from it, so you never set one by hand for a connected command.

That is the whole product working: you registered nothing, and Muster now knows about the task and
when to expect it again.

### 7. Deploy to Laravel Cloud

Muster is built to be deployed to Laravel Cloud, one copy per team, into your own account. The
commands below were written against Laravel Cloud CLI **v0.6.0**. Its flags move between releases, so
run `cloud <command> -h` before trusting any signature, including these.

**7.1 — Create the application.**

```bash
cloud ship -n
```

Walk the prompts: region, application name, repository, resources. Let it create and attach the
**database** — that is the reliable path. Say **yes** to enabling the **scheduler**; without it
Muster cannot notice a missing check-in.

Near the end `ship` prints your environment's URL and asks **"Do you want to deploy the
application?"**. Answer **no** for now — you still have a queue to attach and variables to set, and
you will deploy in 7.5.

**Write down the URL it printed.** It looks like `https://<something>.laravel.cloud` and it is the
address of your Muster. Everything below that says `<your-muster-url>` means that URL. If you want a
custom domain instead, see 7.8.

> **Never upload your local `.env`.** CLI v0.6.0 filters standard Laravel keys out of its
> "add local environment variables" prompt, so a stock Muster checkout will not be offered any — but
> the rule below is what matters whenever you set a variable by any route.

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
cloud managed-queue:create <env> --name=muster --size=<size> --json -n
cloud managed-queue:set-default <instance-id> -n
```

Run `cloud managed-queue:create -h` for the sizes your account offers. `set-default` takes the
**instance id** the create call returns, not the queue's name. Because the queue is a
Cloud-provisioned resource, Cloud sets `QUEUE_CONNECTION` itself — you do not.

**7.4 — Set the application variables.** Only these. Nothing about a resource.

```bash
cloud environment:variables <env> --action=set --key=APP_ENV   --value=production   -n --force
cloud environment:variables <env> --action=set --key=APP_DEBUG --value=false        -n --force
cloud environment:variables <env> --action=set --key=APP_NAME  --value=Muster       -n --force
cloud environment:variables <env> --action=set --key=APP_URL   --value=<your-muster-url> -n --force
```

Non-secret mail settings go the same way — `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_FROM_ADDRESS`. Remember Muster only sends through a delivery-capable transport, so `log` is
not a usable production value.

**Anything secret — `MAIL_PASSWORD`, a mail provider's API key — does not go in `--value=`.** That
leaves the plaintext in your shell history and in the process list. Use a Cloud secret, which the
CLI encrypts locally so the plaintext never reaches the API:

```bash
echo "$MAIL_PASSWORD" | cloud secret:create --name=MAIL_PASSWORD --json -n
cloud environment-secret:attach <env> <secret-id> -n
```

`secret:create` returns the id; `environment-secret:attach` takes ids, not names. Let Cloud generate
and keep `APP_KEY`.

**7.5 — Deploy and migrate.**

```bash
cloud deploy -n
```

`cloud deploy` **already waits** for the deployment and reports the result, so do not follow it with
`cloud deploy:monitor`: the monitor only accepts a deployment that is still running, and against a
finished one it polls for about five minutes and then fails with
`No active deployment found after waiting.` If you want the two separately, start it detached and
monitor immediately:

```bash
cloud deploy --no-wait -n
cloud deploy:monitor -n
```

Then migrate:

```bash
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

**7.7 — Check it actually works.** Do not assume. Four things have to be true, and the first two
being true tells you nothing about the last two.

*The app serves and the resources are attached:*

```bash
curl -sI <your-muster-url>                     # expect 200 and a valid certificate
cloud tinker <env> --code="echo config('database.default');" -n
cloud tinker <env> --code="echo config('queue.default');" -n
```

The database value should name the engine Cloud attached, and the queue value should be Cloud's own
managed-queue connection name — **not** `database` and not `sync`. If either looks like a value from
your local `.env`, you set a variable you should not have; remove it and redeploy. Sign in at
`<your-muster-url>/bfc/login` and confirm `/monitors` loads.

*The scheduler is actually running.* This is the check people skip, and skipping it is how you
discover months later that nothing was ever being watched. `monitors:evaluate` writes a line every
time it runs, so look for it:

```bash
cloud tinker <env> --code="echo App\Models\Monitor::whereNotNull('next_due_at')->count();" -n
```

Then wait two minutes and run the same command after deliberately letting a monitor go overdue —
its status should reach `late` and then `missed` without you running anything. If a monitor stays
`healthy` past its due time, the scheduler is not enabled for the environment.

*The queue actually drains.* A configured queue is not a consuming queue. Create a throwaway monitor
in the UI with a short cadence, point its webhook alert at a request-bin style URL you control, let
it go missed, and confirm the request arrives:

```bash
cloud tinker <env> --code="echo App\Models\AlertDelivery::latest('id')->value('status');" -n
```

**What you should see:** `delivered`. A value stuck at `pending` means nothing is consuming the
queue; `retrying` or `failed` means the destination or the mail transport rejected it. Delete the
throwaway monitor afterwards.

**7.8 — Optional: a custom domain.** The environment URL from 7.1 works permanently. If you want
your own hostname instead:

```bash
cloud domain:create <env> --name=muster.example.com --json -n
cloud domain:verify <domain-id> -n
```

`domain:create` returns the DNS records to place at your registrar. `domain:verify` only succeeds
once those records have propagated. After it verifies, update `APP_URL` (7.4) to the new hostname
and redeploy, or the weekly digest will keep linking to the old one.

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

curl -sS -X POST http://localhost:8000/api/monitors/1/check-ins \
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
variable for it, by any route — `cloud environment:variables`, a secret, or accepting an offer to
upload a local variable. Remove it and redeploy. Read the resolved value back with
`cloud tinker` (step 7.7) rather than trusting the dashboard.

## License

MIT, as declared in `composer.json`.
