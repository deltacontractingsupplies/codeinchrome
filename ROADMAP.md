# Roadmap

What "done" means for codeinchrome, as a checklist. An item is ticked only when
it is built, tested, deployed, and verified against production - not when the
code exists.

## Waiting on the owner (everything else is built)

Each line: what to do, and what to hand over. The code for the first five is
live and switched off; handing over the value switches it on, and it is then
verified on production.

1. **Heartbeat** - Healthchecks.io or Better Stack: a check, period 1 min,
   grace 5 min. Hand over: its ping URL (`CIC_HEARTBEAT_URL`).
2. **Bot protection** - Cloudflare dashboard: Security > Bots > Bot Fight Mode
   on; Turnstile > add a Managed widget for app.codeinchrome.com. Hand over:
   its site key and secret (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET`).
3. **Backup copy at a second provider** - a bucket at Cloudflare R2 or
   Backblaze B2 with a lock/retention rule (the key must not be able to
   delete), and an access key limited to that bucket. Hand over: endpoint,
   bucket, key id, secret (`/opt/codeinchrome/etc/offsite.env`).
4. **Mail provider** - Postmark or Resend, domain codeinchrome.com verified.
   Hand over: its SMTP host, port, user and password (`MAIL_*`).
5. **Cloudflare for SaaS** - Cloudflare dashboard: SSL/TLS > Custom Hostnames,
   enable (free for 100). Then say so: custom domains are moved behind it
   and switched on.
6. **Lemon Squeezy** - finish identity verification; then say so: products are
   copied to live mode and billing re-run with the live key.
7. **Key rotation** - in each dashboard, roll the Lemon Squeezy API key and
   webhook secret and the Cloudflare API token. Hand over the new values.
8. **Sign in with Google** at app.codeinchrome.com in Chrome - proves Google
   sign-in, and lets the Claude in Chrome test run on the editor.
9. **Decisions** - the operator's legal name and an abuse-response
   commitment for the terms, and (deferred: "not now") a separate
   customer-site domain. (The sandbox was decided 2026-09-26: runc stays.)

### Owner's requests, 2026-09-26: speed, live UX, GitHub, testing, data safety

Every item is built on what exists (checked first, so nothing is duplicated),
tested, and verified on production before it is ticked.

**Free that feels like the real thing, and uses every idle core**
- [x] F1. Free sites feel slow. Free already has Starter's 0.5 CPU / 384 MB, but
      as a hard cap. Let every site burst into idle host CPU (a higher cap with
      CPU weights: paid sites win when the host is busy, free sites get the
      rest). The kernel rebalances instantly, so free gets full power while
      the host is quiet and shrinks only when paid sites need it. Measure a
      page's p50/p95 before and after on a free site.
      DONE 2026-09-26 (#75, agent 0.44.0): every site bursts to 2 CPUs (half a host), --cpu-shares free 256 / paid 1024, applied live with no restart. Measured on the free store on h1: artisan route:list 478-503 ms before, 229-284 ms after.
- [ ] F2. Show how many free trial places are left (from measured capacity:
      config fleet.stock), on the home and pricing pages.
- [ ] F3. When free sites crowd paid ones (measured, not guessed), the free
      share shrinks first, automatically; paid sites are never slowed by free
      ones. Alert the owner when it happens.

**See the agent work, live**
- [ ] L1. Every cic write, edit and delete shows in the editor the moment it
      happens: the file opens (or its tab updates), the changed lines are
      highlighted, the file tree marks what changed - not all at once at the
      end of a long script.
- [ ] L2. An activity timeline beside the editor: each step the agent takes
      (file written, command run, test result), with the time, as it happens.
- [ ] L3. Shell and artisan output streams line by line into the terminal as
      the command runs, like a real terminal; exit code at the end.
- [ ] L4. Fast with large changes: batched UI updates, no freeze on writeMany
      of hundreds of files.
      (Research first: how Lovable, Replit, Bolt, v0, Cursor show this.)

**Never lose code, and link GitHub**
- [x] G1. Every change is a commit already (history.git, verified). Re-check
      every path that changes files (write, edit, delete, rename, shell, eval,
      restore, import) records a version, and that no agent call can erase
      history.
      DONE (verified 2026-09-26): every change path records a version - write, edit (through writeFileIf), batch, delete, move, copy, upload, folder deletes, unzip, clone, replace, commands, eval, restore. history.git is outside the site's container (only vol/app is mounted; checked on a live host: not findable from inside), and no route deletes or rewrites it - only deleting the whole site does.
- [x] G2. Link a GitHub repository: from then on every commit is pushed to the
      customer's repo automatically. Simplest secure path first (research:
      deploy key vs GitHub App); the free plan says plainly that a deleted
      free site is gone unless GitHub is linked.
      DONE 2026-09-26 (agent 0.47.0): Settings > GitHub. A deploy key made for the site alone (ssh-keygen on the host; the private half never leaves it, 0600, outside the container); the owner adds the public half with write access; every recorded version is pushed a few seconds later (a burst is one push), and once at agent start to catch up. Fast-forward only: when GitHub has the owner's own commits, the site's history goes to codeinchrome/sync and nothing is overwritten. GitHub's host keys pinned (checked against the published fingerprint). .env never leaves (history excludes it; tested). Unlink destroys the key. The free plan's page says a deleted free site is gone unless linked.
- [x] G3. Claude in Chrome can do the linking for the user (the skill says how).
      DONE: cic.github.status/link/push (JSON), and the skill's steps - the agent links it, opens the add-key page in the person's browser with their OK, and they confirm their password on GitHub if asked, never the agent.

**The agent can test like a person, safely, on production**
- [ ] T1. A one-time sign-in link for the site: opens the site in the browser
      already signed in as a chosen user, no password (Claude in Chrome cannot
      type passwords). Builds on cic.request({ as }) and the login-cookie route.
- [ ] T2. A headless browser for the agent: visit pages as a visitor, click
      through a flow, and take screenshots at phone, tablet and desktop widths
      (responsiveness) - built on the renderer the link scanner already uses.
- [x] T3. Tests never touch live data: the site's tests run on their own
      database with every test rolled back; RefreshDatabase, migrate:fresh,
      db:wipe and friends are refused by the platform, not only discouraged.
      DONE (verified 2026-09-26, already enforced by the platform): `artisan test` runs with APP_ENV=testing on an in-memory SQLite database and the MySQL host pointed at nothing (agent commandEnv, tested) - RefreshDatabase in a site's tests can only ever wipe that. migrate:refresh, migrate:reset and db:wipe are not on the allow-list at all; migrate:fresh, migrate:rollback and db:seed need an explicit confirm. cic.eval stays the owner's own PHP, by design.
- [x] T4. Database history: a snapshot before every migration and seeder the
      agent runs, plus frequent automatic snapshots, restorable from the
      backups page - so a database is never lost, free or paid.
      DONE 2026-09-26 (agent 0.46.0): a snapshot of the database before every import, migrate, migrate:rollback, migrate:fresh and db:seed - and if it cannot be taken, nothing runs. The newest 10 (1 GB at most) per site, outside the site's own files; listed in the Database view and by cic.db.snapshots(), put back by cic.db.restore(name, { confirm: true }) or a click, and a restore snapshots what it replaces first. The nightly backups keep the long history.

**The skill**
- [ ] S1. Testing first: every feature ships with tests and a browser check
      (T1/T2), responsiveness checked at three widths.
      PART 2026-09-26: the skill asks for tests first and a check at phone, tablet and desktop widths (browser window resize + screenshot); T2 will make that one call.
- [x] S2. Security: roles and permissions for every route, no data exposed to
      the wrong user, checked by a test; no secrets anywhere public.
      DONE 2026-09-26 in the skill: a Policy per model action, a test that a guest is sent to log in and another user gets 403/404 on someone else's record, never trusting an id, price or role from the browser.
- [x] S3. DRY: before building, search the app for an existing feature or
      component that already does it and extend it; never duplicate. Keep a
      todo list for multi-step work and tick it off.
      DONE 2026-09-26 in the skill: look for an existing model, controller, component or layout first and extend it; markup used twice is a component; a todo list ticked only when built and tested.
- [ ] S4. Claude in Chrome knows the skill in every chat - find the simplest
      way to load it in the extension (research: skills, shortcuts).
      BUILT 2026-09-26: the public repository is a Claude plugin marketplace (.claude-plugin/marketplace.json, the skill already in it); in Claude: Customize > Plugins > Add marketplace > deltacontractingsupplies/codeinchrome, and every chat - the Chrome side panel included - has the skill. The editor says so. LEFT (owner): add it once on a paid Claude account to confirm.

**Speed and polish everywhere**
- [ ] P1. The editor and dashboard fast and polished on every device (measure
      load time and interaction latency; fix the slowest first).

### Security audit, round 2 (2026-09-25): 49 confirmed findings

Six auditors (redirects and downloads, malware and PHP, host hardening,
performance, other platforms, accounts) each re-checked what was built
rather than trusting it. A skeptic then re-verified every finding against
code and production; none was refuted. Full evidence and proposed fixes are
in the audit's journal; each item below is fixed, tested and verified live
before it is ticked.

- [x] **A1 [high]** Offsite redirect passes the edge when the Location contains a TAB ("/<TAB>/evil", "ht<TAB>tps://evil", "\<TAB>\evil") - FIXED 2026-09-25: a control character anywhere in Location is refused (agent 0.30.1); reproduced blocked live.
- [x] **A2 [high]** Refresh response header is not checked, so a free site can send visitors to any site - FIXED at the edge 2026-09-25: Refresh headers are removed (0.30.1); verified live (no refresh header reaches the visitor).
- [x] **A3 [high]** The download guard is bypassed by letter case, RFC 5987 filename*, bare attachment with octet-stream, and unlisted executable MIME types - FIXED 2026-09-25: one case-insensitive CEL check of Content-Type and Content-Disposition (filename*= too), wider type/extension lists; verified live.
- [x] **A4 [high]** Static files in public/ with executable or container extensions not on the list are served (.scr, .iso, .hta, .cab, .gz, .deb, .lnk, .img, .vhd, .msix, .appx, .xapk) - FIXED 2026-09-25: program/archive extensions refused by path before the app (incl. /x.php/setup.exe); verified live.
- [x] **A5 [high]** Phishing detection can be evaded (only pages linked from the home page, a published User-Agent, server HTML only) and never pauses a site (review email only) - PART DONE 2026-09-25: see A15 - unlinked routes are now crawled and the scanner does not announce itself. And the agent scan names phishing-kit code for review (Telegram bot or Discord webhook exfiltration, scanner cloaking, card fields beside a brand), in PHP, HTML and JS outside vendor/ (agent 0.31.4); no false positive over our code or any live site.
- [x] **A6 [high]** No Safe Browsing or threat-feed monitoring of hosted sites, although sites share codeinchrome.com with the dashboard - DONE 2026-09-26 without any key: abuse:feeds (hourly) reads URLhaus (malware, ~16,000 live URLs) and OpenPhish (phishing), both public, and matches every hosted name - platform subdomains and VERIFIED custom domains (a claimed, unverified name can be anyone's). A free site listed is paused and a person told, with the listing and the resume/ban commands; a paid one goes to a person (a third-party list can be wrong about a paying customer); each listing is acted on once a week; the dashboard itself listed is a critical alarm. Owner, optional: a Google Safe Browsing API key as CIC_SAFE_BROWSING_KEY in the local .env adds its verdicts to the same run (carried by deploy-control).
- [ ] **A7 [high]** Customer sites are not on a separate Public Suffix List domain (owner-deferred item, re-raised)
- [x] **A8 [high]** ClamAV detects no PHP webshells on h1/h3/h4 (only EICAR), yet it is the only check in vendor/, node_modules and non-PHP files - MITIGATED 2026-09-25: a webshell anywhere under public/ can no longer run (only index.php executes). FIXED 2026-09-26: vendor/ and node_modules/ get the PHP rules except for files exactly as composer installed them (A11); the one way left to run PHP from a non-PHP file - include/require - is a rule ("a non-PHP file included as code": a literal path to an image, text, log, archive or asset), and an image carrying a PHP open tag is refused on upload, unzip, copy, clone and found by the scans. Checked for false positives against the control plane's app and vendor/ before shipping.
- [x] **A9 [high]** The obfuscation regexes are trivially bypassed: 17 of 17 common variant webshell forms I tested pass - MITIGATED 2026-09-25: see A8 - an undetected shell in public/ cannot execute; the rules still to harden. - FIXED 2026-09-25 (agent 0.30.5): new rules (any eval, create_function, chr() chains, escapes decoded and checked for dangerous names, request input called/included/handed to a callback or a shell through a variable, dangerous names in variables incl. 'ba'.'se64_decode', PHP files written from input, shell backticks); comments dropped first; all 19 audit variants caught; no false positive on 257 control-plane files and 394 live-site files.
- [x] **A10 [high]** Rules are chosen by file extension and Apache honours AllowOverride All, so PHP hidden in .txt/.jpg or pulled in by include is never checked - FIXED 2026-09-25: AllowOverride None (Laravel's rewrite rules built into the image) and only public/index.php executes; verified live: a .jpg beside an AddType .htaccess is served as text, a dropped .php and /x.php/y answer 403.
- [x] **A11 [high]** vendor/ and any node_modules/ path are exempt from the rules and writable from the editor; public/node_modules/x.php is served directly - MITIGATED 2026-09-25: public/node_modules/x.php and any other PHP in public/ answer 403. FIXED 2026-09-26: measured first - the rules flag ~30 of 8,878 PHP files in a stock Laravel vendor/ (eval in Laravel, Carbon, phpseclib), so a blanket rule would ban honest sites. Instead the host records the SHA-256 of every vendor/ PHP file after each successful composer run (out of the container's reach); the scheduled scan exempts only files byte for byte as composer left them, and anything else under vendor/ or node_modules/ gets the rules (unverified_dependency: review, as an app uploaded with its own vendor/ is honest). A PHP file saved into vendor/ from the editor that trips the rules is refused (no ban). A site with no record yet has its vendor/ recorded by its first scan (never weaker than the old blanket exemption, and no flood of reviews on rollout); anything planted after is seen.
- [x] **A12 [high]** The CPU (mining) watch resets on any container restart, which the owner can trigger, and on any single 5-minute reading under 90% - FIXED 2026-09-25: a restart within 15 minutes continues the streak, and a rolling average (75% of the limit: alert over 2 h, pause over 6 h) catches a throttled or dipping miner; tested, both rules mutation-checked.
- [x] **A13 [high]** The stray-process reaper trusts argv: anything named apache2/httpd or containing 'phpactor' (or restarted every <12 minutes) survives on free sites - FIXED 2026-09-25: processes are judged by /proc/<pid>/exe, not argv; phpactor survives only while the site has a language-server session (agent 0.30.3); verified from a host that container Apache resolves to /usr/sbin/apache2.
- [x] **A14 [high]** The egress watch counts only distinct hosts and ports in a conntrack snapshot, so SSH brute force, single-target floods or credential stuffing, and RST-answered port scans go unnoticed - FIXED 2026-09-25: new SSH/FTP connections limited to 6 a minute per destination (verified live: 6 connected, 2 refused), and a site's logged refusals are counted (agent /v1/egress) and reported to the owner at 30 in 10 minutes. And the busiest single destination per site is reported (agent 0.31.3): 200+ open connections to one host:port is emailed for review (a flood or credential stuffing); tested.
- [x] **A15 [high]** The phishing/link scanner can be cloaked (fixed User-Agent, control-host source) and never sees unlinked or JS-rendered pages, yet Explore trusts its result - PART DONE 2026-09-25: a browser User-Agent and headers, a probe mark the edge strips before the app (agent 0.31.0) so a kit cannot cloak on it, every parameterless GET route crawled (route:list), 30 pages, and Explore lists only sites past their first week. DONE 2026-09-26: JS-rendered pages - the first 3 pages of a scan are also read as a browser has them once their scripts ran (headless Chromium), and checked by the same rules; findings only the rendered page shows say "after its scripts ran". Chromium runs in a throwaway container with every privilege taken away (non-root, no capabilities, read-only root, 768 MB, 1 CPU, 256 processes, its own br-* network behind the egress chain) and renders only the platform's own site names; rebuilt weekly with the site image. It renders on ANOTHER host than the site's (a kit can learn its own host's address and hide from it) with a browser's own User-Agent (a kit looks for "HeadlessChrome"); the fleet's addresses are not counted as visits. Once a day per site on the hourly scan, always for a report. Measured first on h4: a script-inserted link appears in the rendered DOM (1.5 s), a live site renders in 1.9 s. The control host's address as the plain fetch's source stays (it is behind Cloudflare, and the render comes from elsewhere).
- [x] **A16 [high]** The control host h2 answers on 80/443 to the whole internet: an old iptables snapshot, restored at boot, undid the Cloudflare-only firewall, and the deploy check still reports the ports as closed - FIXED 2026-09-25 (#21): the snapshot and unit retired, ufw rebuilt, and the deploy now checks the live ruleset and a direct request from outside.
- [x] **A17 [medium]** No CSP or Service-Worker restriction at the edge: page scripts can register a Service Worker and make blob: downloads that never pass through Caddy - PART DONE 2026-09-25: Service Worker registration refused at the edge (verified live); blob: downloads by page script remain (a sandbox CSP would also block legitimate downloads - owner decision). DETECTED 2026-09-26 without blocking anything honest: the link scanner bans a page whose script builds a program download (a Blob or download of an .exe/.msi/.apk..., or an installer's MIME type) or carries a Windows program inline (base64 "TVqQ..."), and now also reads the site's own script files (up to 5 a scan) with the same checks and the clipboard-command check; a CSV export built the same way is not flagged (tested). Blocking them outright stays the owner's decision. The dry run before it went on put EVERY page of every site up for review: Cloudflare adds its Web Analytics beacon (static.cloudflareinsights.com) to proxied pages at the edge, so it is on pages no site wrote. It is an allowed host now (tested); the live scan after the deploy: every site clean. DECIDED 2026-09-26 (owner): detect, do not block - a sandbox CSP would stop every honest script-made download too (CSV exports, generated PDFs).
- [x] **A18 [medium]** The link scanner is blind to JS navigation and iframes, uses a self-identifying User-Agent from the control host, only reviews offsite meta refreshes, and checks only the path extension - FIXED 2026-09-25: the link check reads iframes, script sources and script redirects (reviewed when offsite), and an offsite meta refresh is a ban like the edge's redirect rule; it sends a browser's headers; tested.
- [x] **A19 [medium]** A banned person can come straight back: no network/device link between accounts, no daily sign-up cap, Turnstile off - PART DONE 2026-09-25: the sign-up network is kept as a keyed hash (IPv4 /24, IPv6 /48); an account from the network of one banned in the last 30 days is held before it can create a site (owner emailed once), and a ban email names other accounts from the same network; tested. DONE 2026-09-26: a device cookie - a random id in a long-lived, encrypted, httpOnly cookie; accounts keep a keyed hash of the browser they were made in (signup_device) and last signed in from (last_device, on every sign-in by any route); a free account from the browser of one banned in the last 30 days is held for a person, even from another network, and a ban email names accounts from the same browser; a forged cookie cannot be read and is replaced. Still open: Turnstile keys (owner). Turnstile ON 2026-09-26 (owner approved): widget "codeinchrome app" for app.codeinchrome.com only, Managed mode; keys only in the operator .env and the control host's .env; siteverify accepts the secret; the widget and its CSP origin are on /login and /register only (checked live); the e2e suite's reserved addresses skip it - full e2e green with it on (15 passed).
- [x] **A20 [medium]** No per-site outbound bandwidth cap or byte-volume watch - PART DONE 2026-09-25: the agent reports each site's bytes sent (its bridge counter, agent 0.31.2), and a free site sending over 1 GB in ten minutes is paused and reported (a paid one reported); tested with a counter reset. DONE 2026-09-26: every container sends to the internet at most ~100 Mbit/s (12 MB/s, 24 MB burst): a byte-rate hashlimit as the first rule of the container egress chain, established connections included - safer than tc on each bridge, in the chain every deploy already builds and proves. Measured on h4 first: a 100 MB upload 720-835 Mbit/s without it, 108-157 with it (the burst lifts short runs); a site's answers to its visitors never cross that chain (18-22 Gbit/s to the host either way). The 1 GB/10 min watch still fires under the cap (up to 7.5 GB).
- [x] **A21 [medium]** No domain-level egress visibility: containers can use any DNS resolver, and Telegram exfil and pools on 443 are unrecorded; no restricted tier for new accounts - PART DONE 2026-09-26: containers can no longer use any resolver - measured first on h4 that Docker's resolver forwards from the host's side (a container's lookup crossed the container egress chain 0 times, a direct query to 8.8.8.8 twice), then direct DNS (53), DNS over TLS (853) and the public DNS-over-HTTPS resolvers' addresses are refused; every deploy proves it with a throwaway container (names resolve; a direct query is refused). FOUND the same day, before it did harm: the refusal also hit the default bridge, where image builds run without Docker's resolver - apt failed in a build, and the weekly site-image rebuild (next run 2026-09-27 03:45) would have failed. Now limited to the site bridges (br-*), with a deploy check that a build container resolves names (it failed 3 of 3 under the faulty rule). DONE 2026-09-26, the restricted tier: a new free site's first week - the same window as its noindex, lifted with it by sites:indexing (past the week or paid, and clean on both scans) - reaches out only on TCP 80/443, with no UDP, at about 8 Mbit/s (CIC-RESTRICT, entered from DOCKER-USER for that site's bridge only, before the ordinary chain; the agent records it and re-applies it at every start). Measured on h4 first: https 200, port 22 refused, names resolve, upload ~11 Mbit/s with the burst. DONE 2026-09-26, the names each site looks up: measured on h4 that Docker's resolver, given the bridge gateway as its upstream, asks FROM THE CONTAINER'S address - so a forwarder on the host sees which site asked (the host's own resolver could not: systemd 249 has no query stream, and a loopback upstream does not work). cic-dns (the agent binary's dns mode, its own service and user, bound to the default bridge's gateway, restarted at once if it stops) forwards every question to the host's resolvers unchanged and logs time, asking address and name (50 MB, one previous file kept). Sites are pointed at it only once a throwaway container proved it answers; the run-spec label moves sites onto it one at a time through fleet:roll-image. The agent reports each site's names (/v1/dns); abuse:dns sends a site that looked up an exfiltration or mining endpoint (the Telegram bot API, Discord, paste and file-drop sites, request catchers, tunnels, pools) to a person, once a week per name - never a ban. Proven on h4 on a throwaway network first: names resolve, and the log named the container and each name. LIVE 2026-09-26: the first deploy could not start cic-dns anywhere (its user cannot enter the agent's 0750 directory - "Permission denied"); the proof that gates the sites caught it and no site was pointed at it. With its own copy of the binary it runs on every host; one site per host was moved first, then the rest: all 7 live sites resolve through it, a lookup from each was attributed to exactly that site, every site answered as before, and the first-week restrictions survived the moves. HARDENED 2026-09-26 (found reviewing it): every site reaches the forwarder, and nothing bounded what one could make it hold - a flood started a goroutine and a 64 KB buffer per packet (2,000 in flight from a 2,000-packet test flood). Now at most 256 questions in flight and 64 TCP connections (the rest dropped, as a busy resolver drops), a 96 MB memory ceiling on the service, and 50 questions a second per site at the firewall (the same budget as DNS on the way out; this traffic arrives through INPUT, which the egress chain never sees). Measured live on h4 (agent 0.43.2) from a throwaway container: 5,000 questions sent in 0.04 s, 101 let through and 4,899 dropped at the firewall, the forwarder's memory 7.3 -> 9.7 MB, and a different container resolved normally straight after. Rolled out to h1 and h3; every site answering.
- [x] **A22 [medium]** Abuse reports trigger no automatic scan or pause; no abuse@ contact exists, which risks Cloudflare's 24-hour response rule - PART DONE 2026-09-25: a report now triggers the link check of the site at once (a ClickFix or program on the site bans, the rest goes to review), and three different reporters (per /64) in 24 hours pause the site - never a ban; tested. LEFT (owner): an abuse@ mailbox registered with Cloudflare. APPROVED 2026-09-26 (owner): abuse@ forwards to the owner's inbox. Waiting on the Cloudflare token: it has no Email Routing permission (checked: routing is on for the zone, the rules API answers "Authentication error"). DONE 2026-09-26: abuse@codeinchrome.com forwards to the owner's inbox (Cloudflare Email Routing, created in the dashboard: the API token has no Email Routing permission; the destination was already verified; MX points at Cloudflare's routing), and it is published on the report page and in the terms.
- [x] **A23 [medium]** Rename, Copy, eval, artisan and composer commands, and the app's own writes are not scanned when they happen, so detection waits for the 6-hourly scan; editor saves never run ClamAV - FIXED 2026-09-25 (agent 0.30.9): a move or copy is scanned where it lands and undone on a finding; PHP a command or eval wrote is held to the rules (and bans like a save); verified live: notes.txt renamed to public/x.php was refused and put back.
- [x] **A24 [medium]** A false positive from the hex-escape rule bans the account and takes down all its sites, even on a refused save - FIXED 2026-09-25: the hex rule decodes and flags only dangerous names (a PNG signature or zero-width stripping no longer matches). Obfuscated code still bans, by the owner's decision.
- [x] **A25 [medium]** Customer static files (public/css, js, images) have no Cache-Control at the origin; Cloudflare adds max-age=14400 and caches them, so edits reach visitors up to 4 hours late - FIXED 2026-09-25: static files get Cache-Control public, no-cache (only when the app set none), Vite's hashed /build/assets a year immutable (agent 0.30.7), and Cloudflare's browser cache TTL respects origin headers (setup-cloudflare-proxy.sh); verified live: REVALIDATED, not a 4-hour HIT.
- [x] **A26 [medium]** Once an agent runs optimize, route:cache or config:cache (all allowed), later editor edits to routes/config/.env/listeners silently have no effect - FIXED 2026-09-25: after any change the agent makes, a route/config/event cache older than its sources is deleted (bootcache.go); verified live: route:cache, then a save of routes/web.php, and the stale cache was gone.
- [x] **A27 [medium]** A site paused for CPU or egress abuse can be deleted and a new one created at once; deleting the account also defeats a later ban by email - FIXED 2026-09-25: a site paused for CPU, scanning or abuse cannot be deleted by its owner, the account cannot be deleted meanwhile, no new site can be created, and a second automatic pause within 30 days (an earlier incident) is a ban; tested.
- [x] **A28 [medium]** Automatic bans can be triggered by harmless content (external .apk/.dmg/.deb links, copy buttons next to install commands), including content posted by strangers - FIXED 2026-09-25: a program link elsewhere and a copy button alone go to review; a ban needs a program on the site itself, or the full ClickFix (clipboard command AND Win+R/paste).
- [x] **A29 [medium]** Per-IP limiters use the full IPv6 address, register has no hourly or daily cap, Turnstile is off in production, and /report has no challenge - FIXED 2026-09-25: every per-IP limiter keys an IPv6 visitor by /64 (App\Auth\ClientNet); sign-up is capped per hour (20) and day (50), reset mail per hour; tested. /report gets Turnstile with the keys (owner).
- [ ] **A30 [medium]** Ban evasion: only the canonical email links a banned person to a new account, and Google/Apple sign-up skips the Gmail-only domain rule - PART DONE 2026-09-25: network signals as in A19. Google and Apple sign-up without the Gmail-only rule is the owner's decision (any real Google or Apple identity).
- [x] **A31 [medium]** The site-name impersonation filter misses common phishing names - FIXED 2026-09-25: names normalised (hyphens, look-alike digits) and more brands; short brands refused beside a lure word; every audit example refused, foodbank-online and purchase-online still allowed (tested).
- [x] **A32 [medium]** Customer apps see every visitor as the Docker gateway address, so per-IP limits and bans cannot tell visitors apart - FIXED 2026-09-25: Caddy sends X-Real-IP {client_ip} (agent 0.30.4) and the image's mod_remoteip trusts it from the Docker networks only; verified live through Cloudflare: my real address logged, a forged X-Real-IP ignored.
- [x] **A33 [medium]** Authenticated Origin Pulls are off: the Cloudflare-ranges allowlist admits any Cloudflare customer's proxy or Worker, and h2's IP is published in DNS - BUILT 2026-09-26: the platform names' vhosts (hosts and h2's app, bare domain and www) require Cloudflare's origin-pull certificate (Cloudflare's CA in the repo, valid to 2029); the agent's own edge probe presents a certificate from a per-host probe CA (root-only), so the rollback guard keeps working; custom domains and h2's fleet backups vhost are left as they are. The deploys require it only when the zone setting is ON (checked through the API), so no order of deploys can refuse visitors; turning it off is the reverse order with CIC_ORIGIN_PULLS=off (infra/setup-origin-pulls.sh explains). Verified: the rendered block validates on a host's Caddy 2.11 (require_and_verify).
      INCIDENT 2026-09-26, first rollout on h1: every h1 site answered 520
      through Cloudflare for several minutes. Cause: Cloudflare's CA file has
      no final newline, and the pool was built with a plain cat, which joined
      "-----END CERTIFICATE-----" and "-----BEGIN CERTIFICATE-----" on one
      line: a pool that parses to nothing trusts no client, Cloudflare
      included. My pre-rollout validation had checked Cloudflare's file alone,
      not the concatenated pool that shipped. Rolled back by hand (flags off,
      agent restart); zone setting turned off again. Fixed four ways: the
      file ends in a newline and the pool is built with explicit separators;
      install-agent.sh proves the pool (exactly two certificates, the probe
      verifies) before enabling; the agent refuses at startup to require a
      pool that does not fully parse (tested with the joined file); and
      install-agent.sh backs origin pulls out by itself if the probe OR
      Cloudflare is refused after enabling. The fixed pool was proven on a
      throwaway Caddy on h1 (probe 200, no certificate refused).
      ON 2026-09-26 (second rollout, one host at a time with the
      self-rollback armed): h1, h3, h4 and h2 require Cloudflare's
      certificate; each host's checks passed (without it refused, the probe's
      accepted), and every live site answered through Cloudflare exactly as
      before (200/302 as in the baseline taken first); the production e2e
      suite then passed (15, 3 skipped as always), creating and serving a
      brand-new site behind them.
- [x] **A34 [medium]** Docker CE, containerd.io (which includes runc), Caddy and the ondrej PHP packages are never auto-updated - FIXED 2026-09-25: unattended-upgrades also takes Docker (docker-ce, containerd, runc) and Caddy on every host, and Ondrej's PHP and Caddy on the control host (origins read from apt-cache policy); checked by bootstrap.
- [x] **A35 [low]** Wildcard *.lemonsqueezy.com lets any self-made Lemon Squeezy store be a redirect target - FIXED 2026-09-25: Lemon Squeezy only at /checkout/ or /buy/, at the edge and in LinkScanner; verified live.
- [x] **A36 [low]** Several Location headers are joined before the check, so an offsite one after a relative one passes the edge - FIXED 2026-09-25: several joined Location values with an offsite one are refused; verified live.
- [x] **A37 [low]** The mining check resets on any 5-minute reading under 90% CPU, so a throttled miner is never paused - FIXED 2026-09-25 with A12: the 6-hour average pauses a miner held under 90%.
- [x] **A38 [low]** Scans read only the first 2 MiB (scheduled) or 32 MiB (unzip) of a PHP file, and clamd reports content past 100 MB as clean - FIXED 2026-09-25 (agent 0.31.1): the scheduled scan reads PHP up to the 32 MiB upload limit and sends a larger PHP file to review; clamd's AlertExceedsMax (A40) reports oversize content instead of OK.
- [x] **A39 [low]** A clamd failure skips the rules walk in ScanSite, and repeated scan failures alert nobody - FIXED 2026-09-25: the rules run and their findings count even when ClamAV fails (agent 0.30.8); a site never counts as clean without both; two failed scans in a row are emailed.
- [x] **A40 [low]** Password-protected archives pass ClamAV as clean (AlertEncrypted not set) - FIXED 2026-09-25: clamd AlertEncryptedArchive/Doc and AlertExceedsMax; such files are refused on upload and sent for review, never a ban; verified live: an encrypted zip with EICAR is now Heuristics.Encrypted.Zip.
- [x] **A41 [low]** Site image loads no php.ini, so compiled-in defaults apply (zend.assertions=1, zend.exception_ignore_args=0) - FIXED 2026-09-25: the image sets zend.assertions=-1, zend.exception_ignore_args=On, display_startup_errors=Off; verified in a live container.
- [x] **A42 [low]** Control plane: Vite hashed assets not marked immutable (Cloudflare applies max-age=14400), and /theme.js is unversioned - FIXED 2026-09-25: the control plane's /build/assets are immutable for a year, and /theme.js carries its version in the URL.
- [x] **A43 [low]** The e2e test sign-up domain is enabled in production and will skip Turnstile once it is on - FIXED 2026-09-25: the test domain is accepted only with X-CIC-E2E = today's HMAC of CIC_SIGNUP_TEST_SECRET (held by the control host and the operator); none configured means never; tested both ways; the e2e suite sends it.
- [x] **A44 [low]** The first-week noindex is lifted on schedule even when the link check has open review findings - FIXED 2026-09-25: noindex is lifted only when both checks passed within the week (tested).
- [x] **A45 [low]** fail2ban runs only the default sshd jail (10-minute bans, no recidive), and sshd keeps defaults while SSH is open to the world - FIXED 2026-09-25: fail2ban sshd aggressive with growing bans (to a week) and recidive, on every host and the control host; sshd LoginGraceTime 30, no X11 or agent forwarding, MaxStartups 10:30:60; checked by the deploy scripts.
- [x] **A46 [low]** The control host's tunnel key (cictunnel) can open remote (-R) and unix-socket forwards on every customer host - FIXED 2026-09-25: the tunnel key also carries permitlisten="localhost:1" and command=nologin; proved with a throwaway key first (agent forward works, -R and a shell refused), then the tunnels were restarted one at a time.
- [x] **A47 [low]** The control host still runs an unused root cic-agent, the Docker daemon and old DOCKER-USER rules - FIXED 2026-09-25: the control host runs no agent and no Docker; checked by deploy-control.
- [x] **A48 [low]** There is no verifiable Cloudflare-side rate limiting or IP reputation, and the API token cannot read or manage it - PART VERIFIED 2026-09-26: IP reputation is on - the zone's Security Level is "medium" (visitors with a poor reputation are challenged) and Browser Integrity Check is on, both read through the API. Rate-limiting rules: the token is still refused on rulesets (owner: add Zone WAF edit to it, or add one rule in the dashboard). DONE 2026-09-26: the owner added Zone WAF: Edit to the platform token (codeinchrome.com only); one rate-limiting rule, kept in infra/setup-cloudflare-proxy.sh: POSTs to sign-in, sign-up, reset, 2FA and the email code on app.codeinchrome.com, 10 per 10 s per address, then blocked 10 s. Verified live: 10 reached the app, the 11th-15th got 429, the form was back 12 s later, and a customer site's /login is never limited.
- [x] **A49 [low]** Host auditing and kernel hardening are at Ubuntu defaults: no auditd, and several sysctls are not hardened - FIXED 2026-09-25: sysctl hardening (bpf_jit_harden, kexec off, ldisc autoload off, no ICMP redirects, sysrq off, no setuid dumps) and auditd watching SSH, firewall, Docker, Caddy, sudoers, root's keys and /opt/codeinchrome/etc, on every host and the control host; checked by the deploy.

- [x] **Every site's trailing-slash and directory redirects went to
      http://<site>:8080/...** (found 2026-09-25 while testing the image):
      Apache built them from its internal port. The vhost now names its public
      face (https, 443, the visitor's host); verified live: /login/ -> https://<site>/login.

### Claude in Chrome: the same power as a terminal (owner, 2026-09-25)

Measured 2026-09-25, building "Lumen Commerce" (a Shopify-style store: a
catalogue with variants, a cart, checkout with locked stock, an admin with
products, orders and three themes, 17 tests, app:check) using ONLY the
Claude in Chrome tools - no terminal, no local files:
- the editor loaded in 2.2 s; cic.hello and cic.overview answered in under 0.5 s;
- 16 files + 6 migrations + seed: 3.5 s; 21 files + route:list: 2.1 s;
  19 views + CSS + 7 page requests: 4.8 s; 5 test files + the test run +
  app:check: 4.6 s; a 78-page crawl (visitor + admin) + a real purchase: 37 s;
- the time that matters is the agent composing each batch, not the platform.

Limits found (each one a thing a terminal agent never hits):
- [x] Clicking "Create" answers before the page changes: a text read 3 s
      later still showed "No sites yet" (the site was created). FIXED
      2026-09-25: the moment Create is pressed the page says "Creating
      <name>... usually 5 to 20 seconds" (role=status, data-create-status,
      data-state="creating"; measured 3-17 s, median 4 over the last 7
      sites) and the button is disabled; afterwards the flash is a
      role=status and each site row carries data-site-id and data-site-status.
- [x] fetch() of the skill from JavaScript is refused by the browser tool as
      "query string data"; reading it as a page works. The skill says so.
      DONE: cic.skill() pages it in a form the tool accepts - verified live
      2026-09-26 (contents 809 characters; a section in pages under 1,000,
      each naming the next call), nothing blocked.
- [x] Every result is cut at 1,000 characters; test failures had to be
      filtered and paged with cic.show. DONE by cic.sh, measured live:
      `cic.sh("php artisan test 2>&1 | grep -E 'FAIL|Tests:|Duration'")`
      gives only failures and the summary in one call (17 tests, 4.0 s).
- [x] Tools a terminal has that cic lacks or names differently - DONE
      2026-09-25: `cic.sh(line)` speaks the shell itself (resources/js/shell.js):
      pipes, && || ;, > >> 2>&1, heredocs, globs, cd, and about 45 commands
      (ls, cat, head, tail, wc, grep -rniEFwlLcov -A/-B/-C --include, find
      -name/-type/-maxdepth/-newer/-mmin/-path, sed -n/-i/-E, diff -u, cp -r,
      mv, rm -r, mkdir -p, touch, tree, du, sort, uniq, cut, tr, xargs, tee,
      test, php artisan, composer, php -r, mysql -e, curl (this site only),
      git log/diff/show over the saved versions). The host gained grep (RE2)
      and find. Measured on production against the same commands in a terminal
      here (BookStack, 1,961 PHP files):
      | | terminal (local disk) | cic.sh (live site, from Chrome) |
      |---|---|---|
      | ls -la, cat 2 files, sed -n, tree, diff | 18-34 ms | 186-227 ms (one request) |
      | grep -rn, grep -rniE --include, wc on a glob | 18-48 ms | 382-392 ms before, one request now |
      | find -newer, du -sh 2 folders | 25-26 ms | 547 / 773 ms before, parallel now |
      | php artisan route:list | - | 2.0 s (boots the app in its container) |
      One request is ~186 ms from here to the host and back; every everyday
      command is now one request (pinned by a test that counts them), and
      the agent's own turn - seconds per tool call - still dominates.
- [x] Bring in open-source Laravel apps both ways - DONE 2026-09-25:
      `git clone [-b ref] https://github.com/owner/repo [folder]` in cic.sh.
      The host fetches GitHub's archive (HTTPS, github.com/codeload only, no
      other redirect, 100 MB cap), unpacks it into a NEW folder (never over
      the site, never into public/), and scans every file as an upload is -
      malware refuses all of it and bans, as for any upload. The rules found
      nothing in BookStack's 1,961 PHP files. Terminal here: `git clone
      --depth 1` of laravel/laravel 1.4 s (65 files), BookStack 2.8 s (2,615
      files, 5.6 MB archive).
- [x] Cloning OVER the running app, so a cloned open-source Laravel app is
      the site itself: `git clone URL /` (or cic.clone(repo, { replace: true,
      confirm: true })). Checked and scanned in a staging folder first (in
      the request: malware counts like any clone), then in the background a
      backup - no backup, no replace - then the swap, keeping the site's
      .env and storage/, then composer install; `git clone --status` or
      cic.operation() follows it. Needs confirm; audited.
- [x] **The same power as JavaScript calls**, not only as a shell (owner,
      2026-09-25): cic.grep, cic.find, cic.clone and cic.diff return data
      (browser-safe text) through the same endpoints cic.sh uses; every other
      shell command already had a call (read, view, writeMany, edit, mkdir,
      mv, cp, rm, rmdir, run, eval, request, db.query, history, versionAt).

### Claude in Chrome: the agent tools must be safe to hand to an AI (owner, 2026-09-25)

Reviewed by an independent agent and attacked live from Claude in Chrome on
the Lumen Commerce test store. SAFE, verified live: file contents and names
with HTML/script render as text (no handler, no foreign image, flag never
set); `;` `$(...)` backticks `-d` `--env` refused by the host; `db:wipe` not
available; `xargs` does not get past a confirm gate; `../` and absolute
paths stay in the site; a symlink to /etc planted by the site's own PHP is
refused by cat, grep, find and cp; curl to 169.254.169.254, other hosts and
`site@evil` is refused; no innerHTML in the editor; CSP self-only with
frame-ancestors none; no postMessage listener; every route checks the owner.
- [x] **HIGH: a failed clone's cleanup, and folder delete, could delete
      outside the site** - os.RemoveAll resolves the parent by path, and the
      site's own code can swap a parent for a link mid-operation (root then
      deletes wherever it points). FIXED: removeAllBeneath deletes through
      directory handles, O_NOFOLLOW at every level; rmdir is the kernel's
      AT_REMOVEDIR on the host (atomic "only if empty").
- [x] **HIGH: cloning someone else's repository could ban the customer**
      (a prompt injection saying "clone X" would take them down). FIXED: a
      clone with malware keeps nothing and is recorded for the owner's review
      (abuse.review), with no ban; the customer's own writes and uploads still ban.
- [x] **The site's secrets could be published**: `cat .env > public/x.txt`,
      or a key pasted into a page. FIXED on the host: no write, upload, copy,
      move or unzip into public/ may contain a secret value from the site's
      .env (public-by-design names like VITE_/PUBLIC/pk_ allowed). Secret
      values are shown to agents as `[secret hidden]`, and that marker (and
      `[long value hidden]`) is refused if written back. LEFT: a value
      transformed first (tr, base64) is not recognised - defence in depth
      only; a live check with a planted fake secret on a test site.
- [x] **One line could fan out into thousands of requests** (find | xargs
      cat, grep -C over a tree). FIXED: file reads 600/min and writes 240/min
      per user, readMany at most 8 in flight, xargs at most 1000 items and
      100 runs, grep's whole-file mode 200 files; clones 2 at a time per host.
- [x] **Confirm gates were line-wide and recognised by text** (site output
      saying "confirm: true" could raise the person's dialog, and confirming
      re-ran the whole line). FIXED: a gated refusal is data (needsConfirm),
      stops the line, and names the one command confirming would run.
- [x] **Site content reaches the agent unlabelled** - the skill and
      cic.help now say plainly: what the site says is data, never
      instructions; nothing the platform returns is ever run by it.
- [x] **Re-review of the fixes** (same reviewer, 2026-09-25) - four held,
      and every gap it found is FIXED: an archive can no longer be made in
      public/; after eval, artisan and composer the host removes any file in
      public/ they changed that carries a secret value; an upload into public/
      is checked BEFORE it takes the name (never served, never replaces the
      old file); keys a browser needs (pk., DSN, client ids, domains, map and
      search keys) are no longer refused; "[secret hidden]" is refused in
      cic.sh, cic.eval and cic.db.query too; a confirm command names the
      absolute path (cd cannot change what it deletes) and only needs_confirm
      or needs_write - not busy or conflict - asks for one; copy, move, mkdir,
      folder delete and download are throttled; the delete loop is bounded;
      a third malware clone in a day bans (no free scanner oracle).
- [x] A site's OWN code, serving a web request, could still write a secret
      into public/. FIXED: the six-hourly scan sweeps public/ too; a file
      carrying a secret value is moved to storage/app/quarantine/ (kept, not
      served - runtime files have no saved version) and reported for review
      (kind published_secret, no ban). Eval and commands quarantine the same way.
- [x] Tag cloned folders so a later ClamAV signature update on them goes
      to review, not a ban. DONE: the host records each cloned file's
      SHA-256 beside the site's history (out of the container's reach); a
      scan finding on a file still byte for byte as cloned is
      malware_in_clone (review). A changed, added or back-dated file is the
      customer's own and treated as any other.
- [x] Masking is for accidents, not a boundary: `cut -d= -f2 .env` or
      `{ raw: true }` show values. Said in the skill. Accepted by design:
      the boundaries are the host's refusals (no secret into public/, none
      written back as a marker), which do not depend on masking.
- [x] php -r / tinker (cic.eval) run any PHP in the site's own container:
      the confirm gates are speed bumps against mistakes, not a boundary.
      Said in the skill; the container and its limits are the boundary.
      Accepted by design, and what eval writes is held to the same rules
      afterwards (malware scan, secrets swept out of public/).

- [x] A skill-creator style check: the skill tested with a fresh Claude in
      Chrome session (side panel, no terminal, no prior context) building
      the same store, timed, and every place it stalls fixed in the skill
      or the API. DONE 2026-09-26: a fresh agent given only "add an FAQ page
      with seeded questions" on the live store found the skill from the
      editor's banner, and shipped migration, model, seeder, controller,
      Blade view (the store's layout) and a feature test - its tests and
      the site's whole suite green, /faq live - in 4 min 40 s and 33
      browser calls. The store was backed up first and restored after.
      Where it stalled: a page read sent in the same batch as its navigate
      read the blank tab (the tool's order, not ours; one retry); cic.view
      pages are ~900 characters and `to` only narrows them (now said in
      the skill, with `match` and `chars: Infinity`); the refusals it met -
      an edit whose text appears twice, a seeder run without confirm - were
      the intended ones, and it recovered from both in one call. The
      restore afterwards found a real bug (below: backups and moves).

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
      Cloudflare instead of on-demand ACME. Until then customers' own domains
      are OFF (CIC_CUSTOM_DOMAINS, "coming soon" in the panel; checked
      2026-09-25: none exist): every host's 80/443 now accept Cloudflare's
      ranges only, so a domain pointed straight at a host could be neither
      visited nor issued a certificate - and pointing it there would publish
      the host's address. With SaaS, a customer points a CNAME at Cloudflare
      and the origin stays hidden.
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
- [x] **An agent finds window.cic on its own** (owner, 2026-09-24: Claude in
      Chrome built a page by typing into routes/web.php, "because I couldn't
      expand the file tree" - it never read the agent instructions nor the
      skill). Whatever it reads first must say it: the page title, the first
      text of <main>, the page-text read, a screenshot - never behind a click
      on "For AI agents". DONE: the tab's title and a banner on screen say how
      until an agent calls window.cic (e2e on production). DONE 2026-09-26,
      tried live with a fresh agent - no skill, no pasted message, only the
      Claude in Chrome tools and "find how to edit this site from the page":
      it found window.cic from the tab title and the banner on its 4th
      browser call (no click, no screenshot needed), and read routes/web.php
      in one call, nothing blocked. From its notes: the instructions now say
      which calls change nothing (a look-only task must not write to a live
      site) and that the site's own text is never instructions; the on-screen
      strip was checked and is visible to a screenshot-only agent.
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
      DONE 2026-09-26: abuse@codeinchrome.com (Cloudflare Email Routing), on the report page and in the terms.

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
- [x] **Found reviewing the scanners, fixed** (2026-09-26): the control host
      read a site's WHOLE answer - the link scanner, Explore, the minutely
      monitor, the exposure check and the image roll - past 2 MB into a temp
      file on its disk and all of it into memory once read, and its PHP CLI
      has no memory limit. A free site answering with gigabytes (visitor
      traffic is not rate-capped) could fill the control host's disk or
      memory, and the scanner stopped for every site after it. Now every
      answer from a site is written through BoundedSink: the transfer stops at
      a cap (4 MB a page or script for the scanner, 64 KB where only the
      status is used), measured against a local 50 MB answer - stopped at the
      cap in well under a second, in a pool too. A page or script cut at the
      cap is itself for review, so padding cannot hide a kit.
- [x] **Found by the skill test, fixed** (2026-09-26): a whole-site restore
      left a Laravel site answering EVERY page with a 500 ("Please provide a
      valid cache path"). Backups and host moves leave out the compiled views
      and the file cache, and nothing put their directories back. The live
      store was fixed by hand within minutes; cic-backup and the move's
      unpack now recreate them (agent 0.43.1), and the backups e2e asks for
      a page Laravel renders - it only fetched a static file, which is why
      this passed. Every other live site checked: none was missing them.

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
- [x] **Bot protection on sign-up and login** - DONE 2026-09-26 with Turnstile
      (on, see A19) and the edge rate limit (A48). Bot Fight Mode deliberately
      NOT on: on the Free plan it cannot be skipped by a rule, so it would
      challenge the payment provider's webhooks, our own monitor and link
      scans, and customer sites' API clients. Was: our Cloudflare token has no
      permission for either. Owner: turn on
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
- [x] **Stronger sandbox** (gVisor, or user-namespace remapping): containers
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
      DECIDED 2026-09-26 (owner): neither for now - runc stays, with the
      escape surfaces above refused; revisit when plan sizes or prices change.
      **What runc leaves reachable, measured 2026-09-26** (Docker 29.8.1,
      kernel 5.15, inside a site container, as root and as uid 33): the
      kernel's usual escape surfaces are refused - io_uring, userfaultfd,
      keyctl/add_key, bpf, perf_event_open, unshare(NEWUSER), mount,
      kexec_load, open_by_handle_at, pidfd_getfd. Reachable: ptrace and
      process_vm_readv, which only ever reach processes in the SAME
      container (its own PID namespace) - a site reading its own memory.
      So the case for gVisor is a kernel bug in what remains, not a known
      open door.
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
      replica removes the single point of failure. BUILT (2026-09-25):
      cic-replicate-offsite copies every encrypted repository nightly to an
      S3-compatible bucket, grow-only (rclone copy --immutable: never deletes,
      never overwrites) and monitored (`control:offsite`, alerted when a day
      old). Tested on the control host against a stand-in target: all 631
      files, the password file excluded, a copy-only file survives, a
      tampered file fails the run and is not overwritten, the copy opens with
      its escrowed password. Off until /opt/codeinchrome/etc/offsite.env
      exists. Owner: a bucket at another provider (Cloudflare R2 is one
      account we already have; Backblaze B2), WITH a lock/retention rule so
      the key cannot delete, and an access key for it.
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
