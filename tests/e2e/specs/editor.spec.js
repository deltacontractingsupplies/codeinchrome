import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';
import { fleetHostIps } from '../helpers/control-host.js';

/**
 * The editor, driven the two ways it is meant to be: by a person clicking and
 * typing, and by an agent calling window.cic. Against a real site.
 */

const stamp = Date.now().toString(36);
const siteName = `ed-${stamp}`.slice(0, 40);
const password = `ed-${stamp}-${randomBytes(9).toString('hex')}-Wq3`;

test.describe.configure({ mode: 'serial' });
// One long journey through the whole editor, including the PHP language
// server's first index of the site (up to a minute): more than the default.
test.setTimeout(600_000);
test.afterAll(() => destroySite(siteName));

// Put the caret at the very end of the editor. Keyboard shortcuts for this
// differ by platform (Cmd+Down on macOS, Ctrl+End elsewhere); the result is
// what matters, not the key.
const toEnd = async (page) => {
  await page.locator('.monaco-editor .view-lines').click();
  await page.keyboard.press('ControlOrMeta+End');
};
// The editor as the person sees it (Monaco), unsaved edits included.
const shown = (page) => page.evaluate(() => window.cic?.buffer() ?? { content: '' });
const expectShown = async (page, matcher) => {
  await expect.poll(async () => (await shown(page)).content).toMatch(matcher);
};

test('a person and an agent can both edit a real site, without erasing each other', async ({ page, browser }) => {
  // Any native dialog is a failure: it would freeze the page for an agent.
  page.on('dialog', (d) => {
    throw new Error(`native ${d.type()} dialog: ${d.message()}`);
  });

  await test.step('sign up, create a site, open the editor', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Editor Runner');
    await page.getByLabel('Email').fill(`ed-${stamp}@codeinchrome.test`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, `ed-${stamp}@codeinchrome.test`);

    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    await waitForDns(`${siteName}.codeinchrome.com`);

    await page.getByRole('link', { name: 'Edit code' }).click();
    await expect(page).toHaveURL(new RegExp(`/sites/${siteName}/edit$`));
    // It opens routes/web.php on its own, from the real site.
    await expectShown(page, /Route::/);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
  });

  await test.step('an agent that never loaded the skill reads it here and proves it is connected; the person sees it', async () => {
    const editUrl = page.url();
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(editUrl).origin });
    // A fresh tab: nothing has called window.cic in it yet.
    const fresh = await page.context().newPage();
    await fresh.goto(editUrl);
    await expect(fresh.locator('#sbMsg')).toHaveText(/^(Ready|Restored)/);
    // The editor's own loading never counts as an agent.
    await expect(fresh.locator('#agentBadge')).toBeHidden();
    // An agent that reads only the screen or the tab list still learns how
    // to work here, without clicking anything.
    await expect(fresh.locator('#agentBanner')).toBeVisible();
    await expect(fresh.locator('#agentBanner')).toContainText('await cic.hello()');
    await expect(fresh).toHaveTitle(/AI agent: run await cic\.hello\(\) in this page$/);

    // The message the person pastes into their agent's chat.
    await fresh.getByRole('button', { name: 'Copy for agent' }).click();
    const message = await fresh.evaluate(() => navigator.clipboard.readText());
    expect(message).toContain(`${siteName}.codeinchrome.com`);
    expect(message).toContain(`/sites/${siteName}/edit`);
    expect(message).toContain('/agent/skill.md');
    expect(message).toContain('await cic.hello()');
    await expect(fresh.locator('#agentBadge')).toBeHidden(); // a button press is not an agent

    const r = await fresh.evaluate(async () => ({
      contents: await cic.skill(),
      section: await cic.skill('Step 3'),
      next: await cic.skill('Step 3', 2),
      none: await cic.skill('no such section'),
      hello: cic.hello(),
    }));
    // Each answer is one a browser tool prints whole (it cuts at 1,000 characters).
    for (const answer of [r.contents, r.section, r.next]) expect(answer.length).toBeLessThan(1000);
    expect(r.contents).toContain('Read it ALL before you change anything');
    expect(r.contents).toContain('/agent/skill.md');
    expect(r.contents).toContain('Step 3 - build it');
    expect(r.section).toMatch(/^## Step 3 - build it/);
    expect(r.section).toMatch(/\[page 1 of \d+: await cic\.skill\("Step 3", 2\) for more\]$/);
    expect(r.next).toMatch(/\[(page 2 of \d+|end of "Step 3")/);
    expect(r.none).toContain('No section about "no such section"');
    expect(r.hello).toContain(`codeinchrome editor connected: ${siteName}.codeinchrome.com`);
    await expect(fresh.locator('#agentBadge')).toBeVisible();
    await expect(fresh.locator('#agentBadge')).toHaveAttribute('title', /cic\.hello/);
    // Its work done, the hint steps aside for the person.
    await expect(fresh.locator('#agentBanner')).toBeHidden();
    await expect(fresh).not.toHaveTitle(/AI agent/);

    // The same skill, whole, as an agent's page-text tool reads it.
    const md = await fresh.request.get('/agent/skill.md');
    expect(md.status()).toBe(200);
    expect(md.headers()['content-type']).toBe('text/plain; charset=utf-8');
    expect(await md.text()).toContain('name: codeinchrome');
    await fresh.close();
  });

  await test.step('the site was given its own MySQL database', async () => {
    const env = await page.evaluate(() => window.cic.read('/.env'));
    expect(env.ok).toBe(true);
    expect(env.content).toMatch(/^DB_CONNECTION=mysql$/m);
    expect(env.content).toMatch(/^DB_HOST=cic-db$/m);
    expect(env.content).toMatch(new RegExp(`^DB_DATABASE=site_${siteName.replace(/-/g, '_')}$`, 'm'));
    expect(env.content).toMatch(/^DB_PASSWORD=[0-9a-f]{64}$/m);
  });

  await test.step('PHP IntelliSense runs in the site: hover, go to definition, completion', async () => {
    // Phpactor indexes the site first (~15 s cold), so the first answers are retried.
    await expect(async () => {
      const h = await page.evaluate(() => window.cic.php.hover('/routes/web.php', 5, 3));
      expect(h.ok, h.hint).toBe(true);
      expect(h.text).toContain('Route');
    }).toPass({ timeout: 90_000, intervals: [3_000] });

    const def = await page.evaluate(() => window.cic.php.definition('/routes/web.php', 5, 3));
    expect(def.ok, def.hint).toBe(true);
    expect(def.locations[0].path).toContain('/vendor/laravel/framework/');

    expect((await page.evaluate(() => window.cic.write('/app/Probe.php', "<?php\n\nuse Illuminate\\Support\\Str;\n\nStr::sl"))).ok).toBe(true);
    const comp = await page.evaluate(() => window.cic.php.complete('/app/Probe.php', 5, 8));
    expect(comp.ok, comp.hint).toBe(true);
    expect(comp.items.map((i) => i.label)).toContain('slug');
    expect((await page.evaluate(() => window.cic.rm('/app/Probe.php'))).ok).toBe(true);

  });

  await test.step('Laravel Boost MCP answers the agent from the site itself, read-only', async () => {
    const tools = await page.evaluate(() => window.cic.mcp.tools());
    expect(tools.ok, tools.hint).toBe(true);
    expect(tools.tools.map((t) => t.name)).toEqual(expect.arrayContaining(['application-info', 'database-schema', 'database-query', 'last-error', 'search-docs']));

    const info = await page.evaluate(() => window.cic.mcp.call('application-info', {}));
    expect(info.ok).toBe(true);
    expect(JSON.parse(info.text).database_engine).toBe('mysql');

    const write = await page.evaluate(() => window.cic.mcp.call('database-query', { query: 'drop table migrations' }));
    expect(write.ok).toBe(false);
    const still = await page.evaluate(() => window.cic.db.query('select count(*) as n from migrations'));
    expect(still.ok).toBe(true);
    // cic.db.query shows the Database view to the person watching; back to Files.
    await page.getByRole('tab', { name: 'Files' }).click();
  });

  await test.step('the explorer shows the real Laravel tree, with the open file revealed', async () => {
    await expect(page.locator('.node.active .nm')).toHaveText('web.php');
    for (const name of ['app', 'public', 'routes', 'artisan', 'composer.json']) {
      await expect(page.locator('.node .nm').getByText(name, { exact: true }).first()).toBeVisible();
    }
  });

  await test.step('a person types and saves with the keyboard', async () => {
    await toEnd(page);
    await page.keyboard.type("\n// edited by a person\n");
    await expect(page.locator('.tab.active')).toHaveClass(/dirty/);

    await page.keyboard.press('ControlOrMeta+s');
    await expect(page.locator('#sbMsg')).toHaveText('Saved /routes/web.php');
    await expect(page.locator('.tab.active')).not.toHaveClass(/dirty/);

    const disk = await page.evaluate(() => window.cic.read('/routes/web.php'));
    expect(disk.content).toContain('// edited by a person');
  });

  await test.step('a hostile file is shown as text, never run', async () => {
    // If the highlighter ever rendered content as markup, this would execute.
    const payload = '<?php // <img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script>\n$x = "</span><svg onload=window.__pwned=3>";\n';
    const res = await page.evaluate((p) => window.cic.write('/app/Hostile.php', p, { expect: 'absent' }), payload);
    expect(res.ok).toBe(true);

    await page.evaluate(() => window.cic.open('/app/Hostile.php'));
    await expect.poll(async () => (await shown(page)).content).toBe(payload);
    // What the editor draws: Monaco's rendered lines show the text...
    const lines = page.locator('.monaco-editor .view-lines');
    await expect(lines).toContainText('onerror');

    // ...and none of it became an element or ran.
    expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
    expect(await lines.locator('img, script, svg').count()).toBe(0);
  });

  await test.step('an agent edit reaches the person watching', async () => {
    await page.evaluate(() => window.cic.open('/routes/web.php'));
    const before = await page.evaluate(() => window.cic.read('/routes/web.php'));

    const res = await page.evaluate(
      (c) => window.cic.write('/routes/web.php', c),
      before.content + '// edited by the agent\n',
    );
    expect(res.ok).toBe(true);
    // The revision it presented was the one it had just read.
    expect(res.note).toBeUndefined();

    // The open, unmodified tab now shows the agent's change.
    await expectShown(page, /edited by the agent/);
  });

  await test.step('the person sees each file the agent writes as it is written, not at the end', async () => {
    // A file that is NOT open: following the agent opens it in a preview tab,
    // with the lines it wrote highlighted, and the step is in the Agent panel.
    const res = await page.evaluate(() => window.cic.write('/app/Support/LiveProof.php', "<?php\n\nnamespace App\\Support;\n\nclass LiveProof {}\n"));
    expect(res.ok, res.hint).toBe(true);
    await expect(page.locator('#tabs .tab.agent-preview .tab-name', { hasText: 'LiveProof.php' })).toBeVisible();
    await expectShown(page, /class LiveProof/);
    await expect(page.locator('.monaco-editor .agent-line').first()).toBeVisible();
    await expect(page.locator('#agentView')).toBeVisible();
    await expect(page.locator('#agentLog .step.done', { hasText: 'Write /app/Support/LiveProof.php' })).toBeVisible();
    await expect(page.locator('#tree .node[data-path="/app/Support/LiveProof.php"] .chg-A')).toHaveText('A');

    // The next file replaces the preview tab (no pile of tabs from a big write),
    // and an edit highlights only what it changed.
    await page.evaluate(() => window.cic.writeMany({ '/app/Support/LiveProof2.php': "<?php\n// two\n" }));
    await expect(page.locator('#tabs .tab-name', { hasText: 'LiveProof2.php' })).toBeVisible();
    await expect(page.locator('#tabs .tab-name', { hasText: /^LiveProof\.php$/ })).toHaveCount(0);
    const edited = await page.evaluate(() => window.cic.edit('/app/Support/LiveProof2.php', { find: '// two', replace: '// two, edited' }));
    expect(edited.ok, edited.hint).toBe(true);
    await expect(page.locator('#agentLog .step.done', { hasText: 'Edit /app/Support/LiveProof2.php' })).toBeVisible();
    await expect(page.locator('.monaco-editor .agent-line')).toHaveCount(1);

    // A command is a step with its exit code; a deletion is marked D.
    await page.evaluate(() => window.cic.rm('/app/Support/LiveProof.php'));
    await page.evaluate(() => window.cic.rm('/app/Support/LiveProof2.php'));
    await expect(page.locator('#agentLog .step.done', { hasText: 'Delete /app/Support/LiveProof.php' })).toBeVisible();

    // Following is the person's choice, and it is remembered.
    await page.locator('#agentFollow').uncheck();
    expect(await page.evaluate(() => localStorage.getItem('cic.followAgent'))).toBe('off');
    await page.locator('#agentFollow').check();
  });

  await test.step('a stale save is refused and the person is told, not overwritten', async () => {
    // The person starts typing...
    await toEnd(page);
    await page.keyboard.type('// person, unsaved\n');

    // ...and meanwhile a SECOND client - another tab, another agent - saves.
    const other = await browser.newContext({ storageState: await page.context().storageState() });
    const otherPage = await other.newPage();
    await otherPage.goto(`/sites/${siteName}/edit`);
    await expect(otherPage.locator('#sbMsg')).toHaveText('Ready');
    const theirs = await otherPage.evaluate(async () => {
      const r = await window.cic.read('/routes/web.php');
      return window.cic.write('/routes/web.php', r.content + '// other client\n', { expect: r.revision });
    });
    expect(theirs.ok).toBe(true);
    await other.close();

    // The person saves. The server must refuse; nothing is written.
    await page.keyboard.press('ControlOrMeta+s');
    await expect(page.locator('#conflict')).toBeVisible();
    await expect(page.locator('#sbMsg')).toContainText('changed since you opened it');

    const disk = await page.evaluate(() => window.cic.read('/routes/web.php'));
    expect(disk.content).toContain('// other client');
    expect(disk.content).not.toContain('// person, unsaved');

    // The person chooses to keep theirs, deliberately.
    await page.getByRole('button', { name: 'Keep mine and overwrite' }).click();
    await expect(page.locator('#sbMsg')).toHaveText('Saved /routes/web.php');
    await expect(page.locator('#conflict')).toBeHidden();
    const after = await page.evaluate(() => window.cic.read('/routes/web.php'));
    expect(after.content).toContain('// person, unsaved');
  });

  await test.step('unsaved work survives a reload', async () => {
    await toEnd(page);
    await page.keyboard.type('// draft that must survive\n');
    await page.reload();

    await expect(page.locator('#sbMsg')).toContainText('Restored 1 unsaved');
    await expectShown(page, /draft that must survive/);
    await expect(page.locator('.tab.active')).toHaveClass(/dirty/);
  });

  await test.step('creating a file never replaces an existing one', async () => {
    const res = await page.evaluate(() => window.cic.write('/routes/web.php', 'clobbered', { expect: 'absent' }));
    expect(res.status).toBe(409);
    const disk = await page.evaluate(() => window.cic.read('/routes/web.php'));
    expect(disk.content).not.toBe('clobbered');
  });

  await test.step('the database browser shows the site database, as the site', async () => {
    await page.getByRole('tab', { name: 'Database' }).click();
    await expect(page.locator('#dbName')).toHaveText(`SITE_${siteName.replace(/-/g, '_').toUpperCase()}`);
    await page.locator('.tbl', { hasText: 'users' }).first().click();
    await expect(page.locator('#dbGrid th').first()).toHaveText('id');
    await expect(page.locator('#dbMeta')).toContainText('· read');

    // Another tenant's data, and MySQL's own tables, are out of reach.
    const denied = await page.evaluate(() => window.cic.db.query('SELECT user FROM mysql.user'));
    expect(denied.ok).toBe(false);
    expect(denied.hint).toContain('denied');
  });

  await test.step('a statement that changes data asks a person first, and runs nothing until they agree', async () => {
    await page.locator('#sql').fill('DELETE FROM users');
    await page.getByRole('button', { name: 'Run', exact: true }).click();
    await expect(page.locator('#modal')).toBeVisible();
    await expect(page.locator('#modalText')).toContainText('may change data');
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('#dbMeta')).toHaveText('Not run.');
  });

  await test.step('an agent must ask for write explicitly; it is never confirmed for it', async () => {
    const insert = "INSERT INTO users (name, email, password) VALUES ('<img src=x onerror=\"window.__dbpwned=1\">', 'x@example.test', 'x')";
    const refused = await page.evaluate((q) => window.cic.db.query(q), insert);
    expect(refused.status).toBe(409);
    expect(refused.error).toBe('needs_write');
    await expect(page.locator('#modal')).toBeHidden();

    const written = await page.evaluate((q) => window.cic.db.query(q, { write: true }), insert);
    expect(written.ok).toBe(true);
    expect(written.result.rowsAffected).toBe(1);
  });

  await test.step('stored markup in a row is shown as text, never run', async () => {
    const res = await page.evaluate(() => window.cic.db.query('SELECT name FROM users'));
    expect(res.result.rows[0][0]).toContain('<img');
    await expect(page.locator('#dbGrid td').first()).toContainText('<img src=x');
    expect(await page.locator('#dbGrid img').count()).toBe(0);
    expect(await page.evaluate(() => window.__dbpwned)).toBeUndefined();
    await page.getByRole('tab', { name: 'Files' }).click();
  });

  await test.step('artisan runs in the site, and its output comes back', async () => {
    const res = await page.evaluate(() => window.cic.run('artisan', ['make:model', 'Invoice', '--migration']));
    expect(res.ok, res.result?.output).toBe(true);
    expect(res.result.output).toContain('Invoice');
    await expect(page.locator('#termOut')).toContainText('$ artisan make:model Invoice --migration');

    const model = await page.evaluate(() => window.cic.read('/app/Models/Invoice.php'));
    expect(model.content).toContain('class Invoice extends Model');

    const migrated = await page.evaluate(() => window.cic.run('artisan', ['migrate']));
    expect(migrated.ok, migrated.result?.output).toBe(true);
    const tables = await page.evaluate(() => window.cic.db.tables());
    expect(tables.tables.map((t) => t.name)).toContain('invoices');
  });

  await test.step('the database was saved before the migration, and it can be put back', async () => {
    const snaps = await page.evaluate(() => window.cic.db.snapshots());
    expect(snaps.ok, snaps.hint).toBe(true);
    const before = snaps.snapshots.find((s) => s.reason === 'before-migrate');
    expect(before, JSON.stringify(snaps.snapshots)).toBeTruthy();
    await expect(page.locator('#dbSnapshots li', { hasText: 'before migrate' }).first()).toBeAttached();

    // Never without confirm: the database is untouched.
    const refused = await page.evaluate((n) => window.cic.db.restore(n), before.name);
    expect(refused.error).toBe('needs_confirm');
    expect((await page.evaluate(() => window.cic.db.tables())).tables.map((t) => t.name)).toContain('invoices');

    // Restored: the invoices table (made by that migration) is gone again,
    // and what was there is itself a snapshot, so the restore is undoable.
    const restored = await page.evaluate((n) => window.cic.db.restore(n, { confirm: true }), before.name);
    expect(restored.ok, restored.hint).toBe(true);
    expect((await page.evaluate(() => window.cic.db.tables())).tables.map((t) => t.name)).not.toContain('invoices');
    const after = await page.evaluate(() => window.cic.db.snapshots());
    expect(after.snapshots[0].reason).toBe('before-import');

    // Put the migration back, for the steps that follow.
    const again = await page.evaluate(() => window.cic.run('artisan', ['migrate']));
    expect(again.ok, again.result?.output).toBe(true);
  });

  await test.step('a destructive command runs nothing without confirm', async () => {
    const refused = await page.evaluate(() => window.cic.run('artisan', ['migrate:fresh']));
    expect(refused.status).toBe(409);
    expect(refused.error).toBe('needs_confirm');
    const still = await page.evaluate(() => window.cic.db.tables());
    expect(still.tables.map((t) => t.name)).toContain('invoices');
  });

  await test.step('commands outside the allow-list are refused', async () => {
    for (const [tool, args] of [['artisan', ['tinker']], ['artisan', ['migrate', '--env=testing']], ['composer', ['exec', 'bash']]]) {
      const res = await page.evaluate(([t, a]) => window.cic.run(t, a), [tool, args]);
      expect(res.ok, `${tool} ${args.join(' ')}`).toBe(false);
      expect(res.result).toBeUndefined();
    }
  });

  await test.step('composer require works inside the plan memory limit, its output shown as it prints', async () => {
    // Not awaited: while composer runs, its output reaches the terminal -
    // the person sees it working, not a silent wait and then all of it.
    await page.evaluate(() => {
      window.__composerDone = false;
      window.__composer = window.cic.run('composer', ['require', 'spatie/array-to-xml']).finally(() => { window.__composerDone = true; });
    });
    // composer takes seconds here; its first lines must be on screen before it ends.
    await expect.poll(() => page.evaluate(() => ({
      shown: /Using version|has been updated|Loading composer|Updating dependencies/.test(document.querySelector('#termOut').textContent),
      done: window.__composerDone,
    })), { timeout: 60_000, intervals: [200] }).toMatchObject({ shown: true });
    expect(await page.evaluate(() => window.__composerDone), 'output appeared only when composer had finished').toBe(false);
    const res = await page.evaluate(() => window.__composer);
    expect(res.ok, res.result?.output?.slice(-2000)).toBe(true);
    // Nothing shown twice: the live part and the rest add up to the output once.
    const text = await page.locator('#termOut').textContent();
    expect(text.split('./composer.json has been updated').length - 1).toBeLessThanOrEqual(1);
    expect(text).toContain('spatie/array-to-xml');
    const lock = await page.evaluate(() => window.cic.read('/composer.json'));
    expect(lock.content).toContain('spatie/array-to-xml');
  });

  await test.step('the request log shows real requests', async () => {
    const [address] = await waitForDns(`${siteName}.codeinchrome.com`);
    await httpsGet(`${siteName}.codeinchrome.com`, '/?from=the-log-test', address);
    await expect.poll(async () => {
      const res = await page.evaluate(() => window.cic.logs('access', 50));
      return res.log?.lines ?? '';
    }, { intervals: [2_000], timeout: 30_000 }).toContain('/?from=the-log-test');
  });

  await test.step('the change is live on the site itself', async () => {
    const [address] = await waitForDns(`${siteName}.codeinchrome.com`);

    // The route also has the SITE'S OWN PHP write a real binary file. The
    // Laravel skeleton ships none (its favicon.ico is 0 bytes, which is valid
    // text), and the file API can only write text - so this is the honest way
    // to put genuine binary bytes on disk for the next step.
    const route = String.raw`<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    file_put_contents(public_path('probe.bin'), "\x89PNG\r\n\x1a\n\0\0\xff\xfe");
    return 'hello from the editor';
});
`;
    const res = await page.evaluate((c) => window.cic.write('/routes/web.php', c), route);
    expect(res.ok).toBe(true);

    // Polled: opcache revalidates every 2 seconds, and the first HTTPS
    // request is also the one that obtains the certificate.
    await expect.poll(async () => {
      const r = await httpsGet(`${siteName}.codeinchrome.com`, '/', address);
      return r.body.trim();
    }, { intervals: [3_000], timeout: 150_000 }).toBe('hello from the editor');
  });

  await test.step('binary files are refused rather than corrupted', async () => {
    const listing = await page.evaluate(() => window.cic.ls('/public'));
    const probe = listing.listing.entries.find((e) => e.name === 'probe.bin');
    expect(probe, 'the site should have written probe.bin').toBeTruthy();
    expect(probe.size).toBe(12);

    const res = await page.evaluate(() => window.cic.read('/public/probe.bin'));
    expect(res.ok).toBe(false);
    expect(res.hint).toContain('binary');
  });

  await test.step('every save is a version; an old one opens read-only and can be restored', async () => {
    await page.evaluate(async () => {
      await window.cic.write('/resources/views/hist.blade.php', 'version one', { expect: 'absent' });
      await window.cic.read('/resources/views/hist.blade.php');
      await window.cic.write('/resources/views/hist.blade.php', 'version two');
    });
    const h = await page.evaluate(() => window.cic.history('/resources/views/hist.blade.php'));
    expect(h.ok).toBe(true);
    expect(h.versions.map((v) => v.message)).toEqual(['save resources/views/hist.blade.php', 'save resources/views/hist.blade.php']);

    // A person looks at the first version in the History panel.
    await page.evaluate(() => window.cic.open('/resources/views/hist.blade.php'));
    await page.getByRole('tab', { name: 'History' }).click();
    await page.locator('#historyList .node').nth(1).click();
    await expect.poll(async () => (await shown(page)).content).toBe('version one');
    expect((await shown(page)).readOnly).toBe(true);
    await expect(page.locator('#versionBar')).toBeVisible();

    // Restore it: it becomes current, and the restore is itself a version.
    await page.getByRole('button', { name: 'Restore this version' }).click();
    await page.locator('#modalOk').click();
    await expect(page.locator('#sbMsg')).toContainText('Restored /resources/views/hist.blade.php');
    await expect.poll(async () => (await shown(page)).content).toBe('version one');
    const after = await page.evaluate(() => window.cic.history('/resources/views/hist.blade.php'));
    expect(after.versions[0].message).toMatch(/^restore resources\/views\/hist\.blade\.php from [0-9a-f]{7}$/);
  });

  await test.step('a deleted file waits in the bin and comes back', async () => {
    await page.evaluate(() => window.cic.rm('/resources/views/hist.blade.php'));
    const bin = await page.evaluate(() => window.cic.bin());
    const item = bin.bin.find((b) => b.path === 'resources/views/hist.blade.php');
    expect(item, 'the deleted file should be in the bin').toBeTruthy();
    const r = await page.evaluate(({ from }) => window.cic.restore('/resources/views/hist.blade.php', from), { from: item.from });
    expect(r.ok).toBe(true);
    const back = await page.evaluate(() => window.cic.read('/resources/views/hist.blade.php'));
    expect(back.content).toBe('version one');
  });

  await test.step('.env never enters history', async () => {
    const r = await page.evaluate(() => window.cic.history('/.env'));
    expect(r.ok).toBe(false);
    expect(r.hint).toContain('secrets are not kept in history');
  });

  await test.step('folders, move, copy and search work on the real site', async () => {
    expect((await page.evaluate(() => window.cic.mkdir('/app/Shop'))).ok).toBe(true);
    expect((await page.evaluate(() => window.cic.write('/app/Shop/Cart.php', '<?php // the shopping cart', { expect: 'absent' }))).ok).toBe(true);
    expect((await page.evaluate(() => window.cic.cp('/app/Shop/Cart.php', '/app/Shop/Basket.php'))).ok).toBe(true);
    expect((await page.evaluate(() => window.cic.mv('/app/Shop/Basket.php', '/app/Shop/Trolley.php'))).ok).toBe(true);
    // Never overwrites.
    expect((await page.evaluate(() => window.cic.mv('/app/Shop/Trolley.php', '/app/Shop/Cart.php'))).ok).toBe(false);

    const hits = await page.evaluate(() => window.cic.search('the shopping cart'));
    expect(hits.ok).toBe(true);
    expect(hits.hits.map((x) => x.path).sort()).toEqual(['/app/Shop/Cart.php', '/app/Shop/Trolley.php']);
    // Search never reads the site's secrets.
    const secret = await page.evaluate(() => window.cic.search('DB_PASSWORD'));
    expect(secret.hits.filter((x) => x.path.includes('.env'))).toEqual([]);
  });

  await test.step('a binary upload round-trips byte for byte', async () => {
    const bytes = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff, 0x10, 0x80];
    const up = await page.evaluate(async (b) => {
      const file = new File([new Uint8Array(b)], 'logo.png', { type: 'image/png' });
      return window.cic.upload('/public/img', [file]);
    }, bytes);
    expect(up.ok).toBe(true);
    const got = await page.evaluate(async () => {
      const url = new URL(document.getElementById('cic-app').dataset.download, location.origin);
      url.searchParams.set('path', '/public/img/logo.png');
      const r = await fetch(url, { credentials: 'same-origin' });
      return { type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), csp: r.headers.get('content-security-policy'),
        bytes: [...new Uint8Array(await r.arrayBuffer())] };
    });
    expect(got.bytes).toEqual(bytes);
    expect(got.disp).toBe('attachment; filename="logo.png"');
    expect(got.csp).toBe('sandbox');
  });

  await test.step('images and PDFs open as previews, not as text', async () => {
    // A real 1x1 PNG, and the smallest valid one-page PDF.
    const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    const pdf = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
      + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';
    const up = await page.evaluate(async ({ png, pdf }) => {
      const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
      return window.cic.upload('/public/preview', [new File([bytes], 'dot.png', { type: 'image/png' }), new File([pdf], 'doc.pdf', { type: 'application/pdf' })]);
    }, { png, pdf });
    expect(up.ok).toBe(true);

    const img = await page.evaluate(() => window.cic.open('/public/preview/dot.png'));
    expect(img.preview).toBe('image');
    await expect(page.locator('#preview img.preview-image')).toHaveJSProperty('naturalWidth', 1);
    await expect(page.locator('#preview .preview-meta')).toContainText('1 x 1 px');

    const doc = await page.evaluate(() => window.cic.open('/public/preview/doc.pdf'));
    expect(doc.preview).toBe('pdf');
    await expect(page.locator('#preview canvas.preview-page')).toHaveCount(1, { timeout: 30_000 });
    await expect(page.locator('#preview .preview-meta')).toContainText('1 page');
  });

  await test.step("Laravel names: F12 on a view name opens the Blade file, and view('...') completes the site's views", async () => {
    expect((await page.evaluate(() => window.cic.write('/app/ViewProbe.php', "<?php\n\nreturn view('welcome');\n"))).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/app/ViewProbe.php'));

    // Click on the view name itself, then go to definition.
    await page.locator('.monaco-editor .view-line span', { hasText: "'welcome'" }).first().click();
    await page.keyboard.press('F12');
    await expect(page.locator('.tab.active')).toContainText('welcome.blade.php', { timeout: 15_000 });

    // Completion: a new line typed after the existing one.
    await page.evaluate(() => window.cic.open('/app/ViewProbe.php'));
    await page.locator('.monaco-editor .view-line span', { hasText: "'welcome'" }).first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type("view('");
    await expect(page.locator('.suggest-widget .monaco-list-row', { hasText: 'welcome' }).first()).toBeVisible({ timeout: 30_000 });
    await page.keyboard.press('Escape');

    expect((await page.evaluate(() => window.cic.rm('/app/ViewProbe.php'))).ok).toBe(true);
  });

  await test.step("config('...') keys and <x-...> components complete from the site", async () => {
    expect((await page.evaluate(() => window.cic.write('/resources/views/components/alert.blade.php', '<div>{{ $slot }}</div>\n'))).ok).toBe(true);
    expect((await page.evaluate(() => window.cic.write('/app/ConfigProbe.php', "<?php\n\n$x = 1;\n"))).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/app/ConfigProbe.php'));
    await page.locator('.monaco-editor .view-line span', { hasText: '$x' }).first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type("config('app.");
    await expect(page.locator('.suggest-widget .monaco-list-row', { hasText: 'app.name' }).first()).toBeVisible({ timeout: 30_000 });
    await page.keyboard.press('Escape');

    expect((await page.evaluate(() => window.cic.write('/resources/views/probe.blade.php', '<main>\n</main>\n'))).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/resources/views/probe.blade.php'));
    await page.locator('.monaco-editor .view-line span', { hasText: '<main' }).first().click();
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type('<x-');
    await expect(page.locator('.suggest-widget .monaco-list-row', { hasText: 'alert' }).first()).toBeVisible({ timeout: 30_000 });
    await page.keyboard.press('Escape');

    for (const f of ['/app/ConfigProbe.php', '/resources/views/probe.blade.php', '/resources/views/components/alert.blade.php']) {
      expect((await page.evaluate((p) => window.cic.rm(p), f)).ok).toBe(true);
    }
  });

  await test.step('Markdown previews render, and nothing in them runs', async () => {
    const md = '# Release notes\n\n- first\n- second\n\n<img src=x onerror="window.__pwned=9">\n<script>window.__pwned=8</script>\n\n[click](javascript:window.__pwned=7)\n';
    expect((await page.evaluate((m) => window.cic.write('/NOTES.md', m), md)).ok).toBe(true);
    await page.evaluate(() => window.cic.open('/NOTES.md'));
    await page.getByRole('button', { name: 'Preview' }).click();
    const article = page.locator('#preview article.markdown');
    await expect(article.locator('h1')).toHaveText('Release notes');
    await expect(article.locator('li')).toHaveCount(2);
    expect(await article.locator('script, img, [onerror]').count()).toBe(0);
    const href = await article.locator('a', { hasText: 'click' }).getAttribute('href').catch(() => null);
    expect(href ?? '').not.toContain('javascript:');
    expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
    await page.getByRole('button', { name: 'Edit' }).click();
    expect((await page.evaluate(() => window.cic.rm('/NOTES.md'))).ok).toBe(true);
  });

  await test.step('a folder delete needs confirm, and then its files are in the bin', async () => {
    const refused = await page.evaluate(() => window.cic.rmdir('/app/Shop'));
    expect(refused.ok).toBe(false);
    expect(refused.error).toBe('needs_confirm');
    expect((await page.evaluate(() => window.cic.ls('/app'))).listing.entries.some((e) => e.name === 'Shop')).toBe(true);

    expect((await page.evaluate(() => window.cic.rmdir('/app/Shop', { confirm: true }))).ok).toBe(true);
    const bin = await page.evaluate(() => window.cic.bin());
    expect(bin.bin.map((b) => b.path)).toEqual(expect.arrayContaining(['app/Shop/Cart.php', 'app/Shop/Trolley.php']));
  });
  await test.step("a dump's mysql client commands never run: no shell, no files, on any host", async () => {
    // Proved exploitable before the fix (2026-09-25): these lines ran a shell
    // as root inside the shared MySQL container during an import.
    const marker = `/tmp/cic-e2e-${stamp}`;
    const hostile = await page.evaluate(([m]) => window.cic.db.import(new Blob([
      `\\! touch ${m}-bang\n`, `system touch ${m}-system\n`, `tee ${m}-tee\n`, 'SELECT 1;\n',
    ], { type: 'application/sql' }), { confirm: true }), [marker]);
    expect(hostile.ok, 'a dump carrying client commands must be refused').toBe(false);
    for (const ip of fleetHostIps()) {
      const left = execFileSync('ssh', ['-n', '-o', 'BatchMode=yes', `root@${ip}`,
        `docker exec cic-mysql sh -c 'ls ${marker}-* 2>/dev/null | wc -l'`], { encoding: 'utf8' }).trim();
      expect(left, 'a file was created inside cic-mysql by an import').toBe('0');
    }
  });

  await test.step('the database imports a .sql file through window.cic and exports as .sql.gz', async () => {
    const refused = await page.evaluate(() => window.cic.db.import(new Blob(['SELECT 1;'], { type: 'application/sql' })));
    expect(refused.error).toBe('needs_confirm');

    const imported = await page.evaluate(() => window.cic.db.import(new Blob([
      'CREATE TABLE e2e_import (id INT PRIMARY KEY, name VARCHAR(40));\n',
      "INSERT INTO e2e_import VALUES (1, 'imported by the agent');\n",
    ], { type: 'application/sql' }), { confirm: true }));
    expect(imported.ok, imported.hint).toBe(true);
    const row = await page.evaluate(() => window.cic.db.query('SELECT name FROM e2e_import WHERE id = 1'));
    expect(row.result.rows).toEqual([['imported by the agent']]);

    const dump = await page.evaluate(async () => {
      const r = await fetch(document.getElementById('cic-app').dataset.dbExport, { credentials: 'same-origin' });
      const bytes = new Uint8Array(await r.arrayBuffer());
      const text = await new Response(new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))).text();
      return { status: r.status, disp: r.headers.get('content-disposition'), magic: [bytes[0], bytes[1]], text };
    });
    expect(dump.status).toBe(200);
    expect(dump.magic).toEqual([0x1f, 0x8b]);
    expect(dump.disp).toMatch(/^attachment; filename="site_.*\.sql\.gz"$/);
    expect(dump.text).toContain('CREATE TABLE `e2e_import`');
    expect(dump.text).toContain('imported by the agent');

    // The database as it was before the import was saved, and it does not
    // have the imported table.
    const before = await page.evaluate(async () => {
      const r = await fetch(document.getElementById('cic-app').dataset.dbExport + '?saved=before-import', { credentials: 'same-origin' });
      return new Response(r.body.pipeThrough(new DecompressionStream('gzip'))).text();
    });
    expect(before).not.toContain('e2e_import');
  });
  await test.step('cic.check reports a failing page with its error, then only NEW errors, and waits for a busy site', async () => {
    const marker = `e2e-boom-${Date.now()}`;
    const web = (await page.evaluate(() => cic.read('/routes/web.php'))).content;
    const boom = `\nRoute::get('/e2e-boom', fn () => throw new RuntimeException('${marker}'));\n`;
    expect((await page.evaluate(([c]) => cic.write('/routes/web.php', c), [web + boom])).ok).toBe(true);

    const bad = await page.evaluate(() => cic.check());
    expect(bad.ok).toBe(false);
    expect(bad.problems).toContain('500 /e2e-boom');
    expect(bad.errors.join('\n')).toContain(marker);

    // Fixed: the same log still holds that error, and it must not be reported again.
    expect((await page.evaluate(([c]) => cic.write('/routes/web.php', c), [web])).ok).toBe(true);
    // A command already running holds the site: the check waits for it instead of failing.
    const [, good] = await page.evaluate(() => Promise.all([cic.run('artisan', ['about']), cic.check()]));
    expect(good.problems).toEqual([]);
    expect(good.errors).toEqual([]);
    // A fresh Laravel app is clean code: the review has nothing to say.
    expect(good.review).toEqual([]);
    expect(good.ok).toBe(true);
  });

  await test.step('cic.check rejects code a senior Laravel developer would: a page in a route, a form without @csrf, env() in code', async () => {
    const web = (await page.evaluate(() => cic.read('/routes/web.php'))).content;
    // What an agent really did (2026-09-24): a whole page as a string in a route.
    const bad = {
      '/routes/web.php': `${web}\nRoute::get('/e2e-shortcut', fn () => '<!DOCTYPE html><html><body>hi</body></html>');\n`,
      '/resources/views/e2e-form.blade.php': '<form method="POST" action="/x"><input name="a"></form>',
      '/app/Support/E2eEnv.php': "<?php\n\nnamespace App\\Support;\n\nclass E2eEnv\n{\n    public static function key() { return env('APP_KEY'); }\n}\n",
    };
    expect((await page.evaluate(([f]) => cic.writeMany(f), [bad])).ok).toBe(true);
    const r = await page.evaluate(() => cic.check());
    expect(r.ok, JSON.stringify(r).slice(0, 1500)).toBe(false);
    const said = r.review.join('\n');
    expect(said, 'a review that could not finish is not a verdict').not.toContain('could not finish');
    expect(said).toMatch(/HTML inside PHP at routes\/web\.php:\d+/);
    expect(said).toContain('POST form without @csrf in resources/views/e2e-form.blade.php');
    expect(said).toMatch(/env\(\) outside config\/ at app\/Support\/E2eEnv\.php:\d+/);

    expect((await page.evaluate(([c]) => cic.write('/routes/web.php', c), [web])).ok).toBe(true);
    for (const f of ['/resources/views/e2e-form.blade.php', '/app/Support/E2eEnv.php']) {
      expect((await page.evaluate(([p]) => cic.rm(p), [f])).ok).toBe(true);
    }
    const clean = await page.evaluate(() => cic.check());
    expect(clean.review).toEqual([]);
    expect(clean.ok).toBe(true);
  });

  await test.step('reading as an agent: every page says what it is, and a sign-in that cannot work is said once', async () => {
    const v = await page.evaluate(() => cic.view('/routes/web.php'));
    expect(v.ok).toBe(true);
    expect(v.text.split('\n').at(-1)).toMatch(/^\[lines 1-\d+ of \d+ - (end of file|more: cic\.view\("\/routes\/web\.php", \{ from: \d+ \}\))\]$/);
    expect(v.text.length).toBeLessThan(1000);

    const parts = await page.evaluate(() => {
      const long = Array.from({ length: 120 }, (_, i) => `<div class="row" data-n="${i}">row ${i}</div>`).join('\n');
      return [cic.show(long), cic.show(long, 2)];
    });
    expect(parts[0]).toMatch(/\[part 1 of \d+ - more: cic\.show\(text, 2\)\]$/);
    expect(parts[0]).not.toContain('class="row"'); // shown with '＝', so a browser tool does not block it
    expect(parts[0].length).toBeLessThan(1000);
    expect(parts[1]).toMatch(/^\[?.*row/);

    const nobody = await page.evaluate(() => cic.check({ as: 999999 }));
    expect(nobody).toMatchObject({ ok: false, error: 'sign_in_failed', checked: 0 });
    expect(nobody.hint).toContain('session: true');
  });

  await test.step("the app's tests run in the site, on an in-memory database", async () => {
    const r = await page.evaluate(() => cic.run('artisan', ['test']));
    expect(r.ok, r.hint).toBe(true);
    expect(r.result.exitCode, r.result.text).toBe(0);
    expect(r.result.text).toMatch(/passed/i);

    // The worst case: phpunit.xml without its DB_URL line, and a DB_URL in .env
    // pointing at MySQL. Laravel lets a URL override driver, host and database,
    // so only the agent's forced environment keeps the run in memory.
    const before = await page.evaluate(async () => ({
      xml: (await cic.read('/phpunit.xml')).content, env: (await cic.read('/.env')).content,
    }));
    await page.evaluate(async ([xml, env]) => cic.writeMany({
      '/phpunit.xml': xml.replace(/\s*<env name="DB_URL"[^>]*\/>/, ''),
      '/.env': `${env.replace(/\n*$/, '\n')}DB_URL=mysql://root:nope@db.invalid:3306/live\n`,
      '/tests/Feature/NeverLiveTest.php': `<?php

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\DB;
use Tests\\TestCase;

class NeverLiveTest extends TestCase
{
    public function test_the_run_is_on_an_in_memory_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }
}
`,
    }), [before.xml, before.env]);
    const live = await page.evaluate(() => cic.run('artisan', ['test', '--filter=NeverLiveTest']));
    expect(live.result.exitCode, live.result.text).toBe(0);
    expect(live.result.text).toMatch(/1 passed/);
    await page.evaluate(async ([xml, env]) => {
      await cic.writeMany({ '/phpunit.xml': xml, '/.env': env });
      await cic.rm('/tests/Feature/NeverLiveTest.php');
    }, [before.xml, before.env]);
  });

  await test.step('Extensions lists the built-ins, and a switch really turns one off after a reload', async () => {
    await page.locator('#modeExt').click();
    const list = page.locator('#extList');
    for (const name of ['PHP IntelliSense', 'Emmet', 'Prettier', 'Material Icon Theme', 'PDF and image preview', 'Laravel Boost MCP']) {
      await expect(list.getByText(name, { exact: true })).toBeVisible();
    }
    await expect(list.locator('[data-ext="pdf"]')).toContainText('Built in');
    await expect(list.locator('[data-ext="pdf"]')).toContainText('Apache-2.0');

    const sw = list.locator('[data-ext="tailwind"] [role="switch"]');
    await expect(sw).toHaveAttribute('aria-checked', 'true');
    await sw.click();
    await expect(sw).toHaveAttribute('aria-checked', 'false');
    await expect(page.locator('#extReload')).toBeVisible();
    await page.locator('#btnExtReload').click();
    await page.waitForFunction(() => window.cic?.extensions);
    let state = await page.evaluate(() => Object.fromEntries(cic.extensions().extensions.map((e) => [e.id, e])));
    expect(state.tailwind).toMatchObject({ enabled: false, running: false });
    expect(state.php.running).toBe(true);

    // Back on, so nothing is left switched off in this browser.
    await page.locator('#modeExt').click();
    await page.locator('[data-ext="tailwind"] [role="switch"]').click();
    await page.locator('#btnExtReload').click();
    await page.waitForFunction(() => window.cic?.extensions);
    state = await page.evaluate(() => Object.fromEntries(cic.extensions().extensions.map((e) => [e.id, e])));
    expect(state.tailwind).toMatchObject({ enabled: true, running: true });
  });
});
