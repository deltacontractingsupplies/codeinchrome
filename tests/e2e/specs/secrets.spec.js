import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * A tenant's secrets never reach a visitor - not even when the tenant's own
 * site tries to give them away. Each attack is made from inside the site, the
 * way a careless owner or an AI agent "fixing a 403" would:
 *
 *   - an .htaccess that grants /.env again: the edge (Caddy) still says 404
 *   - APP_DEBUG=true in .env and an exception: a plain error page, no trace
 *   - moving .env into public/ under another name: refused by the editor
 *   - dumps, logs, dotfiles and project files: 404 at the edge
 */

const stamp = Date.now().toString(36);
const siteName = `sx-${stamp}`.slice(0, 40);
const email = `sx-${stamp}@codeinchrome.test`;
const password = `sx-${stamp}-${Math.random().toString(36).slice(2)}-Wq9`;
const host = `${siteName}.codeinchrome.com`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
test.setTimeout(600_000);

test('secrets stay private even when the site itself tries to publish them', async ({ page }) => {
  let address;

  await test.step('a site whose .env holds a known secret', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Secrets Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    [address] = await waitForDns(host);
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
  });

  await test.step('the site grants /.env in its .htaccess, turns debug on and throws', async () => {
    const r = await page.evaluate(async () => {
      const env = (await window.cic.read('/.env')).content;
      const htaccess = (await window.cic.read('/public/.htaccess')).content;
      return window.cic.writeMany({
        '/.env': `${env.replace(/^APP_DEBUG=.*$/m, 'APP_DEBUG=true')}\nLEAK_CANARY=canary-4f1d9\n`,
        '/public/.htaccess': `<FilesMatch "^\\.env">\n  Require all granted\n</FilesMatch>\nOptions +Indexes\n${htaccess}`,
        '/public/backup.sql': 'CREATE TABLE secrets (x text);',
        '/public/debug.log': 'canary-4f1d9',
        // Under .well-known too: the edge once exempted that whole folder.
        '/public/.well-known/dump.sql': 'canary-4f1d9',
        '/public/.well-known/security.txt': 'Contact: mailto:security@example.org',
        '/routes/web.php': String.raw`<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => 'up');
Route::get('/boom', fn () => throw new RuntimeException('canary-4f1d9 in the exception'));
`,
      }, { message: 'try to leak' });
    });
    expect(r.ok, r.hint).toBe(true);
    expect(r.syntaxErrors).toBeUndefined();
    await expect(async () => {
      expect((await httpsGet(host, '/', address)).body).toBe('up');
    }).toPass({ timeout: 30_000, intervals: [2_000] });
  });

  await test.step('the edge refuses every secret-shaped path, whatever the site says', async () => {
    for (const path of ['/.env', '/.ENV', '/%2eenv', '/.env.backup', '/.htaccess', '/.git/config', '/backup.sql', '/debug.log',
      '/composer.json', '/composer.lock', '/artisan', '/.well-known/dump.sql', '/.well-known/../.env']) {
      const r = await httpsGet(host, path, address);
      expect(r.status, path).toBe(404);
      expect(r.body, path).not.toContain('canary-4f1d9');
      expect(r.body, path).not.toContain('APP_KEY');
    }
  });

  await test.step('APP_DEBUG=true in .env does not turn on debug pages', async () => {
    const r = await httpsGet(host, '/boom', address);
    expect(r.status).toBe(500);
    expect(r.body).not.toContain('canary-4f1d9');
    expect(r.body).not.toContain('RuntimeException');
    expect(r.body).not.toContain('/var/www/html');
  });

  await test.step('.env cannot be moved or copied into public/ under any name', async () => {
    const moved = await page.evaluate(() => window.cic.mv('/.env', '/public/settings.txt'));
    expect(moved.ok).toBe(false);
    const copied = await page.evaluate(() => window.cic.cp('/.env', '/public/settings.txt'));
    expect(copied.ok).toBe(false);
    expect((await httpsGet(host, '/settings.txt', address)).status).toBe(404);
  });

  await test.step('a normal page and public file are still served, .well-known too', async () => {
    const wk = await httpsGet(host, '/.well-known/security.txt', address);
    expect(wk.status).toBe(200);
    expect((await page.evaluate(() => window.cic.write('/public/hello.txt', 'hello', { expect: 'absent' }))).ok).toBe(true);
    const r = await httpsGet(host, '/hello.txt', address);
    expect(r.status).toBe(200);
    expect(r.body).toBe('hello');
  });
});
