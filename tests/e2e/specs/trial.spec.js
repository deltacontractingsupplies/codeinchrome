import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { paidPlansOpen } from '../helpers/sales.js';
import { confirmSignup, setPlan, runTrialClock } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * The free trial, end to end on the real fleet: a new account's site is
 * paused when the trial ends (the container stopped, a plain 503 page served
 * in its place, the editor closed), comes back whole when the account pays,
 * and is deleted - files, database and DNS - once the grace period is over.
 * The clock is moved through tinker and trials:expire run by hand, exactly as
 * the scheduler runs it.
 */

const stamp = Date.now().toString(36);
const siteName = `tr-${stamp}`.slice(0, 40);
const email = `tr-${stamp}@codeinchrome.test`;
const password = `tr-${stamp}-${randomBytes(9).toString('hex')}-Qm8`;
const host = `${siteName}.codeinchrome.com`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
test.setTimeout(600_000);

test('a trial site is paused when the trial ends, resumed by paying, and deleted after the grace period', async ({ page, request }) => {
  // Paid plans switched off (App\\Billing\\Sales): there is nothing to buy, and no trial runs out.
  test.skip(!(await paidPlansOpen(request)), 'paid plans are switched off (CIC_PAID_PLANS_OPEN); SalesTest covers that state');
  let address;

  await test.step('sign up: the trial clock starts and the dashboard counts it down', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Trial Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    await expect(page.locator('[data-trial="running"]')).toContainText(/Free trial: .*left/);
  });

  await test.step('build a site and leave a mark in its database and files', async () => {
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    [address] = await waitForDns(host);
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    const web = (await page.evaluate(() => window.cic.read('/routes/web.php'))).content;
    expect((await page.evaluate((c) => window.cic.write('/routes/web.php', c), web + `
Route::get('/still-here', fn () => 'the same app');
`)).ok).toBe(true);
    await expect(async () => {
      const r = await httpsGet(host, '/still-here', address);
      expect(r.body).toBe('the same app');
    }).toPass({ timeout: 60_000, intervals: [3_000] });
  });

  await test.step('the trial ends: the site answers a plain paused page, and cannot be worked on', async () => {
    runTrialClock(email, { ended: true });
    const r = await httpsGet(host, '/still-here', address);
    expect(r.status).toBe(503);
    expect(r.body).toContain('This site is paused');
    // Nothing about the app, its owner or its stack in the page.
    expect(r.body).not.toContain('the same app');
    expect(r.body).not.toContain(email);

    await page.goto('/sites');
    await expect(page.locator('[data-trial="paused"]')).toContainText('will be deleted');
    await expect(page.getByRole('link', { name: 'Download database' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create' })).toHaveCount(0);

    await page.goto(`/sites/${siteName}/edit`);
    await expect(page).toHaveURL(/\/billing$/);
    const write = await page.evaluate(async (name) => {
      const token = document.querySelector('meta[name="csrf-token"]').content;
      const r = await fetch(`/sites/${name}/files`, { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ path: '/routes/web.php', content: '<?php' }) });
      return { status: r.status, body: await r.json() };
    }, siteName);
    expect(write.status).toBe(423);
    expect(write.body.error).toBe('site_paused');

    // Taking the work away still works.
    const dump = await page.request.get(`/sites/${siteName}/db/export`);
    expect(dump.status()).toBe(200);
    expect((await dump.body()).subarray(0, 2).toString('hex')).toBe('1f8b'); // gzip
  });

  await test.step('paying brings it back exactly as it was', async () => {
    setPlan(email, 'starter');
    runTrialClock(email);
    await expect(async () => {
      const r = await httpsGet(host, '/still-here', address);
      expect(r.status).toBe(200);
      expect(r.body).toBe('the same app');
    }).toPass({ timeout: 90_000, intervals: [3_000] });
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
  });

  await test.step('lapsed and past the grace period: the site, its data and its DNS are deleted', async () => {
    setPlan(email, 'free');
    runTrialClock(email); // pauses: the trial clock has long run out
    expect((await httpsGet(host, '/', address)).status).toBe(503);
    runTrialClock(email, { pastGrace: true });

    await page.goto('/sites');
    await expect(page.getByText(host)).toHaveCount(0);
    await expect(page.locator('[data-trial="paused"]')).toContainText('Upgrade to Starter to build again');
    await expect(async () => {
      const r = await httpsGet(host, '/', address, { timeoutMs: 5_000 });
      expect(r.status === 503 && r.body.includes('paused')).toBe(false);
      expect(r.body ?? '').not.toContain('the same app');
    }).toPass({ timeout: 30_000, intervals: [3_000] });
  });
});
