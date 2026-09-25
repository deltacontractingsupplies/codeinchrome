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
- [x] **Sign in with Google and Apple**: built and tested (an OAuth sign-in
      counts as a verified email; linking to an existing account only through a
      verified address). Both configured (Google's client secret added
      2026-09-24; the consent screen is in production, "continue to
      codeinchrome.com", no unverified warning), verified live up to Google's
      account chooser. LEFT for a person: one real sign-in with their own account.
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
- [x] **Repository on GitHub**: superseded by the owner's later choice of a
      PUBLIC repository (below), published 2026-09-24.

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

- [x] **Confirm support@codeinchrome.com receives mail** (done: see the
      support inbox item above - a DKIM-signed test landed in the inbox) (owner: Cloudflare
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
- [x] **Public repository** (owner's decisions, 2026-09-24): PUBLIC, under the
      owner's GitHub account (deltacontractingsupplies - the owner chose it over
      a separate organization); FSL-1.1-ALv2 (no competing use, each
      version Apache-2.0 after 2 years), Copyright 2026 Ahmed Omar; contributor
      agreement (CLA.md); commits keep the owner's own identity. Done and
      verified: no server address, no other business, no secret in any commit
      (publish-repo.sh: scrub, .env values, keys, deny list, gitleaks);
      CONTRIBUTING, SECURITY, CODEOWNERS, PR template; infra/github-setup.sh
      (reviewed pull requests only, CI required, secret scanning); the
      contributor-agreement check as a pinned in-repository action (no external
      app), signatures on branch cla-signatures. PUBLISHED 2026-09-24:
      github.com/deltacontractingsupplies/codeinchrome, rules read back from
      GitHub (public; ruleset active: deletion, force-push, pull request with
      code-owner review, 5 required checks; push protection and secret scanning
      on; squash only; read-only workflow token; private vulnerability
      reporting; cla-signatures branch). Every later push passes
      infra/check-outgoing.sh (pre-push hook; refused a planted address and a
      planted token in testing). The first CI run exposed tests that leaned on
      this machine's fleet registry - fixed in pull request #1.
      LEFT for the owner: read the ruleset once in the GitHub settings; sign
      the contributor agreement on a first pull request of their own.

- [x] **Only our emails** (owner, 2026-09-24): every commit, note and
      co-author line under delta.contracting.supplies@gmail.com, never a
      personal address. Done: this clone commits as the delta address; both
      personal addresses are in the local deny list, so the pre-push check
      refuses any commit that carries one (author, committer, message or
      co-author line - tested on the published history, which it refuses).
      LEFT: the history published on 2026-09-24 carries the personal address
      (GitHub's user filter hides it - the account behind it is not public -
      but every commit's .patch and any clone show it) (129 commits, one notes commit, and the co-author lines GitHub wrote into
      pull requests #1 and #2). Removing it needs the history rewritten
      on GitHub; only with the owner's go-ahead (a force-push, or a fresh
      repository).
      DONE 2026-09-24 (owner: "recreate it"): the repository was deleted and
      recreated with its history rewritten (infra/recreate-public-repo.sh) -
      every file byte-identical, every commit and note under the delta
      address. Read back from outside: 134 of 134 commit patches through the
      API, the API's commit list, a fresh mirror clone, the repository, commit
      and contributor pages - no trace; GitHub links all 134 commits to
      deltacontractingsupplies, its only contributor. Gone with the old
      repository: pull requests #1-#6 and one star. Clones made while it was
      up cannot be recalled.

### The repository is public now (owner, 2026-09-24): link it, and check everything

People can see the code now: every item below is checked on the live site or
on GitHub, not assumed.

**On the site**
- [x] **The GitHub repository on the site**: footer of every public page, a
      short "Open source" block on the home page (licence in plain words:
      free to read, change and contribute; no competing service; each version
      Apache-2.0 after two years), and the link in the editor's help. Only the
      repository address, never an internal one. Tested, and seen live.
      DONE 2026-09-24: footer of every public page, the home page's "The code is public", the editor's help; security.txt Policy is SECURITY.md. Tested (LegalTest, HomePageTest) and seen live.
- [x] **The skill inside the editor** (owner, 2026-09-24): `cic.skill()`
      returns SKILL.md in pages an agent's tool will print, so an agent that
      never loaded the skill can still read it; the same file served as plain
      text at a public address; a small "Copy for your agent" button whose text
      tells the agent to read the skill and prove it is connected; and a
      marker on the page once an agent has really run code there. Tested, and
      tried live with Claude in Chrome.
      DONE: /agent/skill.md (plain text, served from inside open_basedir), cic.skill(), cic.hello(), "Copy for agent", "Agent connected" - the editor e2e passed on production. LEFT for the owner: one real session in the Claude in Chrome side panel.

- [ ] **Sign-in must never fail on a busy database** (production, 2026-09-24:
      Google sign-in answered 500, "database is locked" - its transaction
      read then wrote while the scheduler wrote). Fixed: IMMEDIATE
      transactions, a 10 s busy timeout, WAL; every root access to the
      database moved to the app's user (WAL's -shm would otherwise be
      root's). Deployed and verified on production (journal_mode wal,
      busy_timeout 10000; -wal/-shm owned by the app). LEFT: the owner signs
      in with Google once more.
- [x] **The control plane's own backup had stopped for 32 hours** (a stale
      restic lock, found while fixing the above): unlock before each run, a
      stamp after each complete run, and monitoring alerts when it is older
      than 30 hours. Verified: a run completes and the check reads green.
      DONE: a run completed on production after the fix; monitoring reads "newest complete backup 12 seconds ago".
- [ ] **An agent finds window.cic on its own** (owner, 2026-09-24: Claude in
      Chrome built a page by typing into routes/web.php, "because I couldn't
      expand the file tree" - it never read the agent instructions nor the
      skill). Whatever it reads first must say it: the page title, the first
      text of <main>, the page-text read, a screenshot - never behind a click
      on "For AI agents". DONE: the tab's title and a banner on screen say how
      until an agent calls window.cic (e2e on production). LEFT: tried live
      with Claude in Chrome with NO skill loaded and no pasted message.
- [x] **The skill demands real Laravel** (owner, 2026-09-24): routes stay thin;
      controllers, form requests (validation), Blade layouts and components,
      Eloquent models with migrations and factories, policies for
      authorisation, config and not env() in code, tests for what it builds;
      CSRF, escaping, mass-assignment protection. Never a page in a route
      closure, never markup in PHP strings. And `cic.check` or a review call
      that flags it (HTML in routes/web.php, inline <style> walls, no tests).
      DONE: the skill's required section; cic.check() reviews the code (HTML in PHP, queries in routes, env() outside config/, forms without @csrf) and a fresh site passes it - both proved by the editor e2e on production.
- [x] **Explore: every free site listed publicly** (owner, 2026-09-24): the home
      page (and its own page) lists free sites beside the highlighted demos -
      the site's address only, nothing else (no owner, email, credentials or
      content). Free sites are listed, no opt-out, and the person is told so
      BEFORE creating one (create form, pricing, terms, privacy); paid sites
      are not listed. Only live sites that answer; a site that is taken down
      or deleted leaves the list.
      DONE: /explore and the home page, refreshed hourly; told on the create form, pricing, terms and privacy (ExploreTest). Live, listing the one built free site.

**On GitHub**
- [x] Repository page: homepage https://codeinchrome.com, topics, the
      licence named in the README.
- [x] **Code scanning** (CodeQL default setup: Go, JavaScript, Actions) on
      pull requests and weekly; zero open alerts, or each one fixed.
- [x] **Workflow audit** (zizmor): the contributor-agreement action is from an
      ARCHIVED repository (no more fixes) and runs on pull_request_target
      with a write token. Replace it with our own small check (no pull-request
      code ever runs, inputs never interpolated), keep the same required
      check; zizmor clean in CI.
- [x] CI actions pinned to commits, no persisted checkout token, Dependabot
      version updates (pull request #3); the agent's LSP test race it
      exposed, fixed.
- [x] After every change: secret-scanning, Dependabot and code-scanning
      alerts read back as zero.
      DONE 2026-09-24, read back from GitHub after pull request #5: code
      scanning 0 open (CodeQL extended suite; its JavaScript findings fixed,
      its seven Go findings read against the code and dismissed with the
      reason), secret scanning 0, Dependabot 0. The contributor agreement is
      now .github/cla/cla.sh (16 tests, zizmor clean in CI); its first live
      run set the required status on pull request #5.

**Security, double-checked now that the code is public**
- [x] Whole-history scan again, on GitHub's copy: gitleaks, the deny list,
      every registry address, .env values (a fresh clone, not this one).
      DONE 2026-09-24 over every commit of public/main (131): gitleaks clean,
      no .env secret (both .env files; values unchanged from the committed
      .env.example no longer count), no private key, no artifact, no server
      address, no other business. The ONE finding: the owner's personal
      email in 130 author lines - the "only our emails" item.
- [x] Nothing a reader could use against production: no admin URL that
      skips authentication, no debug route, no default credential, no token
      in a test that is also real. Read the routes and the configuration
      with an attacker's eyes. DONE 2026-09-24, two fixes: an operator's
      address registered by someone else (unverified) was an operator -
      /status and trial exemption; now verified only. Laravel's unused
      GET/PUT storage/{path}: off. PublicSurfaceTest now lists every route
      reachable signed out. CodeQL's seven Go findings each read against the
      code (false positives, dismissed with the reason).
- [ ] Rotate what lived on a laptop (defence in depth - none is in the
      history): the Lemon Squeezy API key and webhook secret, the Cloudflare
      token. Owner, in each dashboard.
- [x] SECURITY.md reporting path tested: private vulnerability reporting
      opens a draft advisory (checked on GitHub). DONE 2026-09-24: reporting
      enabled (API), the policy page shows "Report a vulnerability", its form
      asks a signed-out visitor to sign in, security.txt's Policy points there.
- [x] Only our emails in the published history (above): the owner decides
      between a one-time rewrite and a fresh repository.

### Abuse and security hardening (owner, 2026-09-25): free and public means tight

The code is public and free sites are listed on Explore, so the platform must
be hard to abuse. Every item is verified live, never assumed.

**Notify the owner**
- [x] **Email the owner on every new site**: to delta.contracting.supplies@gmail.com,
      with the site's address (and custom domain, if any) and the account, so
      each new site can be checked. Sent through the platform's own mail.

**Stop malicious redirects and downloads (free sites)**
      DONE 2026-09-25: App\Fleet\OwnerNotifier on every new site and domain (the e2e suite's accounts left out); a real test mail was accepted by Gmail (250 OK).
- [x] **No redirect to an arbitrary site**: a free site may not send visitors to
      another domain except an allow-list of trusted destinations (payment
      gateways such as Stripe and PayPal checkout, the platform itself). A
      redirect to e.g. a malware download site is refused at the platform edge,
      not trusted to the app.
      DONE 2026-09-25 (agent 0.26.5, all 6 sites on h1/h3/h4): the app's
      responses are checked at the edge; a Location to another site is
      refused (403) unless it is this site, the platform, Stripe, PayPal,
      Lemon Squeezy, Google or Apple sign-in. Tested on a host against
      "//host", "/\host", leading space, "javascript:", "allowed.com.evil",
      "allowed.com@evil" and another customer's site - all refused; the
      allowed ones pass untouched. A plain link on a page is the scanner's job.
- [x] **No file downloads of executables/archives**: free sites cannot serve
      .exe .msi .apk .dmg .scr .bat .cmd .ps1 .vbs .jar, or archives meant to
      carry them, nor a download button pointing at them.
      DONE 2026-09-25: refused at the edge by the response's Content-Type
      (executables, installers, APKs, disk images, JARs, archives) and by a
      Content-Disposition filename, so a PHP script streaming an .exe from an
      address with no extension is refused too; a CSV or PDF download still
      works. LEFT: a link to an executable hosted elsewhere - the scanner.
- [x] **ClamAV scanning**: uploaded and written files scanned; a detection, or
      encrypted/obfuscated PHP (eval/base64/gzinflate droppers, ionCube/
      encoded loaders), suspends the site and bans the account (free plan: no
      appeal needed). Scan on write and on a schedule.
      DONE 2026-09-25 (agent 0.26.7): ClamAV on h1/h3/h4 (EICAR found by the post-check); saves of obfuscated PHP refused before they are written; uploads and archives scanned as they land; abuse:scan every 6 hours. All 6 live sites scanned clean before enforcement went on. Proved on production by tests/e2e/specs/abuse.spec.js.
- [x] **Ban an abuser**: suspend a site and ban an account (and its
      canonical email) from the operator side; a banned account cannot sign
      back in or sign up again.

- [x] **Report a site, and rules that match our hosts'** (2026-09-25): a public
      form at /report (only sites we host; 3 a minute, 10 an hour per visitor;
      the IP kept only as a keyed hash) saved and emailed to the owner with
      the ban command; linked from every page and Explore. The terms now
      forbid adult content, content harmful to minors (reported to the
      authorities), gambling, regulated goods, hate/extremism, downloads,
      open redirectors and obfuscated code, and say how enforcement works.
      LEFT (owner): an abuse@ mailbox (Cloudflare Email Routing).

- [x] **Links to malware on a page** (2026-09-25): abuse:links reads every
      site's pages hourly from outside (following its own redirects, up to 15
      pages): a link to a program download on any site bans the account;
      archives, URL shorteners, bare IPs, forms posting elsewhere, meta
      refreshes elsewhere and a password form naming a bank or big brand are
      emailed to the owner for review. Explore lists only sites that passed
      the malware scan AND the link check in the last week. Dry-run on all 6
      live sites before it went on: all clean.
- [x] **Found by the link scanner, fixed**: the edge guard sent every header of
      an allowed redirect twice (Location, Set-Cookie) - copy_response_headers
      on top of copy_response. Agent 0.26.9; checked on production.

**Outbound abuse (Hetzner suspends servers for these)**
      DONE: App\Abuse\Enforcer - banned, signed out, cannot sign in by password or Google/Apple, every site taken down (nothing deleted), a payment never lifts it; abuse:ban (with a reason) and abuse:unban; the owner is emailed each ban with the evidence.
- [x] **Container egress policy** (verified live on h1 2026-09-25): private,
      reserved and metadata ranges refused; mail, mining and brute-force ports
      refused; UDP limited to DNS/QUIC; per-container limits on open
      connections and on new-connection rate; every refusal logged with the
      container's address. Site, HTTPS, DNS and MySQL still work; SMTP,
      metadata, private ranges and RDP are refused. LEFT: rolled out to h3/h4
      and made persistent across reboots.
- [x] **Scan detector**: count distinct destinations per container over time;
      a container that fans out (a scan) is cut off and the operator told.
      DONE 2026-09-25 (agent 0.27.1): each site's outbound spread from the host's connection table (GET /v1/egress); abuse:egress every 2 minutes pauses a site reaching 150+ distinct public hosts or 100+ distinct ports and emails the owner (paused, not banned). Verified live: one request from a site reads as 1 connection, 1 host.
- [x] **CPU/mining detection**: sustained CPU at the ceiling is flagged.
      DONE 2026-09-25 (agent 0.26.8): each site's cgroup CPU counter, read
      every 5 minutes (abuse:cpu); at 90%+ of its limit the owner is emailed
      after 30 minutes and the site paused after 2 hours (not banned - a busy
      app can run hot); abuse:resume brings it back. A container restart is
      never read as a spike.

**Inbound / origin**
- [x] **Origin reachable only through Cloudflare** (80/443): needs custom domains
      on Cloudflare for SaaS and the hosts' certificates renewed without direct
      access first - designed, not yet rolled out.
      DONE for the customer hosts 2026-09-25: h1, h3, h4 take 80/443 from
      Cloudflare's 22 ranges only (a direct request to their IPs now gets
      nothing; SSH unchanged; rolled out one host at a time with an automatic
      revert armed); their own names use the origin certificate and are
      proxied, so DNS no longer publishes their addresses. Custom domains
      (none in production) are off until Cloudflare for SaaS carries them.
      AND the control host h2 (2026-09-25): 80/443 from Cloudflare's ranges,
      443 from the fleet's hosts (backups) only; the backups endpoint serves
      the origin certificate and the hosts trust it explicitly (RESTIC_CACERT:
      system roots + Cloudflare's origin roots, installed before the switch so
      no backup ever failed). Verified: the app answers through Cloudflare and
      not directly, all 3 hosts reach their repositories, SSH unchanged.
      LEFT (owner): the addresses were public before - only new servers would
      make them unknown again.

**Research**
- [x] **How Lovable, Replit, Vercel, Netlify, Render handle abuse** on free
      tiers: what they block, detect and require, adopted where it fits.
      DONE 2026-09-25 (sources: Proofpoint and SC Media on Lovable abuse,
      Lovable's security page, Replit's Trust and Safety docs, Trend Micro on
      fake CAPTCHA pages, Kaseya on Vercel phishing). Already matched: scan on
      publish (ours: on write and scheduled), policy categories (terms),
      custom domains paid-only (ours), stopping abuse at sign-up (Google/Apple
      or Gmail, one inbox one account), a public abuse form with categories.
      Adopted now: ClickFix fake-CAPTCHA detection in the link check (a script
      putting a PowerShell/mshta/shell command on the clipboard bans; "Win+R
      and paste" instructions go to review).
      LEFT: a small "Hosted on codeinchrome - report" badge on free sites
      (Lovable's "Edit with Lovable"); a DMCA designated agent (owner, legal);
      keeping a deleted site's access logs for 30 days as evidence (phishing
      kits delete their sites within hours) - DONE 2026-09-25 (agent 0.27.0,
      /var/log/caddy/deleted, pruned daily; said in the privacy policy).
- [x] **Push to GitHub** so the community can help find abuse and security
      gaps (SECURITY.md, private reporting already on).
      DONE: pull requests #1-#5 on the recreated public repository, every one through the leak checks, CI, CodeQL and the contributor-agreement check.

### Security audit findings that need the owner's decision (2026-09-25)

Everything from the audit that could be fixed without a business decision is
fixed, deployed and verified (above). These remain - each needs the owner:

- [ ] **Customer sites on their own domain** (the audit's one critical item):
      sites share codeinchrome.com with the dashboard, so a phishing report
      that gets the domain listed by Safe Browsing takes the dashboard down
      with it. The fix every platform uses (vercel.app, netlify.app): a
      separate domain for customer sites, submitted to the Public Suffix
      List. **Owner, 2026-09-25: not now** - deferred, the risk accepted
      and reduced by the phishing checks (name refusals, LinkScanner, the
      report form, first-week noindex). The move is scripted when it is bought.
- [ ] **Bot protection on sign-up and login** (Cloudflare Turnstile, Bot Fight
      Mode): our Cloudflare token has no permission for either. Owner: turn on
      Bot Fight Mode, and create a Turnstile widget (or give the token
      Turnstile:Edit). The forms are WIRED (App\Auth\Turnstile): sign-up,
      email sign-in and the reset mail, off until TURNSTILE_SITE_KEY and
      TURNSTILE_SECRET are set; the CSP allows Cloudflare's origin only on
      those pages; a token Cloudflare cannot confirm is refused (fails
      closed); the e2e suite's reserved @codeinchrome.test addresses skip it.
      Owner: a Managed widget for app.codeinchrome.com, and its two keys.
- [x] **Free sites: how long, and how public** (owner, 2026-09-25):
      - a new free site sends `X-Robots-Tag: noindex, nofollow` for its first
        7 days (agent `PUT /v1/sites/{id}/indexing`; set by the Provisioner,
        lifted hourly by `sites:indexing`, at once on an upgrade);
      - a free site with no visitors and no edits for 30 days is warned, then
        paused at least 3 days later (`sites:idle`, daily). Visits come from
        the access logs (agent `GET /v1/visits`: people only - crawlers,
        scanners, scripts and our own checks do not count); work from any
        request the owner makes on the site (`SiteActivity`). A host whose
        logs cannot be read pauses nothing. One click on the dashboard brings
        it back (`sites.wake`), and only an idle pause - never a trial, CPU,
        egress or abuse one (`sites.paused_reason`). Nothing is deleted.
        In the terms.
- [ ] **Stronger sandbox** (gVisor, or user-namespace remapping): containers
      share the host kernel under plain runc. A real hardening, with a
      compatibility and performance cost to test first.
      **gVisor measured, 2026-09-25** (runsc release-20260921.0, on one host,
      a copy of a real site under the free plan's limits - 0.5 CPU, 384 MB -
      beside the same copy under runc; the host restored afterwards):
      compatible (Laravel, MySQL, artisan, file writes all worked), but
      start 1.0 s -> 2.3 s, p50 9 -> 17 ms, p95 21 -> 69 ms, throughput at
      10 concurrent 51-54 -> 13-14 req/s (about 4x less for the same CPU),
      `artisan route:list` 0.32 -> 1.0 s, and 55 -> 175-182 MiB of the
      site's 384 MiB memory (the same with `--overlay2=none`). At today's
      plan sizes it would need roughly double the CPU and memory per site.
      User-namespace remapping has no such runtime cost but is daemon-wide:
      every site disk and the MySQL container re-owned, every container
      recreated. Owner: which, if either - the measurements are here.
- [x] **Disk I/O limits per site** (2026-09-25): 400/200 MB/s read/write,
      10,000/5,000 IOPS, set on the host's physical disk - measured: the
      kernel charges a site's I/O through its loop device to the site's
      cgroup on the disk, and a loop device's number changes across reboots
      while the disk's does not. Cost measured on the worst case (copying
      vendor/ with caches dropped): +8%. Existing containers are recreated
      by fleet:roll-image, which now treats a container with old run
      settings (label `codeinchrome.runspec`) as outdated; the roll moved to
      05:30 UTC, after the reboot window, so a reboot can never cut one off
      between removing a container and starting its replacement.
      Found while measuring: the site disks (loop devices) ran without direct
      I/O, so every write was cached and flushed twice - 43 MB/s sustained
      inside a site against 850 on the host's disk. Now on (cic-mount, live
      without a remount; install-agent checks every site disk): 210 MB/s, and
      copying vendor/ cold went from 5.0 s to 1.8 s.
- [ ] **One provider for everything** (Hetzner): the control plane, every
      host, the backups and their replica. A second provider for the backup
      replica removes the single point of failure.
- [ ] **Operator identity and an abuse-response process** (legal name on the
      terms, a response-time commitment to Hetzner and Cloudflare reports).
- [x] **A failing scheduled job is an incident** (2026-09-25): abuse scans,
      the image roll, trials, idle pauses and every other scheduled job
      feed monitoring (`job:<command>`): an hourly or daily job alerts on its
      first failure, a job every few minutes on its third in a row, once per
      incident, with recovery. Before, a job failing every run left a log line.
- [ ] **A dead-man's switch for the scheduler itself**: if cron stopped,
      monitoring (a scheduled job) would stop with it and say nothing. BUILT
      (App\Fleet\Heartbeat): every fleet:monitor run pings
      `CIC_HEARTBEAT_URL`; unset, it is off. Owner: create a check at
      Healthchecks.io or Better Stack (period 1 minute, grace 5) and hand over
      its ping URL - it goes in the control host's .env.
- [ ] **Platform mail from its own IP** (the control host): a mail provider
      (Postmark, Resend) instead keeps the host's address out of every mail
      header and SPF record.
- [x] **Automatic reboots for kernel updates** (owner, 2026-09-25: automatic
      at 04:30 UTC): unattended-upgrades reboots when an update needs it,
      one host at a time - h1 04:30, h3 04:50, h4 05:00, the control host
      05:15 (`/etc/apt/apt.conf.d/52cic-reboot`, from install-agent.sh and
      deploy-control.sh). The 3-day `host:hN:reboot` alert stays as the
      backstop.

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
