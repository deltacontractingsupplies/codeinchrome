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
- [ ] **Custom domains**: ownership verified by DNS, certificate on demand
- [ ] **Billing checkout**: plan upgrade through Lemon Squeezy checkout
      (blocked on products existing - dashboard-only)
- [ ] **Artisan / composer from the editor**: a fixed allow-list of commands,
      run inside the site's container, output streamed back
- [ ] **Logs**: the site's Laravel log and access log readable in the editor
- [ ] **Account security**: email verification, password reset, rate limits
- [ ] **Monitoring**: host health and per-site availability, alerting
- [ ] **Control plane database backups**
- [ ] **Public repository** (blocked on `gh auth login`)
