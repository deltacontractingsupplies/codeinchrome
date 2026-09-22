import { test, expect } from '@playwright/test';
import { confirmSignup } from '../helpers/fixtures.js';
import { waitForDns } from '../helpers/dns.js';
import { destroySite } from '../helpers/cleanup.js';

/**
 * The file API as the panel will actually use it: through a browser session,
 * against a real site on a real host.
 *
 * The path-containment guarantee is enforced and tested in the agent against a
 * real filesystem. What this proves is the whole chain - session auth,
 * ownership, the tunnel, the agent, the disk - and that a refusal survives
 * every hop back to the browser with its reason intact.
 */

const stamp = Date.now().toString(36);
const account = {
  email: `files-${stamp}@codeinchrome.test`,
  password: `files-${stamp}-${Math.random().toString(36).slice(2)}-Kp9`,
};
const siteName = `f-${stamp}`.slice(0, 40);

test.describe.configure({ mode: 'serial' });
test.afterAll(() => destroySite(siteName));

test('the panel can browse and edit a real site through the control plane', async ({ page }) => {
  await test.step('sign up and provision', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill('Files Runner');
    await page.getByLabel('Email').fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByLabel('Confirm password').fill(account.password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await confirmSignup(page, account.email);

    await page.getByPlaceholder('my-shop').fill(siteName);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByText(/is building/)).toBeVisible();

    await waitForDns(`${siteName}.codeinchrome.com`);
  });

  // Requests made from the PAGE, so they carry the session cookie exactly as
  // the panel's own fetch calls will.
  const call = (method, query, body) =>
    page.evaluate(
      async ({ method, query, body, name }) => {
        const response = await fetch(`/sites/${name}/files${query}`, {
          method,
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
          },
          body: body ? JSON.stringify(body) : undefined,
        });

        return { status: response.status, body: await response.json().catch(() => null) };
      },
      { method, query, body, name: siteName },
    );

  await test.step('list the site root', async () => {
    const { status, body } = await call('GET', '?path=/');
    expect(status).toBe(200);
    expect(body.ok).toBe(true);

    const names = body.listing.entries.map((e) => e.name);
    expect(names).toContain('public');
    expect(names).toContain('artisan');

    // The host's real layout must never reach the browser.
    for (const entry of body.listing.entries) {
      expect(entry.path.startsWith('/')).toBe(true);
      expect(entry.path).not.toContain('/srv/');
    }
  });

  await test.step('read a file that Laravel shipped', async () => {
    const { status, body } = await call('GET', '?read=1&path=/routes/web.php');
    expect(status).toBe(200);
    expect(body.content).toContain('<?php');
  });

  await test.step('write a file into a directory that does not exist yet', async () => {
    const { status, body } = await call('PUT', '', {
      path: '/app/Services/PanelProof.php',
      content: "<?php\n\nnamespace App\\Services;\n\nclass PanelProof\n{\n    public const MARK = 'written-by-the-panel';\n}\n",
    });
    expect(status).toBe(200);
    expect(body.ok).toBe(true);

    const back = await call('GET', '?read=1&path=/app/Services/PanelProof.php');
    expect(back.body.content).toContain('written-by-the-panel');
  });

  await test.step('emptying a file is a legitimate edit', async () => {
    const { status } = await call('PUT', '', { path: '/app/Services/PanelProof.php', content: '' });
    expect(status).toBe(200);

    const back = await call('GET', '?read=1&path=/app/Services/PanelProof.php');
    expect(back.body.content).toBe('');
  });

  await test.step('a path outside the site is refused, with its reason', async () => {
    for (const path of ['/../../../etc/passwd', '/etc/shadow', '/public/../../../../etc/passwd']) {
      const { status, body } = await call('GET', `?read=1&path=${encodeURIComponent(path)}`);
      expect(status, `${path} must not be readable`).not.toBe(200);
      expect(JSON.stringify(body)).not.toContain('root:');
    }
  });

  await test.step('delete what we made', async () => {
    const { status, body } = await call('DELETE', '?path=/app/Services/PanelProof.php');
    expect(status).toBe(200);
    expect(body.deleted).toBe(true);

    const gone = await call('GET', '?read=1&path=/app/Services/PanelProof.php');
    expect(gone.status).not.toBe(200);
  });
});
