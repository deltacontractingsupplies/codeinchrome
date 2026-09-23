# Roadmap

What "done" means for codeinchrome, as a checklist. An item is ticked only when
it is built, tested, deployed, and verified against production - not when the
code exists.

## Built and verified

- [x] Host bootstrap with verified post-conditions, reboot-safe
- [x] Per-site containers: own network, no capabilities, read-only root, ceilings
- [x] Tenant isolation proved on live hosts, with positive controls
- [x] Automatic HTTPS, requested on first connection behind an ownership gate
- [x] Control plane on its own host; restricted tunnel keys
- [x] Signup, plans, provisioning, DNS, fleet status/audit/reap
- [x] Billing webhook: signed, replay-safe, entitlement rules
- [x] In-browser editor with revision-checked saves; `window.cic` agent API
- [x] End-to-end suite against production

## Remaining

- [x] **Database per site** - MySQL per host, one database and one user per
      site, privileges limited to its own database, unreachable from the
      internet and from other tenants; credentials written into the site's
      `.env`; dropped with the site
- [x] **Database browser** in the editor (tables, rows, queries as the site's
      own user) instead of a public phpMyAdmin
- [x] **Disk quotas** per plan, enforced by the kernel and reported; applied
      to existing sites on plan change, retried if a host is down
- [x] **Backups**: nightly files + database per site, off-host, encrypted,
      restore and control-host-only recovery drilled
- [x] **Append-only backup storage**: hosts can add snapshots but never
      delete one; retention runs only on the control host
- [x] **Custom domains**: ownership proved by a DNS TXT record, A record
      checked, certificate on first visit; verified end to end with a real domain
- [x] **Billing checkout** built and validated against the live API; the
      billing portal link comes from the webhook
- [x] **Billing products** (test mode): Starter/Pro/Studio in the codeinchrome
      store (USD), webhook with every event, checked by `infra/setup-billing.sh`;
      a new customer paying end to end is `tests/e2e/specs/billing.spec.js`
- [ ] **Store activation** (owner: identity verification in Lemon Squeezy), then
      the same products copied to live mode and `setup-billing.sh` re-run
      with the live key
- [ ] **support@codeinchrome.com inbox**: Cloudflare Email Routing is set up
      (MX, DKIM, merged SPF); waiting on the destination address being
      verified, then the forwarding rule
- [x] **Artisan / composer from the editor**: a fixed allow-list, run in the
      site's container as www-data; destructive commands need confirm
- [x] **Logs**: Laravel log, request log and PHP/Apache output in the editor
- [x] **Account security**: TOTP two-factor (RFC-vector tested, replay-proof,
      per-account lockout), recovery codes, password change that signs out
      other sessions, account deletion that removes every site first
- [x] **Password reset and email verification**, over the platform's own mail:
      send-only Postfix on the control host (loopback only), every message
      DKIM-signed, SPF/DKIM/DMARC published (`infra/setup-mail.sh`). Creating a
      site, a domain or a subscription waits for a confirmed address
- [x] **Monitoring**: every host and site checked each minute from outside;
      incidents after two failures; alerts to a webhook if `CIC_ALERT_WEBHOOK` is set, otherwise by email to the operators
- [x] **Control plane backups**, and every backup replicated to a second host
      that refuses deletion; full recovery drilled from the replica
- [x] **Audit log**: who did what to which site, when - provisioning,
      deletion, domains, commands, database writes, 2FA and password changes
- [x] **Security patches reach running sites**: rebuilding the base image does
      not change running containers, so hosts rebuild weekly with --pull and
      fleet:roll-image moves sites one at a time, checked from outside
- [x] **Security headers and a strict Content-Security-Policy** on the control plane
- [x] **Dependency vulnerability scanning** in CI, weekly as well as on push
      (composer audit, npm audit, govulncheck with the toolchain pinned)
- [ ] **Email verification by one-time code**: a 6-digit code typed on the
      site (expiring, attempt-limited, single use), not only a link
- [ ] **Sign in with Google and Apple**: OAuth apps in the owner's Google Cloud
      and Apple Developer accounts; an OAuth sign-in counts as a verified email;
      linking to an existing account only through a verified address
- [ ] **Themes**: light and dark, following the system by default, switchable,
      remembered; every page including the editor
- [ ] **Plans described in capacity, not CPU/RAM**: concurrent visitors on a
      typical Laravel app and live WebSocket connections per plan, measured by
      real load and stress tests on each plan's limits; disk stays; a "view
      more" with the actual test results
- [ ] **Out of stock**: a plan cannot be bought, or a site created, when the
      fleet has no room for it; shown as out of stock rather than failing
      after payment
- [x] **Behind Cloudflare's proxy**: site, app, apex and www records proxied;
      one Cloudflare Origin CA wildcard on the hosts (no per-site ACME, so no
      Let's Encrypt weekly limit); Full (strict); real visitor IP from
      CF-Connecting-IP trusted only from Cloudflare's ranges; __Host- session cookie
- [ ] **Custom domains through Cloudflare for SaaS** (needs it switched on for
      the zone): certificates from Cloudflare instead of on-demand ACME, and
      then the hosts' ports 80/443 firewalled to Cloudflare's ranges only
- [ ] **Background processes in the site container**: queue worker, scheduler
      and Laravel Reverb under a small supervisor, WebSockets routed through
      Caddy and Cloudflare; per-plan connection limits measured
- [ ] **Showcase store**, built live by Claude in Chrome through the editor and
      recorded step by step: a real-looking Laravel shop (products, cart,
      Stripe checkout in test mode) with an admin panel; the demo admin login
      published on the page but READ-ONLY; test orders with Stripe's test card
- [ ] **A home page that shows the product**: the editor, the agent driving it,
      the showcase store and its recording, plans and what they really serve
- [ ] **Editor parity with a hosting panel** (aaPanel as the yardstick): a
      recycle bin (deleted files restorable for a period), upload/download,
      zip/unzip, search, rename/move, permissions view, cron, and anything else
      missing - each audited, built and tested
- [ ] **Git in every site, every change committed automatically**: nothing lost,
      any earlier version restorable from the editor; kept in the site's own
      volume and backups; never served over HTTP
- [ ] **Repository on GitHub, PRIVATE** (owner's choice; blocked on `gh auth login`; then
      `infra/publish-repo.sh --private OWNER/NAME` pushes a history-cleaned
      copy and refuses if any .env secret, private key or artifact is in it)
