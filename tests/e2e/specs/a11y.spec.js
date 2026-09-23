import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * Accessibility, checked by axe-core against WCAG 2.1 A and AA on every page
 * a customer uses, in both themes. Any serious or critical violation fails.
 * Monaco's own internals are left to Monaco (its accessibility is its own
 * project's); everything around it is ours.
 */

const stamp = Date.now().toString(36);
const siteName = `ax-${stamp}`.slice(0, 40);
const email = `ax-${stamp}@codeinchrome.test`;
const password = `ax-${stamp}-${Math.random().toString(36).slice(2)}-Kd4`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
test.setTimeout(600_000);

async function audit(page, name, { exclude = [] } = {}) {
  const found = [];
  for (const theme of ['dark', 'light']) {
    await page.evaluate((t) => { localStorage.setItem('cic-theme', t); document.documentElement.dataset.theme = t; }, theme);
    let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
    for (const sel of exclude) builder = builder.exclude(sel);
    const { violations } = await builder.analyze();
    for (const v of violations.filter((v) => ['serious', 'critical'].includes(v.impact))) {
      found.push(`[${name}, ${theme}] ${v.id} (${v.impact}): ${v.help} - ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    }
  }
  await page.evaluate(() => localStorage.removeItem('cic-theme'));
  return found;
}

test('every page meets WCAG 2.1 AA (no serious or critical violations), in both themes', async ({ page }) => {
  const problems = [];

  await test.step('public pages', async () => {
    for (const [path, name] of [['/', 'home'], ['/pricing', 'pricing'], ['/login', 'sign in'], ['/register', 'sign up'], ['/terms', 'terms']]) {
      await page.goto(path);
      problems.push(...(await audit(page, name)));
    }
  });

  await test.step('signed in: dashboard, account, billing', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Axe Runner');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, email);
    problems.push(...(await audit(page, 'dashboard (empty)')));
    for (const [path, name] of [['/account', 'account'], ['/billing', 'billing']]) {
      await page.goto(path);
      problems.push(...(await audit(page, name)));
    }
  });

  await test.step('a site: dashboard, settings, backups, editor', async () => {
    await page.goto('/sites');
    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();
    await waitForDns(`${siteName}.codeinchrome.com`);
    problems.push(...(await audit(page, 'dashboard (with a site)')));
    for (const [path, name] of [[`/sites/${siteName}/settings`, 'settings'], [`/sites/${siteName}/backups`, 'backups']]) {
      await page.goto(path);
      problems.push(...(await audit(page, name)));
    }
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    problems.push(...(await audit(page, 'editor', { exclude: ['.monaco-editor'] })));
  });

  expect(problems, problems.join('\n')).toEqual([]);
});
