import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns, waitForDnsGone } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
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

test('a visitor can sign up, provision a site, and see it live', async ({ page }) => {
  await test.step('the landing page is there', async () => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Laravel app');
    // The product itself is on the page: the editor and the agent at work.
    await expect(page.getByRole('figure', { name: /The editor in the browser/ })).toBeVisible();
    await expect(page.getByText('Starter', { exact: true }).first()).toBeVisible();
  });

  await test.step('sign up', async () => {
    await page.getByRole('link', { name: 'Start free' }).first().click();
    await page.getByLabel('Name').fill(account.name);
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password').fill(account.password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, account.email);
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

  let address;

  await test.step('the record is published by the zone', async () => {
    // Asked of the authoritative nameservers - see helpers/dns.js for why no
    // recursive resolver, and certainly not this machine's, gets a vote.
    const addresses = await waitForDns(`${siteName}.codeinchrome.com`);
    expect(addresses.length).toBeGreaterThan(0);
    address = addresses[0];
  });

  const get = (path) => httpsGet(`${siteName}.codeinchrome.com`, path, address);

  await test.step('the site actually serves Laravel over HTTPS', async () => {
    // Polled rather than slept: the certificate is issued on first contact and
    // how long that takes is not ours to predict. Certificate verification is
    // fully on - a self-signed or wrong-name certificate fails here.
    let last;
    await expect.poll(async () => {
      last = await get('/');
      return last.status;
    }, {
      message: `${siteUrl} never answered 200 (last: ${JSON.stringify(last?.error ?? last?.status)})`,
      intervals: [5_000],
      timeout: 150_000,
    }).toBe(200);

    expect(last.body).toContain('Laravel');
  });

  await test.step('nothing that must stay private is reachable', async () => {
    for (const path of ['/.env', '/composer.lock', '/artisan', '/storage/logs/laravel.log', '/vendor/autoload.php', '/.git/config']) {
      const response = await get(path);

      // A connection failure is NOT a pass here. The site answered 200 a
      // moment ago, so status 0 would mean the probe proved nothing.
      expect(response.status, `${path}: no answer at all, so this probe proved nothing`).not.toBe(0);
      expect(response.status, `${path} must not be served`).not.toBe(200);
      expect(response.body, `${path} leaked an application key`).not.toContain('APP_KEY=base64:');
    }
  });

  await test.step('the site can be deleted, and really goes', async () => {
    // Two in-page steps, no native dialog: a confirm() would freeze the page
    // for a browser-driving agent. If one appears, that is itself a failure.
    page.on('dialog', (dialog) => {
      throw new Error(`a native ${dialog.type()} dialog appeared: "${dialog.message()}"`);
    });
    await page.getByText('Delete', { exact: true }).click();
    await page.getByRole('button', { name: 'Delete permanently' }).click();

    await expect(page.getByText(/was removed/)).toBeVisible();
    await expect(page.getByText('No sites yet')).toBeVisible();

    // Two separate facts, each observed rather than inferred.
    //
    // The HOST stops serving it. Asked of the old address directly: the
    // previous version of this check went through the OS resolver, so a name
    // that merely failed to resolve came back as status 0, which is "not 200",
    // and the assertion passed without proving the host had stopped serving
    // anything at all.
    await expect.poll(async () => (await get('/')).status, {
      message: 'the host still serves the deleted site',
      intervals: [3_000],
      timeout: 60_000,
    }).not.toBe(200);

    // And the zone stops publishing it.
    await waitForDnsGone(`${siteName}.codeinchrome.com`);
  });
});
