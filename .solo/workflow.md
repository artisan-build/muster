# Workflow - Muster

Project profile for the `multi-agent-build` skill and every agent working in this Built for Cloud
app. The coordinator reads this first. Keep it truthful as the app evolves.

This repository is an app scaffolded from `artisan-build/built-for-cloud-starter`; it is not the
starter kit. Never open app pull requests against the starter kit or its nodeless upstream.

## What This Is

Muster is a self-hosted heartbeat and cron monitor. Scheduled tasks and background services check in on a known rhythm; when a check-in does not arrive inside its window, Muster says so. It replaces the per-monitor pricing of Healthchecks.io, Cronitor and Oh Dear cron monitoring.

Non-goals, explicitly: no public status page at MVP (it is a later surface of this same product, not a second build); no escalation policies, on-call schedules or paging integrations; and emphatically not a metrics or log store - Hone is the telemetry product, Muster answers only whether the thing reported in.

The decision-complete scope lives in `~/Herd/brain/ideas/2026-09-21-muster-prd.md` — read
**Amendment 1** at the end, which changes the onboarding model; the MVP scope above it is shipped
history. The package scope is `~/Herd/brain/ideas/2026-09-21-muster-client-prd.md`. Read it before decomposing work; it
records which decisions are Ed's and which are brain's, so neither gets silently reopened.

## Phase And Mode

- phase: pre-launch
- default mode: A-autonomous
- merge policy: merge each PR when the full gate and CI are green; no human review required per PR.
  Ed is explicitly not reviewing each PR for this build.
- merge method: `gh pr merge --squash`, then delete the branch.
  - **Ruleset #23758577 is ACTIVE on `main`** (verified 2026-09-21): `pull_request` required,
    required status checks `ci (8.5)` and `quality`, plus `deletion` and `non_fast_forward`
    protection. Direct pushes to `main` are DECLINED — brain hit this landing this very file.
    Bobbin carries the parallel ruleset #23758578.
  - Because required checks ARE configured, `--auto` would genuinely wait for them here. Keep
    using a manual squash on confirmed-green anyway: it is one command either way, and it keeps
    the merge decision observable in the run log.

## Hard Gate

- command: `composer ready` (ide-helper regen + rector + pint + phpstan + pest + composer audit)
- extra suites: none
- monorepo: YES as of 2026-09-21. `packages/muster-client` is now in scope and splits out with
  kibble. Supersedes the earlier "do not pre-build a package layout" line; see PRD Amendment 1.

The coordinator verifies the hard gate on the committed SHA with a clean tree.

## CI

- status: verified
- required jobs: `ci (8.5)` (Rector check, PHPStan, Pest, composer audit) and `quality` (Pint).
  Both testing and static analysis are present, which is what permits CI-gated autonomous merging.
- workflows: `.github/workflows/tests.yml` and `.github/workflows/lint.yml`

CI installs free `livewire/flux` and needs no Flux Pro credentials. Do not add a step that does.

## Agent Roles

Fleet bindings come from `~/Herd/brain/agents.json`, resolved at spawn time via
`~/Herd/brain/playbooks/resolve-agent-role.md`.

- implementer / quality reviewer / acceptance judge: resolve the roles, do not hardcode.
- **Deliberate deviation from the starter template**, which asks for runtimes and Solo agent_tool_ids
  here: Solo ids are machine-local and drift on re-add, and a copied harness map goes stale silently.
  Resolve live and confirm the model from the agent's own banner, never from the command string.
- constraints: none specific to this app.

## Ship Details

- branch naming: `feat/<slug>`, `fix/<slug>`
- PR target repo: artisan-build/muster
- release or split steps: `packages/muster-client` is built in-repo and split out with kibble. The
  real consumer now exists: every connected BfC app. (Ed, 2026-09-21.)

## Plan And Coordination

- plan location: `~/Herd/brain/ideas/2026-09-21-muster-prd.md`
- Solo project: muster (59)
- run-log: a Solo scratchpad in this project, appended at every transition.
- decision trail: `~/Herd/brain/projects/muster/decisions-<task-slug>.tsv` via
  `~/Herd/brain/scripts/log-decision.sh`. Never write a decisions file into this repo.
- mutation debt: `~/Herd/brain/projects/muster/mutation-debt.tsv`.

## Built For Cloud

- app role: A Built for Cloud catalog product. BfC apps register with Muster EXPLICITLY, per app - never automatically - so a Muster bug cannot storm the fleet with alerts.
- manifest: `config/built-for-cloud.php`, package-owned three-key shape
  (`manifest`, `credentials`, `ui`). Configure it through
  `php artisan bfc:starter:configure --spec=<abs-json> --no-interaction` — the frozen two-command
  contract. Do not hand-edit the shape or flip a contract default to make something pass.
  Verify with `php .claude/skills/bfc-app-manifest/scripts/manifest.php --check --json`.
- deployment: Laravel Cloud, single-tenant, deployed into the customer's own account. Cloud injects
  ALL resource configuration — never set env for a Cloud-provisioned resource. No app ids or secrets
  in this file.

## Stack Notes

- Laravel 13 on `laravel-nodeless`: no Node, no npm, no Vite, no frontend build step. Do not add one.
- `artisan-build/built-for-cloud` is pinned at `^0.15`; the package moves fast, and raising the floor
  is preferred over shimming around it.
- The missed/recovered state machine is the part most likely to carry a test that cannot fail: a monitor that is never marked missed still passes a naive suite. Drive a full late-missed-recovered cycle, and drive a successful check-in TWICE - a repeated identical success is a known trap across this fleet.
