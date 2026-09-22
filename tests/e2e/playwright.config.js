import { defineConfig, devices } from '@playwright/test';

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
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 180_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['html', { outputFolder: 'report', open: 'never' }]],
  use: {
    baseURL: process.env.CIC_BASE_URL || 'http://127.0.0.1:8123',
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
