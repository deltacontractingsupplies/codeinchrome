import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * A customer's backups, end to end on the real fleet: back up, change the
 * site, restore - files AND database come back - and the restore itself left
 * a backup of the state it replaced.
 */

const stamp = Date.now().toString(36);
const siteName = `bk-${stamp}`.slice(0, 40);
const email = `bk-${stamp}@codeinchrome.test`;
const password = `bk-${stamp}-${Math.random().toString(36).slice(2)}-Wq3`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
// A backup goes to the backup server and a restore stops, replaces and
// restarts the site: minutes, not seconds.
test.setTimeout(900_000);

// The page reloads itself while an operation runs; wait for it to settle.
async function waitForOperation(page, kind) {
  await expect(async () => {
    await page.reload();
    await expect(page.getByText(new RegExp(`The last ${kind} finished|The last ${kind} did not finish`))).toBeVisible({ timeout: 1000 });
  }).toPass({ timeout: 600_000, intervals: [5_000] });
  await expect(page.getByText(`The last ${kind} did not finish`)).toHaveCount(0);
}

test('back up, change the site, restore it, and the restore can itself be undone', async ({ page }) => {
  page.on('dialog', (d) => { throw new Error(`native ${d.type()} dialog: ${d.message()}`); });

  await test.step('sign up and create a site', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Backup Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    await waitForDns(`${siteName}.codeinchrome.com`);
    await page.getByRole('link', { name: 'Edit code' }).click();
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
  });

  await test.step('the site has a file and a row worth keeping', async () => {
    expect((await page.evaluate(() => window.cic.write('/public/kept.txt', 'the original'))).ok).toBe(true);
    const q = await page.evaluate(() => window.cic.db.query(
      "CREATE TABLE kept (v VARCHAR(40)); ", { write: true }));
    expect(q.ok, q.hint).toBe(true);
    expect((await page.evaluate(() => window.cic.db.query("INSERT INTO kept VALUES ('the original row')", { write: true }))).ok).toBe(true);
  });

  let taken;
  await test.step('back up now', async () => {
    await page.goto(`/sites/${siteName}/backups`);
    await page.getByRole('button', { name: 'Back up now' }).click();
    await waitForOperation(page, 'backup');
    taken = (await page.locator('tbody tr td.font-mono').first().textContent()).trim();
    expect(taken).toMatch(/^[0-9a-f]{8}$/);
  });

  await test.step('the site changes after the backup', async () => {
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    expect((await page.evaluate(() => window.cic.write('/public/kept.txt', 'changed later'))).ok).toBe(true);
    expect((await page.evaluate(() => window.cic.db.query("UPDATE kept SET v = 'changed later'", { write: true }))).ok).toBe(true);
    const [address] = await waitForDns(`${siteName}.codeinchrome.com`);
    expect((await httpsGet(`${siteName}.codeinchrome.com`, '/kept.txt', address)).body).toBe('changed later');
  });

  await test.step('restore needs the box ticked, then brings files and database back', async () => {
    await page.goto(`/sites/${siteName}/backups`);
    const row = page.locator('tbody tr', { hasText: taken });
    await row.getByText('Restore this backup').click();
    await row.getByLabel(/Replace the site's files and database/).check();
    await row.getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByText(`Restoring backup ${taken}`)).toBeVisible();
    await waitForOperation(page, 'restore');
    await expect(page.getByText(new RegExp(`restored backup ${taken}\\. The site as it was before is backup [0-9a-f]{8}`))).toBeVisible();

    await expect(async () => {
      const [address] = await waitForDns(`${siteName}.codeinchrome.com`);
      expect((await httpsGet(`${siteName}.codeinchrome.com`, '/kept.txt', address)).body).toBe('the original');
    }).toPass({ timeout: 60_000 });
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    const row2 = await page.evaluate(() => window.cic.db.query('SELECT v FROM kept'));
    expect(row2.result.rows).toEqual([['the original row']]);
  });

  await test.step('the restore is a version in history, so the editor can show what it replaced', async () => {
    const h = await page.evaluate(() => window.cic.history('/public/kept.txt'));
    expect(h.ok, h.hint).toBe(true);
    expect(h.versions.map((v) => v.message).join('\n')).toContain(`restore site from backup ${taken}`);
  });
});
