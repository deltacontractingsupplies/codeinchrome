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
- [x] **Billing products** (test mode): the codeinchrome store (USD), webhook
      with every event, checked by `infra/setup-billing.sh`; a new customer
      paying end to end is `tests/e2e/specs/billing.spec.js`
- [x] **One plan and a trial** (owner's decision, 2026-09-23): Starter at $20 -
      3 sites, each up to 1 CPU and 640 MB, 10 GB of storage for files and
      databases together - with a visible count of how many are left. The free
      plan is a 3-day trial with no card that reserves nothing: paused when it
      ends, deleted 2 days later, resumed whole by paying (trials:expire, agent
      suspend/resume, tests/e2e/specs/trial.spec.js). Plan storage is enforced
      from measured usage (no new sites or bulk uploads while over); R2/S3
      guidance on the pricing and settings pages and in cic.help. Accounts from
      before trials keep no clock.
- [x] **Starter's price in Lemon Squeezy** - moot: Starter is back to $12, what
      Lemon Squeezy already charges (checked on the live checkout by the billing
      e2e). The checkout's description is now written from config/billing.php on
      every checkout (Checkout::description), so it never drifts and never shows
      CPU or memory. Optional, owner: archive the unused Pro and Studio products
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
- [x] **Plans described in measured capacity** (no CPU or memory figures since 2026-09-24): page views and concurrent
      visitors measured per plan (tests/load/run.sh) and shown with every step
      of the test. WebSocket connections: harness built (tests/load/ws.sh,
      ext-uv, and a 65536 open-file limit for WebSocket sites) - measured:
      starter 4,000, pro and studio 10,000+ connections, shown on every plan
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
- [x] **Background processes in the site container**: queue worker, scheduler
      and Laravel Reverb under supervisor; WebSockets routed through Caddy and
      Cloudflare. Proven end to end: a queued job run by the worker and a
      scheduled task firing (tests/e2e/specs/background.spec.js); Reverb by
      the WebSocket load test
- [x] **Showcase stores**, built live through the editor and recorded: Ember &
      Oak (coffee) and Petal & Stem (flowers, built by Claude in Chrome), each
      with an admin panel whose published login is READ-ONLY, and all their
      code readable. Checkout is cash only (owner's choice, 2026-09-24), so no
      payment keys are involved
- [x] **A home page that shows the product**: the real editor showing a demo's
      live code, what every site gets, the editor's extensions, the demos,
      measured capacity, plans
- [x] **Editor parity with a hosting panel** (aaPanel as the yardstick).
      Done: recycle bin, upload/download, zip/unzip, search, rename/move/copy,
      database export/import (the database before an import is kept),
      customer backups with restore (backs up first, so undoable), queue/cron
      (scheduler)/WebSockets, PHP settings (memory, execution time, uploads;
      bounded by the plan, mounted read-only - e2e-tested), and a properties
      view (permissions, size, modified; cic.stat for agents).
- [x] **Git in every site, every change committed automatically** (deployed; e2e-tested: save, restore, bin, backup restore recorded): every save,
      upload, move, delete, command and restore is a version; any earlier one
      opens read-only and restores as a new version; outside the container,
      never served, backed up with the site
- [x] **Root file operations kernel-checked against symlink swaps** (deployed; the agent refuses to start unless they work in its sandbox): every
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
- [x] **UI polish pass**: codicon buttons; Monaco isolated from page CSS;
      minimap no longer shows through the Database view; light theme reviewed;
      WCAG 2.1 AA audit (axe-core) of every customer page in both themes, in
      the e2e suite - its only finding (nested controls in the file tabs)
      fixed; the file tree fully keyboard-operable (ARIA tree pattern).
- [x] **Laravel names**: config() keys and <x-> components complete too;
      Markdown preview, sanitised (marked + DOMPurify).
### The explorer and workbench, as VS Code does them (owner's request, 2026-09-24)

VS Code's own source (MIT, github.com/microsoft/vscode) is the reference for
behaviour: read its explorer and workbench code and match it, rather than guess.

- [x] **Collapse**: "Collapse Folders in Explorer" button (⌘←), ⌘B hides the side bar; collapse/expand the
      side bar (⌘B), the panel, and each tree section
- [x] **Right-click menus** (VS Code's groups and shortcuts, the tab bar's too): on a folder - New File, New Folder, Reveal,
      Copy Path, Copy Relative Path, Rename, Delete, Download, Upload here,
      Find in Folder; on a file - Open, Open to the Side, Rename, Duplicate,
      Delete, Copy Path, Download, History; on the tab bar - Close, Close
      Others, Close All, Close Saved
- [x] **Inline rename and create in the tree**: F2 / Enter renames in place,
      the file name selected without its extension, Escape cancels, invalid
      names shown inline; New File / New Folder open an input row inside the
      folder, as in VS Code (no prompts, no dialogs - agents cannot dismiss them)
- [x] **Drag and drop**: move files and folders in the tree; drop files from
      the desktop to upload into a folder
- [x] **Open-source extensions, licence checked** (Tailwind class completion is our own, from its documented scales, offered only on sites that use it): Emmet (emmet-monaco-es,
      MIT); Prettier formatting (MIT, browser build) for JS/CSS/JSON/Markdown and
      PHP via its plugin; Tailwind CSS IntelliSense class completion (MIT);
      format on save as a setting
- [x] **Quick Open (⌘P), the Command Palette (⌘⇧P) and Go to Symbol (⌘⇧O)**, and
      search across files in the side bar with Replace All (one version, undoable)
- [x] **Explorer details**, compact folders included: modified/unsaved dots, the open file revealed
      and highlighted, compact folders, sticky scroll, breadcrumbs

### Agents build sites through the editor, fast (owner's request, 2026-09-24)

- [x] **A skill any agent can load** (skills/codeinchrome/SKILL.md): open the
      site's editor and build ONLY through window.cic - never locally
- [x] **The editor tells an agent so itself**: instructions in the page an
      agent reads (text and accessibility tree), /llms.txt on the app
- [x] **Fewer, bigger calls**: cic.writeMany (one call, one version for many
      files), cic.edit (find/replace without resending a file), cic.http
      (request the live site from the editor: status, headers, body) so an
      agent can test its own pages without cross-origin failures
- [x] **Measured with real agents** (2026-09-24, four runs, each fixing what the
      last one hit: inventory 13 calls ~4.5 min; booking 19 calls ~9 min; CRM with
      login 29 calls ~15 min; blog with admin 11 calls ~4.5 min, zero problems.
      Fixes they drove: sign-in links that switch accounts, cic.view for results
      browser tools would block, cic.eval, test sign-in as a site user, the 2 s
      opcache wait, return shapes in the skill): fresh Opus and Sonnet agents given only
      the skill and "build an inventory system" through Claude in Chrome;
      time taken, calls made, mistakes - the skill and API tightened until a
      five-file change takes minutes, not an hour

### Security hardening (2026-09-24)

- [x] **Secrets refused at the edge**: Caddy returns 404 for dotfiles, .env
      under any name, dumps, logs and project files, before the site's own
      Apache - whose rules the tenant's .htaccess can override
- [x] **Debug pages impossible on a live site**: APP_DEBUG forced off by the
      container's environment (beats .env), passed to PHP by Apache
- [x] **Independent security review of the new code** (2026-09-24). Found and
      fixed: the edge guard exempted ALL of /.well-known/ (Caddy ANDs a
      matcher's conditions), so a dump or key placed there was served - now
      two matchers, and the e2e test places one there; bulk writes skipped the
      storage limit. Found on the way: an unknown platform name answered an
      empty 200 once the origin wildcard certificate was in (now 404); health
      checks followed an app's https redirect and called a healthy site DOWN.
- [x] **A .env never moves or copies into public/** under any name.
      All three proved on production by tests/e2e/specs/secrets.spec.js: a
      hostile .htaccess, APP_DEBUG=true and a thrown exception, .env moved

### Owner's requests, 2026-09-24 (afternoon)

- [x] **Starter back to $12** (what Lemon Squeezy already charges) with LESS CPU
      and memory per site, so more fit and a full fleet is profitable: 0.5 CPU,
      384 MB, 51 Starters on today's fleet
- [x] **Customers see measured capacity only** - no CPU or memory figures on
      any page (asserted by CapacityTest and on the checkout); the stress-test
      numbers and how they were measured instead. Re-measured at the new limits
      (2026-09-24): 60 page views/s at p95 10 ms (80/s fails: the CPU quota caps
      delivery at ~60/s; it was 160/s at 1 CPU and 640 MB), and 6,000 WebSocket
      connections at p95 330 ms (8,000: p95 501 ms). No OOM kills
- [x] **The home page shows the REAL editor**: its look, its Material file
      icons, and live code from a demo site (read-only), so it changes when the
      demo does - not a drawing. <x-code-window> (App\Showcase\DemoSource,
      App\Support\FileIcons): the same component on /demos/*/code; cached ten
      minutes, and a host outage shows the last good code, never an error
- [x] **No 500 anywhere**: every page of the platform and both demos crawled,
      signed out and signed in (2026-09-24). Petal & Stem's
      /admin/products/create fixed (a null name into FlowerArt); Petal & Stem
      clean as a visitor (31 pages) and as its admin (106); Ember & Oak clean as
      its admin (33, session from login-cookie, no password); the platform clean
      signed out (69, infra/crawl-public.py) and signed in (148)
- [x] **Ember & Oak has `app:check` and real tests** - added through the editor
      in Chrome, signed in as the showcase account by a one-time link issued on
      the server (no password typed), then switched back the same way. 8 feature
      tests (catalogue, cash checkout taking stock in a transaction, bad phone,
      empty bag, sold out meanwhile, admin sign-in, the read-only demo login)
- [x] **Petal & Stem's 45 tests run, and pass** (they never had: `artisan test`
      was broken). RefreshDatabase removed from 4 files (owner's rule: never, in
      any project); the in-memory TestCase instead (schema built once with a
      plain migrate, each test rolled back, refuses any database but
      :memory:); the demo switch off in phpunit.xml and 2 tests for it on;
      one test fixed that read a flash message as the product listing. 47 pass
- [x] **A test run can never reach the live database through DB_URL** - Laravel
      lets a URL override driver and host on every connection, and only
      phpunit.xml blanked it; the agent forces it empty (0.26.3, deployed; e2e:
      phpunit.xml without the line and a MySQL DB_URL in .env still test in memory)

### Owner's requests, 2026-09-24 (evening)

- [x] **Say it works with Claude in Chrome**, everywhere a visitor decides: the
      home page, the plans and the editor - the extension builds the site in the
      editor, in the browser, with nothing to install on the computer. Wording:
      "works with / built for Claude in Chrome", not "powered by" (it is not an
      Anthropic product or partnership); link to the official page only once its
      exact address is confirmed
- [x] **Every plan line says per site or per account, and is exact**: visitors
      and WebSockets are PER SITE, measured on one site at its limits; "visitors
      at once" says how it is derived (page views a second x 10 seconds a
      visitor spends on a page); "up to", because CPU is shared and busy
      neighbours can take some of it. Checked against the code and
      capacity.json, pinned by CapacityTest and BillingTest
- [x] **Starter storage is 5 GB PER SITE** (owner's decision: fair, and no loss):
      each site's files capped at 5 GB by its own disk, files and databases
      15 GB in all. Kept profitable by overcommitting disk 2.5x - site disks are
      sparse and use 0.6-0.8 GB of their quota (measured), hosts were 6-7% full
      - so a full fleet is still memory-bound: 51 Starters available, as before
      (was 25 at 1x). The host disk alert moved from 10% to 25% free, in time
      to move sites

- [x] **Getting started with Claude in Chrome, step by step, on the home page**:
      install the extension (linked straight to its official Chrome Web Store
      listing), sign in to Claude, open your site's editor, pin the side panel,
      ask for what you want - each step checked against Anthropic's own
      help pages, never assumed
- [x] **Say plainly what the customer pays for, and to whom**: Claude in Chrome
      needs a SEPARATE Claude subscription from Anthropic (the plans that
      include it and their prices, verified on claude.com, never assumed);
      codeinchrome sells the hosting and the editor only. Shown beside our own
      plans so nobody expects the agent to be included

- [ ] **Confirm support@codeinchrome.com receives mail** (owner: Cloudflare
      dashboard, Email Routing): the terms, privacy and refund pages and
      security.txt all publish it; mail for the domain goes through Cloudflare
      Email Routing, whose rules our API token cannot read, so it is unverified
- [x] **security.txt (RFC 9116)** at /.well-known/security.txt: contact, an
      Expires kept a year ahead, canonical address, policy. LegalTest

- [x] **Paid plans switched OFF until Lemon Squeezy approves the store** (owner's
      request, 2026-09-24): free only - no paid option shown or sold anywhere
      (home, pricing, billing, register, terms; checkout refuses) - behind ONE
      switch, CIC_PAID_PLANS_OPEN in .env (App\Billing\Sales). While off no
      trial runs out (owner's choice); a customer who already pays keeps and
      sees their plan. To open: set it true, deploy, `php artisan
      trials:restart` (every free account: a fresh trial and an email).
      SalesTest (mutation-checked); verified live
- [ ] **Public repository** (owner's decisions, 2026-09-24): PUBLIC, under the
      owner's GitHub account (deltacontractingsupplies - the owner chose it over
      a separate organization); FSL-1.1-ALv2 (no competing use, each
      version Apache-2.0 after 2 years), Copyright 2026 Ahmed Omar; contributor
      agreement (CLA.md); commits keep the owner's own identity. Done and
      verified: no server address, no other business, no secret in any commit
      (publish-repo.sh: scrub, .env values, keys, deny list, gitleaks);
      CONTRIBUTING, SECURITY, CODEOWNERS, PR template; infra/github-setup.sh
      (reviewed pull requests only, CI required, secret scanning); the
      contributor-agreement check as a pinned in-repository action (no external
      app), signatures on branch cla-signatures. LEFT, in order: owner creates
      `gh auth login` (workflow scope); infra/go-public.sh

### Found while verifying, 2026-09-24
- [x] **The legal pages say what the service really does** (audited 2026-09-24):
      the privacy policy said Cloudflare did DNS only - every site and the app
      are behind its proxy, so it carries all traffic, visitors' IPs included;
      now said. New: we send nothing to any AI provider (checked: no AI API call
      anywhere in the platform's code); an AI agent is a separate service under
      its provider's terms, paid and refunded there (privacy, terms, refunds).
      Backup retention checked against the job (7 daily, 4 weekly, 3 monthly;
      30 days after deletion; ran today). LegalTest pins it
- [x] **A second fresh agent (Sonnet, only the skill)**: a "Today" page for
      barber-b behind the app's own login, 17 min, every check clean, screenshot
      taken. What it hit, fixed: every cic.view page now ENDS with a line saying
      which lines it holds and the exact next call (a page cut short by a tool
      is noticed; `next` vs `from` no longer confuses); cic.show(text, part)
      reads any long text (a page's HTML) safely; an app with no Laravel users
      (a shared password) - `as` now fails once with a clear hint, and
      cic.check({ session: true }) / cic.lookUrl(path, { session: true }) use a
      login the agent did itself through the app's form (the session is kept
      encrypted, one header, no line breaks)
- [x] **Independent security review of the day's new code** (2026-09-24, a
      separate reviewer, read-only): the page preview (sandbox, token, redirects),
      the public code viewer (allow-list, symlinks, escaping), log reading by
      offset, artisan test's forced environment, the editor's new calls, the
      firewall and backup scripts, backup monitoring. No issue found; each area's
      reasoning recorded in the review
- [x] **The public demo login showed real customers' names and phone numbers**
      (Petal & Stem's orders: someone's real order today). While demo mode is
      on, every personal field reads masked (App\Support\DemoPrivacy, model
      accessors, so no view can forget one) and the admin search looks up order
      numbers only (a phone typed in must not confirm an order). Tests for both,
      and for the owner seeing everything outside demo mode; verified live.
      Ember & Oak's demo admin scanned: nothing phone- or email-shaped
- [x] **See a page behind the app's own login** (a simulated agent could not):
      `cic.lookUrl(path, { as })` gives a 10-minute address (an unguessable
      token, bound to the site and the owner) that shows the page as the site's
      user in a real tab, fetched on the server - the site's session never
      reaches the browser - and served sandboxed (opaque origin, no script, no
      forms; checked live: origin "null", cookies throw). LookTest, mutation-checked
- [x] **The browser tool's limit is 1,000 characters, not 1,500** (measured):
      cic.view and cic.help now page to fit it, cic.view takes `match` to read
      a file's outline in one call, and cic.help is no longer blocked
- [x] **Measured with a fresh agent after today's changes** (Sonnet, only the
      skill): a tasks feature for crm-c - migration, model, controller, 2 views,
      nav, styles, 7 feature tests, app:check - in 14 min; every check clean.
      What it hit is fixed above

- [x] **A stale lock stopped a host's backups, silently.** The deploy's
      append-only probe (`restic forget`, which must fail) took an exclusive lock
      that was not released; h4's repository refused everything after it, and
      the next deploy failed ("config file already exists": a lock read as a
      missing repository). Fixed: the probe releases stale locks, setup
      initialises only on restic's "does not exist" (exit 10), the nightly run
      clears stale locks first. h4 backed up again and verified
- [x] **Backups are monitored**: fleet:sync-backups (hourly) records each live
      site's newest COMPLETE backup (files and database) as the backup server
      lists it; monitoring alerts when one is older than 30 hours
- [x] **Every host deploy cut its sites off MySQL for a moment**: bootstrap ran
      `ufw --force reset`, dropping the containers' route to the database (and
      filtering) until a later step restored it. Now converged, never reset.
      Measured: 3-6 refused connections per deploy before, 0 after (h1, h3)
- [x] **The skill gives every app a safe TestCase** (the skeleton's ExampleTest
      fails on the empty in-memory database) and an app:check that prints paths
      right (`/`, not `//`)
- [x] **Petal & Stem in the demos**: its admin read-only for the published
      login (every write route refused - proved with a VALID order update and a
      delete, database unchanged), the 3 "E2E Test" orders its agent left
      cancelled, its build leftovers (zips, staging copy) out of the code view;
      its 80 published files checked for secrets and personal details
- [x] **From the browser agent's handoff note** (larashop-HANDOFF.md): allow
      `artisan test`, forced onto an in-memory database so a test can never
      touch live data (it never ran at all until agent 0.26.2: PHPUnit refused
      the --no-interaction every command carried); editor API answers never
      cached (it had to add &t= to see fresh content); Upload goes to the
      selected folder, not always the root
- [x] **The agent never found window.cic** (same note) - the page's
      instructions must be the first thing any agent reads. Cause found: page-text
      tools (Claude in Chrome's get_page_text) read <main> only, and the
      instructions were outside it. Now the first thing inside <main>
      (AgentInstructionsTest), verified with get_page_text on production

- [x] **An Extensions view in the editor**, as VS Code's: every built-in extension
      listed with what it does, its project and licence, and a switch - PDF and
      image preview (pdf.js), Markdown preview, PHP IntelliSense (Phpactor),
      Emmet, Prettier, Tailwind classes, Material Icon Theme, Laravel names,
      Laravel Boost MCP - and the same list shown on the home page
- [x] **Every page checked by agents**: cic.check() (all routes and links, as a
      visitor and signed in, plus exactly the errors the app logged DURING the
      check - an exact log mark, agent 0.26.1 LogsSince; it used to report old
      errors as new), an app:check command in every app the skill builds, and
      `artisan test` on a safe in-memory database. A busy site is waited for,
      not failed

### Only public/ is public - shown, and proved (owner's request, 2026-09-24)

- [x] **The editor shows what is served**: the public/ folder and everything in
      it carries a "public" badge with its live URL; every other file says it is
      private and never served; .env says so explicitly
- [x] **"Check what is public"** in the editor (and cic.exposure() for agents):
      the control plane asks the LIVE site, through Cloudflare like any visitor,
      for /.env and its variants, .git, logs, dumps, lock files, traversal tricks
      (/%2e%2e/.env, /public/../.env) and every secret-shaped file inside
      public/; each must be refused, and no answer may contain the site's real
      APP_KEY or database password (compared on the server, never sent out)
- [x] **The same check for every site, on a schedule** (daily, 05:15; proved on a
      real site with a planted leak, which it failed and named), alerting the operator
      on any failure
- [x] **Public demo code** (/demos/{demo}/code): read-only source of the demo
      stores, allow-listed, secrets withheld - configured demo sites only

### Scale by adding a server, nothing else (owner's request, 2026-09-24)

Today a host is written into four places by hand (config/fleet.php hosts and
tokens, infra/hosts.env, the tunnel units). Adding capacity must be one step.

- [x] **Hosts come from one registry** (infra/hosts.env, not a list in
      code - chosen over a database table: every shell script already reads
      it), with CIC_HOST_STATES for draining. Originally planned as: hosts in the database, not in config: name, address, tunnel
      port, capacity, state (joining / active / draining / retired), agent
      token encrypted at rest. The fleet config keeps only the forbidden list
- [x] **`infra/add-host.sh <ssh address>`** (run for real against h4:
      deploy, register, proxy, control, then a probe site served over HTTPS
      and all 16 isolation claims DENIED): refuses the forbidden hosts,
      bootstraps and hardens the box, installs the agent and image, proves
      isolation (verify-isolation.sh), registers it with the control plane,
      opens its tunnel (a systemd template unit per host) and starts it
      taking sites - with no edit to any file in the repository
- [x] **Stock grows by itself**: a new host's real CPU, memory and disk are
      counted as soon as its monitor report arrives; the "N available" count
      rises without a deploy
- [x] **Draining and retiring a host** (fleet:drain; CIC_HOST_STATES): no new sites; its sites moved to other
      hosts one at a time (files, database, DNS), verified, then the host retired
- [x] **Site migration between hosts** (fleet:move-site; files packed inside the site's own container) (needed for draining and rebalancing):
      stop writes, copy disk image and database, start on the target, switch
      DNS, verify from outside, then remove the source
- [x] **Everything per-host is looped, never listed**: backups, monitoring,
      image rolls, audits, reconcile read the hosts table
- [x] **Capacity alerts** (host disk already; paid stock below CIC_STOCK_ALERT_BELOW): tell the operator when free stock or any host's
      disk/memory crosses a threshold, before it sells out

### Paid data is never lost (owner's rule, 2026-09-24)

- [x] **A failed payment keeps the service for 7 days** (past_due, unpaid):
      the customer keeps Starter and is emailed at once, and again before it ends
- [x] **After 7 days unpaid: the account moves to free**, which pauses its
      sites (not served, not editable, files and database kept)
- [x] **3 days after that, the sites are deleted** - with a final backup of
      every site that ever belonged to a paying customer kept for 30 days
      first, restorable by an operator (`site:restore-deleted`)
- [x] **Paying at any point brings everything back** as it was (e2e: trial.spec.js)
- [x] **A cancellation runs to the end of the paid period**, then the same
      pause-then-delete, with the emails saying the dates
- [x] **Deletion is never the first failure**: if the final backup did not
      complete, the site is not deleted, and the operator is alerted

- [x] **A lost host's sites come back from backups** - infra/recover-host.sh
      (drilled: recovered onto another host in 21 s, data and history intact)

### Quality bar for everything above

- [x] Every feature tested in unit/feature tests AND end to end on the fleet
      (2026-09-24, end of day: 275 control tests, the agent's tests, and all 17
      browser specs against production - one stale expectation of the old home
      page found by the full run and updated; billing passes at $12)
- [x] No tenant can read another's files, database, logs or .env, proven
      by tests that try (and a positive control that shows the test can fail).
      Re-proved 2026-09-24 on h1, h3 and h4 with two fresh tenants each:
      every one of 16 claims DENIED, positive controls YES
- [x] Every operator action idempotent and safe to re-run (add-host, recover-host,
      move-site, drain, trials:expire, sync-usage, roll-image, reap)
- [x] ROADMAP ticked only after it is deployed and verified in production (a
      standing rule; held on 2026-09-24 with one slip, caught the same hour: the
      DB_URL item was ticked before its e2e ran - it then ran and passed)
