# codeinchrome

Hosting for Laravel sites where an **AI agent in the browser does the work** —
and the customer's code lives on a server they can take with them.

Read [ARCHITECTURE.md](ARCHITECTURE.md) first. It records not just what was
decided but what was rejected and why, which is the part that gets lost.

## Layout

| | |
|---|---|
| `infra/` | `bootstrap.sh` turns a bare Ubuntu box into a hardened host. Idempotent; verifies 17 post-conditions and refuses to claim success without them. |
| `agent/` | Go binary, one per host. Per-customer containers, Caddy vhosts, TLS, limits. |
| `control/` | Laravel 11. Accounts, billing, fleet, provisioning. |
| `panel/` | The browser panel and the `window.cic` agent API. Docs generated from source. |
| `tests/e2e/` | Playwright. Signup → provision → deploy → live. |

## Provision a host

```bash
ssh root@HOST 'bash -s' < infra/bootstrap.sh          # provision + verify
ssh root@HOST 'bash -s' < infra/bootstrap.sh --verify # verify only
```

## The rule this codebase is built around

Eight rounds of adversarial review produced one recurring defect: **a method
claiming more than its mechanism could support.** A canned fixture labelled as
rendering. A caller-declared number labelled as verification. `exit 0` labelled
as "tests pass". `bootstrap.sh` itself shipped "2G swap added" while `mkswap`
had failed.

So: every verdict carries the mechanism that produced it, and is only as strong
as the weakest link in that chain. Claims are **observed, never inferred from
"the step ran"**.
