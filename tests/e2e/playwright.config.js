import { defineConfig, devices } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';

/*
 * Temporary files stay beside the suite, not in the system temp directory.
 *
 * Every run launches Chromium with a throwaway profile, and node writes its
 * own scratch files; by default both go to os.tmpdir(), which on macOS is
 * /var/folders on the BOOT disk. Measured: about 3 MB per run. Small, but the
 * machine this was built on has a soldered SSD that is deliberately spared,
 * and there is no reason for any of it to land there. os.tmpdir() re-reads
 * TMPDIR on every call, so setting it here - before any browser is launched -
 * covers the runner and everything it spawns, however the suite is started.
 * CIC_E2E_TMP overrides the location.
 */
// The suite's reserved sign-up domain is accepted only on its own requests:
// today's HMAC (UTC) of CIC_SIGNUP_TEST_SECRET, which the control host holds
// too (App\Auth\TestSuite). Read from the environment or the repository's
// local .env (never committed).
function e2eHeader() {
  let secret = process.env.CIC_SIGNUP_TEST_SECRET || '';
  if (!secret) {
    try {
      const env = readFileSync(fileURLToPath(new URL('../../.env', import.meta.url)), 'utf8');
      secret = (env.match(/^CIC_SIGNUP_TEST_SECRET=(.*)$/m) || [])[1]?.trim() || '';
    } catch { /* no local .env: no header, and test sign-ups are refused */ }
  }
  if (!secret) return {};
  return { 'X-CIC-E2E': createHmac('sha256', secret).update(new Date().toISOString().slice(0, 10)).digest('hex') };
}

const localTmp = process.env.CIC_E2E_TMP || fileURLToPath(new URL('./.tmp', import.meta.url));
mkdirSync(localTmp, { recursive: true });
process.env.TMPDIR = localTmp;

/**
 * These tests drive the REAL control plane against the REAL fleet: signing up
 * creates a container on a Hetzner host, a DNS record in Cloudflare and a
 * Let's Encrypt certificate. That is the point - a mocked provisioning test
 * proves the mock works.
 *
 * Consequences, which the specs are written around:
 *   - Workers are 1. Two specs provisioning at once would race for host
 *     capacity and for the same site names.
 *   - Retries are 0. A retry would re-run provisioning and leave the first
 *     run's container behind.
 *   - Every spec cleans up what it created, in a finally, including on failure.
 */
export default defineConfig({
  testDir: './specs',
  globalTeardown: './helpers/global-teardown.js',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 180_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { outputFolder: 'report', open: 'never' }]],
  use: {
    baseURL: process.env.CIC_BASE_URL || 'http://127.0.0.1:8123',
    extraHTTPHeaders: e2eHeader(),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: [
            // Chrome speculatively resolves every link it renders. The
            // dashboard shows a link to the site the instant it is created,
            // so the prefetch fires BEFORE the DNS record has propagated,
            // gets NXDOMAIN, and that negative answer lands in the shared
            // macOS resolver cache for the zone's SOA minimum - roughly half
            // an hour. Node then inherits it, and a site that is live and
            // serving looks dead for the rest of the run. The spec passed in
            // isolation and failed in a full run for exactly this reason.
            '--dns-prefetch-disable',
          ],
        },
      },
    },
  ],
});
