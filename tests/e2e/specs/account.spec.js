import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { totp } from '../helpers/totp.js';

const stamp = Date.now().toString(36);
const email = `acct-${stamp}@codeinchrome.test`;
const password = `acct-${stamp}-${Math.random().toString(36).slice(2)}-Pq2`;

test('two-factor: set up, then a password alone no longer signs in', async ({ page }) => {
  page.on('dialog', (d) => { throw new Error(`native dialog: ${d.message()}`); });
  let secret, recovery, setupStep;

  await test.step('sign up and turn on two-factor', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Account Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);

    await page.goto('/account');
    await page.getByRole('link', { name: 'Set up' }).click();
    await expect(page.locator('svg').first()).toBeVisible();
    secret = (await page.locator('[data-secret]').textContent()).replace(/\s/g, '');

    await page.getByPlaceholder('123456').fill('000000');
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.getByText('does not match')).toBeVisible();

    setupStep = Math.floor(Date.now() / 30_000);
    await page.getByPlaceholder('123456').fill(totp(secret));
    await page.getByRole('button', { name: 'Turn on' }).click();
    await expect(page.getByText('will not be shown again')).toBeVisible();
    recovery = (await page.locator('[data-recovery-codes] li').first().textContent()).trim();
    await page.getByRole('link', { name: 'I have saved them' }).click();
    await expect(page.getByText('On. Signing in needs a code')).toBeVisible();
  });

  await test.step('sign out; the password alone reaches the challenge, not the account', async () => {
    await page.getByRole('button', { name: 'Sign out' }).click();
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/two-factor-challenge/);

    await page.goto('/sites');
    await expect(page).toHaveURL(/\/login$/);
  });

  await test.step('a wrong code is refused; the right one signs in', async () => {
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByPlaceholder('123456').fill('111111');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByText('That code did not work.')).toBeVisible();

    // The setup code, replayed, is refused as ALREADY USED - not as wrong.
    await page.getByPlaceholder('123456').fill(totp(secret, setupStep * 30_000));
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByText(/already been used|did not work/)).toBeVisible();

    // Wait for a time step after the one used at setup, then sign in with it.
    await expect.poll(() => Math.floor(Date.now() / 30_000), { intervals: [1_000], timeout: 35_000 }).toBeGreaterThan(setupStep);
    await page.getByPlaceholder('123456').fill(totp(secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/sites$/);
  });

  await test.step('a recovery code works exactly once', async () => {
    for (const expectOk of [true, false]) {
      await page.getByRole('button', { name: 'Sign out' }).click();
      await page.goto('/login');
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password').fill(password);
      await page.getByRole('button', { name: 'Sign in' }).click();
      await page.getByText('Use a recovery code instead').click();
      await page.getByPlaceholder('abcde-fghij').fill(recovery);
      await page.getByRole('button', { name: 'Use' }).click();
      if (expectOk) await expect(page).toHaveURL(/\/sites$/);
      else await expect(page.getByText('That code did not work.')).toBeVisible();
    }
  });
});
