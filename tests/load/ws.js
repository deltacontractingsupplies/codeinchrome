// One step of the WebSocket capacity test: CONNS real Pusher-protocol
// connections to the site's Laravel Reverb, held for HOLD seconds, each
// subscribed to the public channel "bench". The site broadcasts a "tick"
// every second carrying the time it was sent (bench:tick in tests/load/app),
// and every connection measures how late each tick arrives.
//
// k6's event-loop WebSocket API lets one VU hold many sockets, so ten
// thousand connections do not need ten thousand VUs (a VU costs megabytes -
// the generator would run out of memory long before the site did).
//
// Latency is measured across two hosts, so it includes their clock offset;
// both are NTP-synchronised, which keeps that to a few milliseconds.
import { WebSocket } from 'k6/experimental/websockets';
import { Counter, Trend } from 'k6/metrics';

const CONNS = Number(__ENV.CONNS || 100);
const HOLD = Number(__ENV.HOLD || 60);      // seconds each connection is held
const RAMP = Number(__ENV.RAMP || 20);      // seconds over which they are opened
const VUS = Math.min(CONNS, 50);
const HOST = __ENV.HOST;
const ORIGIN = __ENV.ORIGIN;
const KEY = __ENV.KEY;

const opened = new Counter('ws_opened');
const subscribed = new Counter('ws_subscribed');
const closedEarly = new Counter('ws_closed_early');
const errors = new Counter('ws_errors');
const ticks = new Counter('ws_ticks');
const latency = new Trend('ws_tick_latency', true);

export const options = {
  hosts: { [HOST]: ORIGIN },
  insecureSkipTLSVerify: true,
  scenarios: {
    ws: { executor: 'per-vu-iterations', vus: VUS, iterations: 1, maxDuration: `${HOLD + RAMP + 60}s` },
  },
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const vu = __VU - 1;
  const mine = Math.floor(CONNS / VUS) + (vu < CONNS % VUS ? 1 : 0);
  const url = `wss://${HOST}/app/${KEY}?protocol=7&client=js&version=8.4.0&flash=false`;
  for (let i = 0; i < mine; i++) {
    // Spread the opens over RAMP seconds: a real audience arrives over time,
    // and a thundering herd would measure the TLS handshake, not the holding.
    const at = ((i * VUS + vu) / CONNS) * RAMP * 1000;
    setTimeout(() => {
      const ws = new WebSocket(url);
      let done = false;
      ws.onopen = () => opened.add(1);
      ws.onmessage = (m) => {
        const msg = JSON.parse(m.data);
        if (msg.event === 'pusher:connection_established') {
          ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'bench' } }));
        } else if (msg.event === 'pusher_internal:subscription_succeeded') {
          subscribed.add(1);
        } else if (msg.event === 'pusher:ping') {
          ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
        } else if (msg.event === 'tick') {
          const data = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
          ticks.add(1);
          latency.add(Date.now() - Number(data.t));
        }
      };
      ws.onerror = () => errors.add(1);
      ws.onclose = () => { if (!done) closedEarly.add(1); };
      setTimeout(() => { done = true; ws.close(); }, (RAMP * 1000 - at) + HOLD * 1000);
    }, at);
  }
}

export function handleSummary(data) {
  const v = (name, key = 'count') => (data.metrics[name] ? data.metrics[name].values[key] : 0);
  return {
    stdout: JSON.stringify({
      conns: CONNS,
      opened: v('ws_opened'),
      subscribed: v('ws_subscribed'),
      closed_early: v('ws_closed_early'),
      errors: v('ws_errors'),
      ticks: v('ws_ticks'),
      p50_ms: data.metrics.ws_tick_latency ? data.metrics.ws_tick_latency.values['p(50)'] : null,
      p95_ms: data.metrics.ws_tick_latency ? data.metrics.ws_tick_latency.values['p(95)'] : null,
    }) + '\n',
  };
}
