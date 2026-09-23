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
- [x] **support@codeinchrome.com inbox**: Cloudflare Email Routing (MX, DKIM,
      merged SPF) forwards to the owner's verified Gmail; a DKIM-signed test
      from the control host landed in the inbox, not spam
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
- [x] **Email verification by one-time code**: a 6-digit code typed on the
      site (expiring, attempt-limited, single use), not only a link
- [ ] **Sign in with Google and Apple**: built and tested (an OAuth sign-in
      counts as a verified email; linking to an existing account only through a
      verified address). Apple is configured; Google waits for its client
      SECRET from the owner. A live round trip needs a person's own account.
- [x] **Themes**: light and dark, following the system by default, switchable,
      remembered; every page including the editor
- [ ] **Plans described in capacity, not CPU/RAM**: page views and concurrent
      visitors measured per plan (tests/load/run.sh) and shown with every step
      of the test. WebSocket connections: harness built (tests/load/ws.sh,
      ext-uv added to the image so Reverb is not capped at 1024 sockets) -
      to be measured after the image is rolled out
- [x] **Out of stock**: a plan cannot be bought, or a site created, when the
      fleet has no room for it; fails closed on stale host figures
- [x] **Behind Cloudflare's proxy**: site, app, apex and www records proxied;
      one Cloudflare Origin CA wildcard on the hosts (no per-site ACME, so no
      Let's Encrypt weekly limit); Full (strict); real visitor IP from
      CF-Connecting-IP trusted only from Cloudflare's ranges; __Host- session cookie
- [ ] **Custom domains through Cloudflare for SaaS** - the zone has no SaaS
      quota yet (API: "No quota has been allocated"); the owner switches it on
      in the dashboard (free for 100 hostnames). Then certificates from
      Cloudflare instead of on-demand ACME, and ports 80/443 firewalled to
      Cloudflare's ranges only. Customers' own domains work today via ACME;
      only names under codeinchrome.com share the exhausted weekly quota.
- [ ] **Background processes in the site container**: queue worker, scheduler
      and Laravel Reverb under supervisor; WebSockets routed through Caddy and
      Cloudflare. Built and unit-tested; deploy + end-to-end pending
- [ ] **Showcase store**, built live by Claude in Chrome through the editor and
      recorded step by step: a real-looking Laravel shop (products, cart,
      Stripe checkout in test mode) with an admin panel; the demo admin login
      published on the page but READ-ONLY. Needs a Stripe TEST secret key from
      the owner. The home page shows it as soon as SHOWCASE_URL is set.
- [x] **A home page that shows the product**: the editor and the agent at work
      (drawn in HTML, themed), what every site gets, measured capacity, plans
- [ ] **Editor parity with a hosting panel** (aaPanel as the yardstick).
      Done: recycle bin, upload/download, zip/unzip, search, rename/move/copy,
      database export/import (the database before an import is kept),
      customer backups with restore (backs up first, so undoable), queue/cron
      (scheduler)/WebSockets. Left: a permissions view, PHP settings
- [ ] **Git in every site, every change committed automatically** (agent 0.18: built and tested, deploy + e2e pending): every save,
      upload, move, delete, command and restore is a version; any earlier one
      opens read-only and restores as a new version; outside the container,
      never served, backed up with the site
- [ ] **Root file operations kernel-checked against symlink swaps** (agent 0.18, deploy pending): every
      read, write, upload, move, zip and unzip by the agent (root) goes through
      openat2 RESOLVE_BENEATH|NO_SYMLINKS / renameat2 NOREPLACE, so a site
      cannot race a folder into a link to the host. Tested as root on Linux.
- [ ] **Repository on GitHub, PRIVATE** (owner's choice; blocked on `gh auth login`; then
      `infra/publish-repo.sh --private OWNER/NAME` pushes a history-cleaned
      copy and refuses if any .env secret, private key or artifact is in it)

## The editor, to VS Code's standard (owner's request, 2026-09-23)

Every piece open source, licence checked before use; nothing assumed working
until it is exercised in the browser against a real site.

- [x] **Monaco (MIT) as the editor core** - VS Code's own editor: its look,
      keybindings, multi-cursor, find/replace, minimap, and built-in
      IntelliSense for JS/TS, CSS, HTML and JSON. Keeps every current
      guarantee: revision-checked saves, drafts, no native dialogs,
      window.cic unchanged. Must run under the strict CSP (workers from
      self; its styles without opening script-src).
- [x] **File icons**: Material Icon Theme (MIT, icons included) - folder and
      file icons by name and extension, as in VS Code. (vscode-icons' artwork
      is CC BY-SA, which would oblige share-alike; Material is cleaner.)
- [x] **PHP IntelliSense**: Phpactor (MIT) as a language server inside the
      site's own container (so it sees the site's vendor/), bridged to Monaco:
      completion, hover, go to definition, diagnostics. Intelephense is not
      open source and is not used. Done: e2e-tested on a fresh site (hover,
      definition into vendor/, Str::slug completion); the same answers for
      agents via cic.php.complete/hover/definition. Found and fixed on the way:
      lowercased URIs, incremental edits to a full-sync server, Phpactor temp
      files filling the container's /tmp, orphaned servers holding site memory.
- [x] **Laravel awareness**: Blade highlighting (PHP inside {{ }}); route(...)
      and view(...)/@include/@extends complete the site's real route and view
      names; F12/⌘-click on a view name opens the Blade file (e2e-tested).
      Not yet: <x-...> component names, config() keys, translation keys.
- [x] **Laravel Boost MCP (MIT) for the agent**: window.cic.mcp.tools() and
      .call() - routes, schema, config, docs search, last errors - run in the
      site's container as the site's own user. Done: in every new site's
      skeleton; only that process runs as "local" (Boost refuses production),
      the site stays production with debug off; writes refused.
- [x] **Previews**: images (checkerboard, size), PDFs rendered with pdf.js
      (Apache-2.0, lazy-loaded) - e2e-tested. Markdown preview not yet.
- [ ] **UI polish pass** (in progress): codicon buttons; Monaco isolated from
      page CSS (a '.tree' rule and the global reset were hiding suggestion
      rows); the minimap no longer shows through the Database view; light
      theme reviewed by screenshot. Next: keyboard walk-through of the whole
      editor, empty states, focus rings on custom widgets.
