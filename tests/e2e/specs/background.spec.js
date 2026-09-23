import { test, expect } from '@playwright/test';
import { confirmSignup, setPlan } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { httpsGet } from '../helpers/https.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * A site's background processes, proved by their effect: a queued job that
 * only a running worker can execute, and a task the scheduler must run on
 * its own within the minute. Switched on the way a customer does it - the
 * site's settings page - on a paid plan.
 */

const stamp = Date.now().toString(36);
const siteName = `bg-${stamp}`.slice(0, 40);
const email = `bg-${stamp}@codeinchrome.test`;
const password = `bg-${stamp}-${Math.random().toString(36).slice(2)}-Zt7`;

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));
test.setTimeout(600_000);

test('a queued job runs and a scheduled task fires, with nothing but the settings switched on', async ({ page }) => {
  let address;

  await test.step('sign up on a paid plan and create a site', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Background Runner');
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
    [address] = await waitForDns(`${siteName}.codeinchrome.com`);
  });

  await test.step('the app gets a job and a schedule that each leave a mark', async () => {
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    const web = (await page.evaluate(() => window.cic.read('/routes/web.php'))).content;
    const w1 = await page.evaluate((c) => window.cic.write('/routes/web.php', c), web + `
Route::get('/queue-probe', function () {
    dispatch(function () {
        file_put_contents(storage_path('app/queued.txt'), 'ran by the worker at '.now());
    });
    return 'dispatched';
});
`);
    expect(w1.ok).toBe(true);
    const cons = (await page.evaluate(() => window.cic.read('/routes/console.php'))).content;
    const w2 = await page.evaluate((c) => window.cic.write('/routes/console.php', c), cons + `
\\Illuminate\\Support\\Facades\\Schedule::call(function () {
    file_put_contents(storage_path('app/scheduled.txt'), 'ran by the scheduler at '.now());
})->everyMinute();
`);
    expect(w2.ok).toBe(true);
  });

  await test.step('switch on the queue worker and the scheduler in the site settings', async () => {
    await page.goto(`/sites/${siteName}/settings`);
    await page.getByLabel(/Queue worker/).check();
    await page.getByLabel(/Scheduler/).check();
    await page.getByRole('button', { name: 'Save and restart' }).click();
    await expect(page.getByText(/Applied\. The site restarted/)).toBeVisible({ timeout: 120_000 });
  });

  await test.step('a dispatched job is run by the worker', async () => {
    await expect(async () => {
      const r = await httpsGet(`${siteName}.codeinchrome.com`, '/queue-probe', address);
      expect(r.status).toBe(200);
      expect(r.body).toBe('dispatched');
    }).toPass({ timeout: 60_000, intervals: [3_000] });
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    await expect(async () => {
      const f = await page.evaluate(() => window.cic.read('/storage/app/queued.txt'));
      expect(f.ok, f.hint).toBe(true);
      expect(f.content).toContain('ran by the worker');
    }).toPass({ timeout: 60_000, intervals: [3_000] });
  });

  await test.step('PHP settings from the settings page reach PHP, read-only to the site', async () => {
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    const web = (await page.evaluate(() => window.cic.read('/routes/web.php'))).content;
    expect((await page.evaluate((c) => window.cic.write('/routes/web.php', c), web + `
Route::get('/php-probe', fn () => ini_get('memory_limit').' '.ini_get('upload_max_filesize').' '.ini_get('post_max_size'));
`)).ok).toBe(true);
    await page.goto(`/sites/${siteName}/settings`);
    await page.getByLabel('Memory limit').fill('200');
    await page.getByLabel('Largest upload').fill('48');
    await page.getByRole('button', { name: 'Save PHP settings' }).click();
    await expect(page.getByText('Applied. The site restarted with its new PHP settings.')).toBeVisible({ timeout: 120_000 });
    await expect(async () => {
      const r = await httpsGet(`${siteName}.codeinchrome.com`, '/php-probe', address);
      expect(r.body).toBe('200M 48M 49M');
    }).toPass({ timeout: 60_000, intervals: [3_000] });

    // A plan-busting value is refused with the reason, and nothing changes.
    await page.getByLabel('Memory limit').fill('1000');
    await page.getByRole('button', { name: 'Save PHP settings' }).click();
    await expect(page.getByText(/memory limit must be 64 to 448 MB/)).toBeVisible({ timeout: 30_000 });
    const r = await httpsGet(`${siteName}.codeinchrome.com`, '/php-probe', address);
    expect(r.body).toBe('200M 48M 49M');
  });

  await test.step('the scheduler runs the task on its own within the minute', async () => {
    await page.goto(`/sites/${siteName}/edit`);
    await expect(page.locator('#sbMsg')).toHaveText('Ready');
    await expect(async () => {
      const f = await page.evaluate(() => window.cic.read('/storage/app/scheduled.txt'));
      expect(f.ok, f.hint).toBe(true);
      expect(f.content).toContain('ran by the scheduler');
    }).toPass({ timeout: 150_000, intervals: [5_000] });
  });
});
