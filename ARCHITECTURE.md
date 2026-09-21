# Architecture

Hosting for Laravel sites where an **AI agent in the browser does the work**, and
the customer's code lives on a server they can take with them.

Every decision below was made deliberately; where an obvious alternative was
rejected, the reason is recorded, because the reason is what future maintainers
need and it is the thing that gets lost.

## The shape

```
                      ┌─────────────────────────────┐
  browser  ──────────▶│  panel  (static, per host)  │
  + Claude in Chrome  │  window.cic  →  agent API   │
                      └──────────────┬──────────────┘
                                     │ HTTPS, token per host
   ┌─────────────────┐        ┌──────▼──────────────────────────────┐
   │ control plane   │◀──────▶│ cic-agent (Go, one per host)        │
   │ Laravel 11      │  mTLS  │  · per-customer Docker containers   │
   │ · accounts      │        │  · Caddy vhosts + automatic TLS     │
   │ · Lemon Squeezy │        │  · shared MySQL, per-customer user  │
   │ · provisioning  │        │  · egress + resource limits         │
   └─────────────────┘        └─────────────────────────────────────┘
```

## Decisions, and what was rejected

| Decision | Why | Rejected |
|---|---|---|
| **Packed multi-tenant hosts**, not one VM per customer | 99 sites on one 15 GB box is proven on the owner's existing fleet. ~98% gross margin vs ~65%. The four boxes already owned *are* the business until full. | One VM per customer — kept as a paid "Dedicated" tier, not the default |
| **Container per customer** | Mount/PID/network namespaces are a real boundary. aaPanel's default runs every site as one `www` user with `open_basedir` as the only isolation — measured on a live box: 31 mutually-readable `.env` files. That failure is what this product exists to prevent. | Per-site Linux users — weaker, and the thing we sell against |
| **Our own base images**, not Nixpacks/Railpack | Nixpacks images are 800 MB–1.3 GB each with no layer sharing; five sites exhausts a 40 GB disk. Shared base + thin app layer is ~50 MB per site. Railway abandoned Nixpacks over exactly this. | Nixpacks, Railpack, Dokku, Coolify (750 MB–1.2 GB RAM before a single site) |
| **Laravel + PHP only** | Lovable/Bolt/v0 are Node-only. Laravel, and later Rails/Django, is the wedge they cannot serve. One runtime is also one attack surface. | Polyglot on day one |
| **Go for the agent** | Single static binary on the customer host: no runtime to patch, tiny surface, trivial to ship. | Node/PHP agent — another runtime to secure on every box |
| **Laravel for the control plane** | It is where most future work happens and it is the owner's home ground. Maintainability beats novelty. | Go everywhere |
| **Shared MySQL, per-customer user + strict grants** | ~10 MB per database instead of ~300 MB per container. The difference between 50 customers a box and 15. | MySQL container per customer — kept for the Dedicated tier |
| **x86 CX, not ARM CAX or CPX** | After the June 2026 adjustment CX23 is €5.49 vs CAX11 €5.99 for the same specs, and x86 avoids native-extension pain. CPX rose 2.4–2.75x. | CAX (ARM), CPX, CCX |
| **Hetzner, behind a provider interface** | Reselling is explicitly permitted in their terms. Contabo was disqualified on evidence: first orders take up to 3 hours, and fresh VPSs get suspended over prior-tenant IP abuse with a ~€30 reactivation fee. | Contabo, KernelHost (€9.99 for half the specs) |

## Why the agent API is the product

Claude in Chrome has **no filesystem and no shell**. It has the page. So every
action a human can take in the panel is also one documented call, and the panel
is built so an agent can drive it without reverse-engineering private state.

That contract lives in `panel/` and is documented at `/llms.txt`,
`/llms-full.txt`, `/cic-api.json` and `/cic-agent-skill.md`. **The docs are
generated from the source of truth by `build-docs.mjs` — never hand-edited.**

Eight rounds of adversarial review by a second agent produced one recurring
defect worth carrying forward:

> **A method claiming more than its mechanism could support.** A canned fixture
> labelled as rendering. A caller-declared number labelled as verification. A
> fabricated 200 labelled as wiring. A regex over `<x-` tags labelled as a
> production 500. `exit 0` labelled as "tests pass".

Two rules follow, and they apply to new code in this repo:

1. **Authority is earned per claim, not per method.** Default to
   *undecidable* unless every mechanism has been positively excluded.
2. **Every verdict carries the mechanism that produced it** (`basis`), and is
   only as strong as the weakest link in its chain.

`cic.audit()` enforces this as a runnable suite: a field is **unearned** until a
probe designed to make it lie has failed to do so.

## Security posture

The threat is not a clever attacker first — it is **an abusive customer getting
the host account suspended**, which takes every other customer offline. Hetzner
names cryptocurrency mining as prohibited, and the account holder is liable for
everything third parties do.

- Container per customer: separate mount, PID and network namespaces
- `cgroup` CPU and memory ceilings — mining becomes arithmetic, not policing
- Outbound SMTP (25/465/587) blocked; mining pools and Tor unreachable
- No inbound ports but 80/443, everything through the proxy
- `.env` is never a file an agent can read: `cic.setSecret()` writes,
  `cic.readSecret()` always refuses
- Document root cannot be set above `/public` — the panel does not offer it
- Abuse detection before launch, not after

## Repository layout

```
agent/      Go binary, one per host. Sites, containers, proxy, jobs, limits.
control/    Laravel 11. Accounts, billing, provisioning, host fleet.
panel/      The browser panel and the cic agent API. Docs generated from source.
infra/      Idempotent host provisioning. bootstrap.sh turns bare Ubuntu into a host.
tests/e2e/  Playwright. The whole path: signup → provision → deploy → live.
docs/       Operator runbooks.
```
