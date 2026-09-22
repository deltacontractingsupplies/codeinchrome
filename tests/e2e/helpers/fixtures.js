import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const REPO = fileURLToPath(new URL('../../..', import.meta.url));

function onControl(artisanArgs) {
  const base = process.env.CIC_BASE_URL || 'http://127.0.0.1:8123';
  if (/127\.0\.0\.1|localhost/.test(base)) {
    return execFileSync('php', ['artisan', ...artisanArgs], { cwd: `${REPO}/control`, stdio: 'pipe' }).toString();
  }
  const quoted = artisanArgs.map((a) => `'${String(a).replace(/'/g, "'\\''")}'`).join(' ');
  return execFileSync('ssh', ['-o', 'ConnectTimeout=20', process.env.CIC_CONTROL_SSH || 'root@203.0.113.104',
    `cd /srv/control && sudo -u codeinchrome php8.4 artisan ${quoted}`], { stdio: 'pipe' }).toString();
}

/**
 * Put a TEST account on a paid plan. Signup gives the free plan and a real
 * upgrade needs a real payment, so this goes through tinker - and refuses any
 * address that is not @codeinchrome.test, so it can never touch a customer.
 */
export function setPlan(email, plan) {
  if (!email.endsWith('@codeinchrome.test')) throw new Error('setPlan is for test accounts only');
  onControl(['tinker', `--execute=App\\Models\\User::where('email', '${email}')->update(['plan' => '${plan}']);`]);
}

/**
 * Mark a TEST account's email as confirmed. Test addresses are under the
 * reserved .test TLD and cannot receive the real confirmation mail, so the
 * suite does what clicking the link would do - for @codeinchrome.test only.
 */
export function markVerified(email) {
  if (!email.endsWith('@codeinchrome.test')) throw new Error('markVerified is for test accounts only');
  onControl(['tinker', `--execute=App\\Models\\User::where('email', '${email}')->update(['email_verified_at' => now()]);`]);
}

/**
 * After clicking "Create account": the confirmation screen appears when the
 * platform sends mail, then the account is confirmed and the dashboard opened.
 */
export async function confirmSignup(page, email) {
  await page.waitForURL(/\/(sites|email\/verify)$/);
  if (page.url().endsWith('/email/verify')) {
    await page.getByText('Confirm your email').waitFor();
    markVerified(email);
  }
  await page.goto('/sites');
}

/** Cloudflare, for records under the test-only cdtest subdomain. */
function cf() {
  const env = Object.fromEntries(readFileSync(`${REPO}/.env`, 'utf8').split('\n')
    .filter((l) => l.includes('=') && !l.startsWith('#')).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1).trim()]));
  return { token: env.CLOUDFLARE_API_TOKEN, zone: env.CLOUDFLARE_ZONE_ID };
}

export async function dnsCreate(type, name, content) {
  if (!name.endsWith('.cdtest.codeinchrome.com')) throw new Error('test records must live under cdtest.codeinchrome.com');
  const { token, zone } = cf();
  const r = await fetch(`https://api.cloudflare.com/client/v4/zones/${zone}/dns_records`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ type, name, content, ttl: 60, proxied: false }),
  });
  const j = await r.json();
  if (!j.success) throw new Error(`cloudflare: ${JSON.stringify(j.errors)}`);
  return j.result.id;
}

export async function dnsDeleteUnder(suffix) {
  if (!suffix.endsWith('.cdtest.codeinchrome.com')) throw new Error('refusing to delete outside cdtest');
  const { token, zone } = cf();
  const list = await (await fetch(`https://api.cloudflare.com/client/v4/zones/${zone}/dns_records?per_page=100`, {
    headers: { Authorization: `Bearer ${token}` },
  })).json();
  for (const rec of list.result ?? []) {
    if (rec.name === suffix || rec.name.endsWith(`.${suffix}`)) {
      await fetch(`https://api.cloudflare.com/client/v4/zones/${zone}/dns_records/${rec.id}`, {
        method: 'DELETE', headers: { Authorization: `Bearer ${token}` },
      });
    }
  }
}
