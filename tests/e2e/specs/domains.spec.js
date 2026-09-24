import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';

import { fleetHostIps } from '../helpers/control-host.js';
import { destroySite } from '../helpers/cleanup.js';
import { setPlan, dnsCreate, dnsDeleteUnder, confirmSignup } from '../helpers/fixtures.js';

/**
 * A real custom domain, end to end: claimed in the dashboard, proved with a
 * TXT record, pointed with an A record, verified, served over HTTPS with its
 * own certificate - then removed and no longer served.
 *
 * The domain lives under cdtest.codeinchrome.com only because that is a zone
 * the test can write to. The control plane treats it exactly like any
 * customer's domain: it is two labels below the platform zone, so it is not a
 * platform address, and it is only attached after the TXT proof.
 */

const stamp = Date.now().toString(36);
const siteName = `dm-${stamp}`;
const custom = `${siteName}.cdtest.codeinchrome.com`;
const email = `dm-${stamp}@codeinchrome.test`;
const password = `dm-${stamp}-${randomBytes(9).toString('hex')}-Tk8`;

test.describe.configure({ mode: 'serial' });
test.afterAll(async () => {
  destroySite(siteName);
  await dnsDeleteUnder(custom);
});

test('a customer can prove, attach, serve and remove their own domain', async ({ page }) => {
  page.on('dialog', (d) => { throw new Error(`native dialog: ${d.message()}`); });
  let hostIp;

  await test.step('sign up on a paid plan and create a site', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Domain Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    setPlan(email, 'starter');

    await page.goto('/sites');
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    // Proxied through Cloudflare: this resolves to Cloudflare, not the host.
    await waitForDns(`${siteName}.codeinchrome.com`);
  });

  let txtName, txtValue;
  await test.step('claim the domain and get the records to publish', async () => {
    await page.getByRole('link', { name: 'Domains' }).click();
    await page.getByPlaceholder('shop.example.com').fill(custom);
    await page.getByRole('button', { name: 'Add domain' }).click();

    const row = page.locator(`[data-domain="${custom}"]`);
    await expect(row).toContainText('waiting for DNS');
    txtName = (await row.locator('[data-txt-name]').textContent()).trim();
    txtValue = (await row.locator('[data-txt-value]').textContent()).trim();
    expect(txtName).toBe(`_codeinchrome-challenge.${custom}`);
    // A customer's own domain points at the HOST (it is not in our zone, so
    // not behind our proxy); the page must name one of the fleet's hosts.
    hostIp = (await row.locator('[data-a-value]').textContent()).trim();
    expect(fleetHostIps()).toContain(hostIp);
  });

  await test.step('verifying before publishing anything is refused', async () => {
    await page.locator(`[data-domain="${custom}"]`).getByRole('button', { name: 'Verify' }).click();
    await expect(page.getByText(/No TXT record/).first()).toBeVisible();
  });

  await test.step('publish the records, verify, and it attaches', async () => {
    await dnsCreate('TXT', txtName, txtValue);
    await dnsCreate('A', custom, hostIp);

    // Resolvers pick records up at different speeds; retry the button the way
    // a person would, until verification passes.
    await expect.poll(async () => {
      await page.locator(`[data-domain="${custom}"]`).getByRole('button', { name: 'Verify' }).click();
      return page.locator(`[data-domain="${custom}"]`).textContent();
    }, { intervals: [10_000], timeout: 240_000 }).toContain('attached');
  });

  await test.step('the site is served on the custom domain, with its own valid certificate', async () => {
    await expect.poll(async () => (await httpsGet(custom, '/', hostIp)).status, {
      intervals: [5_000], timeout: 150_000,
    }).toBe(200);
    expect((await httpsGet(custom, '/', hostIp)).body).toContain('Laravel');
  });

  await test.step('removing it stops it being served', async () => {
    const row = page.locator(`[data-domain="${custom}"]`);
    await row.getByText('Remove', { exact: true }).click();
    await row.getByRole('button', { name: 'Remove domain' }).click();
    await expect(page.getByText(`${custom} was removed.`)).toBeVisible();

    // The certificate may still be cached, but the vhost no longer names the
    // domain: it must stop answering 200 with the site.
    await expect.poll(async () => {
      const r = await httpsGet(custom, '/', hostIp);
      return r.status === 200 && r.body.includes('Laravel');
    }, { intervals: [3_000], timeout: 60_000 }).toBe(false);
  });
});
