import { test, expect } from '@playwright/test';
import { waitForDns } from '../helpers/dns.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * The whole product in one test: sign up, create a site, and have a real
 * Laravel application answering on HTTPS with a real certificate - with the
 * things that must never be served proven unreachable while it is live.
 */

const stamp = Date.now().toString(36);
const account = {
  name: 'E2E Runner',
  email: `e2e-${stamp}@codeinchrome.test`,
  // Long and random so it cannot collide with a breach corpus, which
  // registration checks against.
  password: `e2e-${stamp}-${Math.random().toString(36).slice(2)}-Zq7`,
};
const siteName = `e2e-${stamp}`.slice(0, 40);
const siteUrl = `https://${siteName}.codeinchrome.com`;

test.describe.configure({ mode: 'serial' });

// Whatever happens above, nothing is left on the fleet. This used to be a
// claim in the config with no implementation behind it, and failed runs
// abandoned containers and DNS records on two hosts.
test.afterAll(() => destroySite(siteName));

test('a visitor can sign up, provision a site, and see it live', async ({ page, request }) => {
  await test.step('the landing page is there', async () => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Laravel hosting');
    await expect(page.getByText('Starter')).toBeVisible();
  });

  await test.step('sign up', async () => {
    await page.getByRole('link', { name: 'Start free' }).first().click();
    await page.getByLabel('Name').fill(account.name);
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password').fill(account.password);
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page).toHaveURL(/\/sites$/);
    await expect(page.getByText('No sites yet')).toBeVisible();
  });

  await test.step('create a site', async () => {
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();

    // The message must NOT claim the site is live: the certificate is issued
    // on the first request, so at this moment TLS is not yet proven.
    await expect(page.getByText(/is building/)).toBeVisible();
    await expect(page.getByRole('link', { name: `${siteName}.codeinchrome.com` })).toBeVisible();
  });

  await test.step('the name resolves before anything asks the OS for it', async () => {
    // MUST come before any request below. See helpers/dns.js: one lookup made
    // too early negatively caches in the OS resolver for ~30 minutes and makes
    // a perfectly healthy site look dead for the rest of the run.
    const addresses = await waitForDns(`${siteName}.codeinchrome.com`);
    expect(addresses.length).toBeGreaterThan(0);
  });

  await test.step('the site actually serves Laravel over HTTPS', async () => {
    // Polled rather than slept: the certificate is issued on first contact and
    // how long that takes is not ours to predict.
    await expect.poll(async () => {
      try {
        const response = await request.get(siteUrl, { timeout: 20_000, ignoreHTTPSErrors: false });
        return response.status();
      } catch {
        return 0; // TLS not ready yet
      }
    }, {
      message: `${siteUrl} never answered 200`,
      intervals: [5_000],
      timeout: 150_000,
    }).toBe(200);

    const response = await request.get(siteUrl);
    expect(await response.text()).toContain('Laravel');
  });

  await test.step('nothing that must stay private is reachable', async () => {
    for (const path of ['/.env', '/composer.lock', '/artisan', '/storage/logs/laravel.log', '/vendor/autoload.php', '/.git/config']) {
      const response = await request.get(`${siteUrl}${path}`, { failOnStatusCode: false });
      expect(response.status(), `${path} must not be served`).not.toBe(200);

      const body = await response.text().catch(() => '');
      expect(body, `${path} leaked an application key`).not.toContain('APP_KEY=base64:');
    }
  });

  await test.step('the site can be deleted, and really goes', async () => {
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete' }).click();

    await expect(page.getByText(/was removed/)).toBeVisible();
    await expect(page.getByText('No sites yet')).toBeVisible();

    // Observed, not assumed: the domain must stop answering.
    await expect.poll(async () => {
      try {
        const response = await request.get(siteUrl, { timeout: 10_000 });
        return response.status();
      } catch {
        return 0;
      }
    }, { message: 'the deleted site still answers', intervals: [3_000], timeout: 60_000 }).not.toBe(200);
  });
});
