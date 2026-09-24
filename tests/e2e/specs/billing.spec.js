import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { paidPlansOpen } from '../helpers/sales.js';
import { confirmSignup, billingOf, lsSubscription, lsCancel, lsResume, lsCancelAllFor } from '../helpers/fixtures.js';

/**
 * A new customer buys a plan, end to end, against the REAL Lemon Squeezy
 * checkout in TEST MODE: sign up, choose Starter, pay with Lemon Squeezy's
 * published test card, and watch the signed webhook move the account to Starter.
 * Then cancel and resume through the Lemon Squeezy API and check the webhook
 * keeps the account in step each time.
 *
 * It refuses to pay unless the checkout page itself says test mode is on, so
 * pointed at a live store it stops before any card field is touched.
 *
 * Needs the webhook to reach the control plane, so it runs against the
 * deployed one only (npm run test:prod).
 */

const stamp = Date.now().toString(36);
const email = `bill-${stamp}@codeinchrome.test`;
const password = `bill-${stamp}-${randomBytes(9).toString('hex')}-Pq2`;

// Lemon Squeezy's documented test card; accepted only in test mode.
const TEST_CARD = { number: '4242424242424242', expiry: '12 / 34', cvc: '123' };

test.skip(!/^https:\/\//.test(process.env.CIC_BASE_URL || ''), 'billing needs the deployed control plane: the webhook must reach it');

/** Reload the billing page until the webhook has done its work. */
async function waitForBilling(page, predicate, what) {
  for (let i = 0; i < 30; i++) {
    const state = billingOf(email);
    if (predicate(state)) return state;
    await page.waitForTimeout(3000);
  }
  throw new Error(`the webhook never delivered: ${what} (last state ${JSON.stringify(billingOf(email))})`);
}

test('a new customer pays for Starter, and cancel/resume stay in step', async ({ page, request }) => {
  // Paid plans switched off (App\\Billing\\Sales): there is nothing to buy, and no trial runs out.
  test.skip(!(await paidPlansOpen(request)), 'paid plans are switched off (CIC_PAID_PLANS_OPEN); SalesTest covers that state');
  test.setTimeout(300_000);
  page.on('dialog', (d) => { throw new Error(`native dialog: ${d.message()}`); });
  let subscriptionId;

  try {
  await test.step('sign up; the account starts on the free trial', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Billing Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);

    await page.goto('/billing');
    await expect(page.getByText('You are on the Free trial plan')).toBeVisible();
  });

  await test.step('choose Starter: the checkout is ours, in test mode, at the advertised price', async () => {
    await page.getByRole('button', { name: 'Choose Starter' }).click();
    await page.waitForURL(/^https:\/\/codeinchrome\.lemonsqueezy\.com\/checkout/);
    await expect(page.getByText('Test mode is currently enabled')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Starter' })).toBeVisible();
    await expect(page.getByText('$12.00 billed every month')).toBeVisible();
    // Described from our plan config (Checkout::description): sites and storage, never CPU or memory.
    await expect(page.getByText(/Laravel hosting with an AI agent in the editor: 3 sites, 5 GB of storage each \(15 GB in all\)/)).toBeVisible();
    await expect(page.getByText(/\bCPU\b|MB RAM/)).toHaveCount(0);
  });

  await test.step('pay with the test card', async () => {
    const card = page.frameLocator('iframe[src*="elements-inner-payment"]');
    await card.locator('[name="number"]').fill(TEST_CARD.number);
    await card.locator('[name="expiry"]').fill(TEST_CARD.expiry);
    await card.locator('[name="cvc"]').fill(TEST_CARD.cvc);

    await page.getByLabel('Cardholder name').fill('Billing Runner');
    await page.getByRole('textbox', { name: 'Address line 1' }).fill('1 Test Street');
    await page.getByRole('textbox', { name: 'City' }).fill('New York');
    await page.getByRole('textbox', { name: 'ZIP' }).fill('10001');
    const state = page.getByRole('combobox', { name: 'Search for option' });
    if (await state.isVisible()) {
      await state.click();
      await page.keyboard.type('New York');
      await page.keyboard.press('Enter');
    }

    await page.getByRole('button', { name: /^Pay \$12\.00/ }).click();
  });

  await test.step('back on our billing page; the signed webhook moves the account to Starter', async () => {
    // Lemon Squeezy confirms on its own page; Continue follows redirect_url.
    await expect(page.getByRole('heading', { name: 'Thanks for your order!' })).toBeVisible({ timeout: 90_000 });
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForURL(/^https:\/\/app\.codeinchrome\.com\/billing/, { timeout: 60_000 });
    await expect(page.getByText('Your payment is being confirmed')).toBeVisible();

    const state = await waitForBilling(page, (s) => s.plan === 'starter' && s.status === 'active', 'plan starter, status active');
    subscriptionId = state.id;
    expect(subscriptionId).toBeTruthy();

    const sub = await lsSubscription(subscriptionId);
    expect(sub.user_email).toBe(email);
    expect(sub.status).toBe('active');

    await page.goto('/billing');
    await expect(page.getByText('You are on the Starter plan')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Manage billing, card and cancellation' })).toBeVisible();
  });

  await test.step('while subscribed, deleting the account is refused', async () => {
    await page.goto('/account');
    await page.getByText('Delete my account').click();
    const form = page.locator('form', { has: page.getByRole('button', { name: 'Delete everything' }) });
    await form.getByPlaceholder('Current password').fill(password);
    await form.getByRole('button', { name: 'Delete everything' }).click();
    await expect(page.getByText('Cancel your subscription in the billing portal first')).toBeVisible();
  });

  await test.step('cancel: still Starter until the paid period ends, and the page says when', async () => {
    await lsCancel(subscriptionId);
    const state = await waitForBilling(page, (s) => s.status === 'cancelled', 'status cancelled');
    expect(state.plan).toBe('starter');
    expect(state.ends_at).toBeTruthy();
    await page.goto('/billing');
    await expect(page.getByText(/Subscription cancelled.*ends/)).toBeVisible();
  });

  await test.step('resume: active again', async () => {
    await lsResume(subscriptionId);
    const state = await waitForBilling(page, (s) => s.status === 'active', 'status active again');
    expect(state.plan).toBe('starter');
  });

  await test.step('cancel for good, so the test leaves no renewing subscription behind', async () => {
    await lsCancel(subscriptionId);
    await waitForBilling(page, (s) => s.status === 'cancelled', 'final cancel');
  });
  } finally {
    // Whatever failed, no test subscription is left renewing in Lemon Squeezy.
    await lsCancelAllFor(email);
  }
});
