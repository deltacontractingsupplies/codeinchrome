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

  await test.step('the file tree works from the keyboard alone', async () => {
    const app = page.locator('#tree .node[data-path="/app"]');
    await page.locator('#tree .node').first().focus();
    // Arrow down to "app" (the first folder in a Laravel tree).
    for (let i = 0; i < 5 && !(await app.evaluate((n) => n === document.activeElement)); i++) await page.keyboard.press('ArrowDown');
    await expect(app).toBeFocused();
    await expect(app).toHaveAttribute('aria-expanded', 'false');
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#tree .node[data-path="/app"]')).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#tree .node:focus')).toHaveAttribute('data-path', /^\/app\//);
    await page.keyboard.press('ArrowLeft');
    await expect(page.locator('#tree .node[data-path="/app"]')).toBeFocused();

    await page.keyboard.press('End');
    const last = await page.locator('#tree .node:focus').getAttribute('data-path');
    // Space opens, as in VS Code (on macOS, Enter renames).
    await page.keyboard.press('Space');
    await expect(page.locator('.tab-name[aria-current="true"]')).toHaveText(last.split('/').pop());
  });

  expect(problems, problems.join('\n')).toEqual([]);
});
