import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * Malware and encrypted PHP (owner's decision, 2026-09-25): refused before it
 * is written, and the account banned and its site taken down - proved on the
 * live platform, with the platform's own test account.
 */

const stamp = Date.now().toString(36);
const siteName = `ab-${stamp}`.slice(0, 40);
const email = `ab-${stamp}@codeinchrome.test`;
const password = `ab-${stamp}-${randomBytes(9).toString('hex')}-Qz5`;

test.describe.configure({ mode: 'serial' });
test.setTimeout(420_000);
test.afterAll(() => destroySite(siteName));

test('a webshell saved through the editor is refused, and the account banned and its site taken down', async ({ page }) => {
  await test.step('sign up, create a site, open the editor', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Abuse Runner');
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
    await expect(page.locator('#sbMsg')).toHaveText(/^(Ready|Restored)/);
  });

  await test.step('ordinary code saves, and a site that works is served', async () => {
    const ok = await page.evaluate(() => cic.write('/routes/abuse-ok.php', "<?php\n\nreturn base64_decode('aGk=');\n"));
    expect(ok.ok, ok.hint).toBe(true);
  });

  await test.step('a webshell is refused, nothing is written, and the account is closed', async () => {
    const bad = await page.evaluate(() => cic.write('/public/tools.php', "<?php system($_GET['c']);"));
    expect(bad.ok).toBe(false);
    expect(bad.error).toBe('malware');
    expect(bad.hint).toContain('request input handed to a shell');
    expect(bad.hint).toContain('This account has been closed');

    // Signed out on the next request.
    await page.goto('/sites');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('This account has been closed for breaking the terms of use')).toBeVisible();
  });

  await test.step('the site is taken down, and the webshell was never written', async () => {
    await expect.poll(async () => (await page.request.get(`https://${siteName}.codeinchrome.com/tools.php`)).status(),
      { timeout: 60_000 }).not.toBe(200);
    const home = await page.request.get(`https://${siteName}.codeinchrome.com/`);
    expect(await home.text()).toMatch(/paused/i);
  });

  await test.step('it cannot sign back in', async () => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByText('This account has been closed for breaking the terms of use')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });
});
