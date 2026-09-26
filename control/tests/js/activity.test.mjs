// The agent-activity helpers: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { changedLines, lineRanges, createActivity, latestOnly } from '../../resources/js/activity.js';

test('a new file is all added', () => {
  assert.deepEqual(changedLines(null, 'a\nb\nc'), { added: [1, 2, 3], removedAt: [], created: true });
});

test('an edit marks only the lines it changed, in the new text', () => {
  const before = 'one\ntwo\nthree\nfour\nfive';
  const after = 'one\nTWO\nthree\nfour\nfive\nsix';
  assert.deepEqual(changedLines(before, after), { added: [2, 6], removedAt: [], created: false });
});

test('a deletion is marked where the lines were', () => {
  assert.deepEqual(changedLines('a\nb\nc\nd', 'a\nd'), { added: [], removedAt: [2], created: false });
});

test('unchanged content marks nothing', () => {
  assert.deepEqual(changedLines('x\ny', 'x\ny'), { added: [], removedAt: [], created: false });
});

test('line numbers become ranges for the editor', () => {
  assert.deepEqual(lineRanges([1, 2, 3, 7, 9, 10]), [[1, 3], [7, 7], [9, 10]]);
  assert.deepEqual(lineRanges([]), []);
});

test('the activity log keeps the newest, updates a running step, and caps its length', () => {
  let t = 1000;
  const log = createActivity({ max: 3, now: () => t });
  const run = log.add({ kind: 'run', text: 'artisan test' });
  assert.equal(run.state, 'running');
  t = 3500;
  log.finish(run.id, { ok: false, detail: 'exit 1' });
  assert.deepEqual(log.items().map((i) => [i.kind, i.state, i.ms]), [['run', 'failed', 2500]]);
  for (const p of ['/a', '/b', '/c']) log.add({ kind: 'write', text: p, state: 'done' });
  assert.deepEqual(log.items().map((i) => i.text), ['/a', '/b', '/c'], 'the oldest fell off');
  assert.equal(log.finish('nope', { ok: true }), null, 'an unknown step is ignored');
});

test('latestOnly runs the newest call once per interval and drops the ones between', async () => {
  const seen = [];
  const f = latestOnly((x) => seen.push(x), 30);
  f(1); f(2); f(3);
  await new Promise((r) => setTimeout(r, 60));
  f(4);
  await new Promise((r) => setTimeout(r, 60));
  assert.deepEqual(seen, [1, 3, 4]);
});
