# codeinchrome

Laravel hosting where an AI agent does the building, and the code stays yours.

A customer picks a name, and a few seconds later there is a real Laravel
application on a real server, on HTTPS, with its own application key. They can
clone it, move it, or host it elsewhere the day they decide to. There is no
proprietary runtime to port away from.

Live: **https://app.codeinchrome.com**

---

## The one rule this codebase is built around

> **A method must not claim more than its mechanism can support.**

Every verdict carries a *basis*. Authority is earned per claim, not per
function. A result is something that was **observed**, never something inferred
from "the step ran without error". Almost every bug found while building this
was a violation of that rule rather than a crash:

| What claimed success | What was actually true |
|---|---|
| `caddy validate` passed, `systemctl reload` passed, 11/11 checks green | Caddy could not read its vhost directory, matched zero files, and served nothing at all — an unmatched glob is a *warning* |
| `docker rm -f` exited 0, delete reported `container: true` | The container was never on that host; `rm -f` exits 0 for a container that does not exist |
| `docker-php-ext-install` succeeded for gd, intl, zip | The runtime libraries had been purged with the `-dev` packages; all three failed to load, silently, because `display_errors` is off |
| `POST /v1/sites` returned 201 with a running container | The agent was a version too old to seed an app or generate a key, so the site had neither |
| 15 isolation probes reported DENIED | The container did not exist; `docker exec` fails identically whether the boundary held or the subject was absent |
| The webhook suite passed, every test green | The route was CSRF-protected and would have rejected every real delivery with 419 — Laravel disables CSRF *during tests* |
| Every editor save returned `ok: true` | Laravel's `TrimStrings` middleware had stripped the file's leading and trailing whitespace before it was written — every save silently removed the final newline |
| A new site was created, DNS and all | Caddy asked Let's Encrypt for its certificate before the record had propagated; the NXDOMAIN was cached for 30 minutes and the site had no HTTPS for that long |

Each of those is now a test, and most of them are checks that run on every
deploy.

## Layout

```
agent/        Go binary on each customer host. Creates and destroys sites,
              writes vhosts, reconciles the proxy. Binds 127.0.0.1 only.
control/      Laravel 13 control plane: accounts, plans, provisioning,
              billing webhooks, the dashboard.
infra/        Host bootstrap, agent install, image build, deploys, tunnels,
              and the isolation test that must pass on every host.
control/resources/js/editor.js
              The in-browser editor. A person clicks it; an AI agent drives the
              same functions through window.cic (run cic.help() in the console).
tests/e2e/    Playwright, against the real fleet. No mocked provisioning.
```

## How a site is isolated

Verified by `infra/verify-isolation.sh`, which carries positive controls so a
"denied" cannot come from a container that is simply absent:

- Its own container, its own bridge network, and `icc=false` on the daemon.
  Before this, one tenant fetched 70,403 bytes of another tenant's live app
  directly off the shared bridge, bypassing the proxy entirely.
- `--cap-drop ALL`, `no-new-privileges`, read-only root filesystem, tmpfs for
  `/tmp` and `/run` only.
- CPU, memory, swap and PID ceilings, read back from `docker inspect` rather
  than from what was requested.
- The document root is `public/` and cannot be moved above it. `.env`,
  `vendor`, `storage` and `composer.lock` are not reachable over HTTP.
- Egress to SMTP ports and known mining pools is rejected at the host, and the
  rules survive a reboot.
- Every site gets its own `APP_KEY`. A shared one would make session cookies
  forgeable and encrypted columns readable across tenants.

The control plane runs on a host of its own and is **not** in the customer
fleet: it holds every agent token and the Cloudflare key. Its SSH key into each
customer host is `restrict,port-forwarding,permitopen="127.0.0.1:9440"` — it
can open one pipe to the agent port and cannot get a shell.

## Running it

```bash
# One host, bare image to ready-to-serve. Idempotent.
infra/deploy-host.sh h1 <host ip>

# Prove tenant isolation on a host that has two sites.
ssh root@<host ip> 'bash -s' < infra/verify-isolation.sh

# The control plane.
infra/deploy-control.sh
```

```bash
cd control && PAO_DISABLE=1 php artisan test     # 40 tests, ~0.5s, no disk writes
cd tests/e2e && npx playwright test              # 6 tests against the real fleet
```

`PAO_DISABLE=1` is not optional: `laravel/pao` replaces the test printer when it
detects an AI agent and discards the entire report on a long run.

## Operations

```bash
php artisan fleet:status    # every host, as the host reports itself
php artisan fleet:audit     # containers no row claims, rows with no container
php artisan site:reap NAME  # force-remove from every host and from DNS
```

`fleet:status` shows the host's count beside our own rather than reconciling
them, because disagreement is the interesting case.

## Status

See [ROADMAP.md](ROADMAP.md). Built, deployed and verified against production:
signup, provisioning, DNS, on-demand TLS, per-site containers, networks,
disks (kernel-enforced quotas) and MySQL databases, tenant isolation (files,
network and database, each with positive controls), the in-browser editor with
revision-checked saves, a database browser, a terminal for allow-listed
artisan/composer commands and logs, custom domains proved by DNS, nightly
append-only encrypted backups with restore and full disaster recovery drilled,
monitoring with incidents, weekly security-patch rollout, two-factor sign-in,
an append-only audit log, a strict CSP, email verification and password
reset over the platform's own DKIM-signed mail, and dependency scanning in CI.

What a user sees today:

- **Free only, for now.** Paid plans wait for the payment provider (Lemon
  Squeezy) to approve the store. Checkout is built and switched off with one
  setting (`CIC_PAID_PLANS_OPEN`). Until then every account is on the free
  plan, and its sites are kept when paid plans open.
- **Custom domains** work today, with certificates issued on demand. Moving
  them to Cloudflare for SaaS is planned (see the roadmap).

## Licence

Source-available under the Functional Source License, version 1.1, with an
Apache-2.0 future license ([LICENSE.md](LICENSE.md), FSL-1.1-ALv2): use, modify
and contribute for any purpose except a competing product or service; each
version becomes Apache-2.0 two years after its release. Contributions: see
[CONTRIBUTING.md](CONTRIBUTING.md) and the [CLA](CLA.md).
