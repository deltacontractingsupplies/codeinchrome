import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * The editor, driven the two ways it is meant to be: by a person clicking and
 * typing, and by an agent calling window.cic. Against a real site.
 */

const stamp = Date.now().toString(36);
const siteName = `ed-${stamp}`.slice(0, 40);
const password = `ed-${stamp}-${Math.random().toString(36).slice(2)}-Wq3`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));

// Put the caret at the very end of the editor. Keyboard shortcuts for this
// differ by platform (Cmd+Down on macOS, Ctrl+End elsewhere); the result is
// what matters, not the key.
const toEnd = async (page) => {
  await page.locator('.monaco-editor .view-lines').click();
  await page.keyboard.press('ControlOrMeta+End');
};
// The editor as the person sees it (Monaco), unsaved edits included.
const shown = (page) => page.evaluate(() => window.cic.buffer());
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

  await test.step('the site was given its own MySQL database', async () => {
    const env = await page.evaluate(() => window.cic.read('/.env'));
    expect(env.ok).toBe(true);
    expect(env.content).toMatch(/^DB_CONNECTION=mysql$/m);
    expect(env.content).toMatch(/^DB_HOST=cic-db$/m);
    expect(env.content).toMatch(new RegExp(`^DB_DATABASE=site_${siteName.replace(/-/g, '_')}$`, 'm'));
    expect(env.content).toMatch(/^DB_PASSWORD=[0-9a-f]{64}$/m);
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
    await expect(page.locator('#hl')).toContainText('onerror');

    expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
    expect(await page.locator('#hl img, #hl script, #hl svg').count()).toBe(0);
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

  await test.step('composer require works inside the plan memory limit', async () => {
    const res = await page.evaluate(() => window.cic.run('composer', ['require', 'spatie/array-to-xml']));
    expect(res.ok, res.result?.output?.slice(-2000)).toBe(true);
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

  await test.step('a folder delete needs confirm, and then its files are in the bin', async () => {
    const refused = await page.evaluate(() => window.cic.rmdir('/app/Shop'));
    expect(refused.ok).toBe(false);
    expect(refused.error).toBe('needs_confirm');
    expect((await page.evaluate(() => window.cic.ls('/app'))).listing.entries.some((e) => e.name === 'Shop')).toBe(true);

    expect((await page.evaluate(() => window.cic.rmdir('/app/Shop', { confirm: true }))).ok).toBe(true);
    const bin = await page.evaluate(() => window.cic.bin());
    expect(bin.bin.map((b) => b.path)).toEqual(expect.arrayContaining(['app/Shop/Cart.php', 'app/Shop/Trolley.php']));
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
});
