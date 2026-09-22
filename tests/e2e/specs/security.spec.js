import { test, expect } from '@playwright/test';

/**
 * Boundaries that must hold in the control plane itself. Each of these is a
 * way a signed-in customer could reach something that is not theirs.
 */

const stamp = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

async function register(page, id) {
  const account = {
    email: `sec-${id}@codeinchrome.test`,
    password: `sec-${id}-${Math.random().toString(36).slice(2)}-Xr4`,
  };
  await page.goto('/register');
  await page.getByLabel('Name').fill(`Sec ${id}`);
  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Password', { exact: true }).fill(account.password);
  await page.getByLabel('Confirm password').fill(account.password);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page).toHaveURL(/\/sites$/);

  return account;
}

test('the dashboard is not reachable signed out', async ({ page }) => {
  await page.goto('/sites');
  await expect(page).toHaveURL(/\/login$/);
});

test('registration refuses a password known to be breached', async ({ page }) => {
  await page.goto('/register');
  await page.getByLabel('Name').fill('Weak');
  await page.getByLabel('Email').fill(`weak-${stamp()}@codeinchrome.test`);
  await page.getByLabel('Password', { exact: true }).fill('password123');
  await page.getByLabel('Confirm password').fill('password123');
  await page.getByRole('button', { name: 'Create account' }).click();

  await expect(page).toHaveURL(/\/register$/);
  await expect(page.locator('text=/password/i').first()).toBeVisible();
});

test('reserved names are refused', async ({ page }) => {
  await register(page, stamp());

  for (const reserved of ['www', 'admin', 'api']) {
    await page.getByPlaceholder('my-shop').fill(reserved);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/reserved/)).toBeVisible();
  }

  await expect(page.getByText('No sites yet')).toBeVisible();
});

test('the webhook endpoint rejects an unsigned payload', async ({ request }) => {
  const response = await request.post('/webhooks/lemonsqueezy', {
    headers: { 'X-Event-Name': 'subscription_created', 'Content-Type': 'application/json' },
    data: { meta: { custom_data: { user_id: '1' } }, data: { id: 'forged', attributes: { status: 'active' } } },
    failOnStatusCode: false,
  });

  // 401 signature rejected, or 503 when no secret is configured. Never 200.
  expect([401, 503]).toContain(response.status());
});

test('a forged signature is rejected', async ({ request }) => {
  const response = await request.post('/webhooks/lemonsqueezy', {
    headers: {
      'X-Event-Name': 'subscription_created',
      'X-Signature': 'a'.repeat(64),
      'Content-Type': 'application/json',
    },
    data: { data: { id: 'forged' } },
    failOnStatusCode: false,
  });

  expect([401, 503]).toContain(response.status());
});
