# Operating codeinchrome

Everything an operator does, as commands. Every one is idempotent: a run that
stops half-way is finished by running it again.

`artisan` below means, on the control host (h2):

```bash
ssh root@<control> 'cd /srv/control && sudo -u codeinchrome php8.4 artisan ...'
```

## The fleet

| Task | Command |
|---|---|
| Add a server (bare Ubuntu, root SSH by key) | `infra/add-host.sh <ip>` - deploys, registers in `infra/hosts.env`, opens its tunnel, proves isolation with two probe sites, and the "N available" count rises |
| Re-prove a host without redeploying | `CIC_PROVE_ONLY=1 infra/add-host.sh <ip>` |
| See the fleet | `artisan fleet:status` |
| Upgrade the agent everywhere | build (`agent/`), then for each host: `infra/deploy-host.sh hN <ip>` (or copy the binary and restart `cic-agent`); raise `min_agent_version` in `control/config/fleet.php` after |
| Deploy the control plane | `infra/deploy-control.sh` (never while an e2e or load run is using the fleet) |
| Move one site | `artisan fleet:move-site <site> [--to=hN]` - copies database, files and history, checks the copy answers, switches DNS, then removes the source |
| Retire a host | add `hN:draining` to `CIC_HOST_STATES` in `infra/hosts.env`, `infra/deploy-control.sh`, then `artisan fleet:drain hN`; when it reports empty, remove its entry and deploy again |
| Move sites onto a new base image | `artisan fleet:roll-image` (also nightly) |
| Clean up after a failure | `artisan site:reap <site>` (every host and DNS) |
| A host is lost | mark it draining, then `infra/recover-host.sh hN` - every site back from its backups on other hosts (docs/RECOVERY.md) |

Hosts are named `hN`; host `hN`'s agent is reached through its tunnel on
`127.0.0.1:(9440+N)`. The one registry is `infra/hosts.env` (`CIC_HOSTS`).
Addresses outside this fleet that must never be touched are listed in
`infra/hosts.local.env` (not committed): every script, and the control plane, refuses them.

## Plans, trials and money

- One paid plan (Starter) and a free 3-day trial: `control/config/billing.php`.
- `trials:expire` runs every ten minutes:
  - a trial is warned a day before it ends, paused when it ends, and deleted
    `trial.grace_days` later;
  - a failed payment keeps Starter for `payment_grace_days` (7), then the
    account moves to free (paused), and `trial.lapsed_grace_days` (3) later
    its sites are deleted - each only after a final backup is confirmed.
- Paying at any point before deletion resumes everything as it was.
- A deleted paying customer's site: `artisan site:restore-deleted <site>`
  (from its final backup, kept 30 days; the account must be on a paid plan).
- Stock: `Stock::available()` counts paid plans only; trials use what is left.
  Monitoring alerts when fewer than `CIC_STOCK_ALERT_BELOW` (3) remain.

## Security guarantees, and where they are enforced

| Guarantee | Enforced by | Proved by |
|---|---|---|
| A visitor never gets `.env`, dotfiles, dumps, logs or project files | Caddy, in every vhost (`agent/internal/sites/create.go`, `secretPath`) - outside the tenant's reach | `tests/e2e/specs/secrets.spec.js` |
| Debug pages never show on a live site | `APP_DEBUG=false` in the container's environment, passed by Apache (`PassEnv`) | same |
| `.env` never moves into `public/` | the agent's move and copy (`refuseSecretIntoPublic`) | same, and `agent` unit tests |
| One tenant cannot reach another | own network, no capabilities, read-only root, per-site disk | `infra/verify-isolation.sh` (run by add-host) |
| Root file operations cannot be raced into the host | openat2 `RESOLVE_BENEATH` / handle-by-handle walk | agent self-test at start, unit tests |

## Measuring

- Capacity per plan: `tests/load/run.sh` and `tests/load/ws.sh` (they write
  `control/resources/capacity.json`, which the pricing page shows).
- Browser suite against production: `cd tests/e2e && npm run test:prod`.
- Control plane: `cd control && PAO_DISABLE=1 php artisan test`.
- Agent: `cd agent && go test -race ./...`.
