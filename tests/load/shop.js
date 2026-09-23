// One step of the capacity test: RATE page views per second of /shop for
// DURATION, as an OPEN model - arrivals keep coming at the set rate whether
// or not the server keeps up, the way real visitors do. (A closed model, where
// each virtual user waits for its last answer before asking again, slows down
// with the server and flatters it.)
//
// Each virtual user keeps its own cookie jar, so every visitor has a session,
// as a real one would. The target is the site's ORIGIN, reached directly by
// address: this measures the plan's container, not Cloudflare's cache.
import http from 'k6/http';
import { check } from 'k6';

const RATE = Number(__ENV.RATE || 10);
const HOST = __ENV.HOST;       // e.g. bench.codeinchrome.com
const ORIGIN = __ENV.ORIGIN;   // the host's address

export const options = {
  hosts: { [HOST]: ORIGIN },
  // The origin serves the Cloudflare Origin CA certificate, trusted by
  // Cloudflare only; the name is still checked by SNI routing on the host.
  insecureSkipTLSVerify: true,
  discardResponseBodies: true,
  scenarios: {
    shop: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: Math.max(20, RATE * 2),
      maxVUs: Math.max(100, RATE * 20),
    },
  },
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const r = http.get(`https://${HOST}/shop`, { timeout: '10s', tags: { page: 'shop' } });
  check(r, { 'status 200': (res) => res.status === 200 });
}

export function handleSummary(data) {
  const m = data.metrics;
  const out = {
    rate: RATE,
    requests: m.http_reqs ? m.http_reqs.values.count : 0,
    achieved_rps: m.http_reqs ? m.http_reqs.values.rate : 0,
    failed_ratio: m.http_req_failed ? m.http_req_failed.values.rate : 1,
    p50_ms: m.http_req_duration ? m.http_req_duration.values['p(50)'] : null,
    p95_ms: m.http_req_duration ? m.http_req_duration.values['p(95)'] : null,
    p99_ms: m.http_req_duration ? m.http_req_duration.values['p(99)'] : null,
    dropped: m.dropped_iterations ? m.dropped_iterations.values.count : 0,
  };
  return { stdout: JSON.stringify(out) + '\n' };
}
