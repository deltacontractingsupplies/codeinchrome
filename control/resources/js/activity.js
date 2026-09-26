// What the agent is doing, shown as it happens (owner, 2026-09-26: "not
// waiting 10 minutes and seeing nothing, then everything at once").
//
// The pure parts of it, kept apart from the editor so they can be tested:
// which lines a write changed, the step log, and a throttle that keeps the
// screen following the newest write without redrawing for every file of a
// big batch. The editor (editor.js) wires them to Monaco, the tree and the
// panel.
import { diffOps } from './shell.js';

/**
 * The lines a write changed, numbered in the NEW text (1-based):
 * added - lines that are new or different; removedAt - the line each removed
 * run was before. before === null: a new file, every line added.
 */
export function changedLines(before, after) {
  const count = after === '' ? 0 : after.split('\n').length;
  const all = () => ({ added: Array.from({ length: count }, (_, i) => i + 1), removedAt: [], created: before === null });
  if (before === null || before === undefined) return all();
  const ops = diffOps(before, after);
  if (ops === null) return { ...all(), created: false }; // too different to align: all of it changed
  const added = [];
  const removedAt = [];
  let line = 1;
  for (const [op] of ops) {
    if (op === ' ') line++;
    else if (op === '+') added.push(line++);
    else if (removedAt.at(-1) !== line) removedAt.push(line);
  }
  // A line replaced is shown as changed, not also as a removal beside it.
  const changed = new Set(added);
  return { added, removedAt: removedAt.filter((n) => !changed.has(n)), created: false };
}

/** [1, 2, 3, 7] -> [[1, 3], [7, 7]]: runs of lines, for editor decorations. */
export function lineRanges(lines) {
  const out = [];
  for (const n of lines) {
    const last = out.at(-1);
    if (last && n === last[1] + 1) last[1] = n;
    else out.push([n, n]);
  }
  return out;
}

/**
 * The step log: newest last, capped at `max`. A step is added as it starts
 * (state 'running', or given) and finished with its outcome and duration.
 */
export function createActivity({ max = 300, now = () => Date.now() } = {}) {
  const list = [];
  let seq = 0;
  return {
    add(entry) {
      const item = { id: `a${++seq}`, at: now(), state: 'running', ms: null, detail: '', ...entry };
      list.push(item);
      if (list.length > max) list.splice(0, list.length - max);
      return item;
    },
    finish(id, { ok, detail = '' }) {
      const item = list.find((i) => i.id === id);
      if (!item) return null;
      Object.assign(item, { state: ok ? 'done' : 'failed', ms: now() - item.at, detail });
      return item;
    },
    items: () => list.slice(),
    clear: () => { list.length = 0; },
  };
}

/**
 * f, called at most once per `ms` with the newest arguments: the first call
 * runs now, calls during the wait collapse into one at its end. The screen
 * follows a 500-file write at ~10 updates a second, not 500.
 */
export function latestOnly(f, ms) {
  let waiting = false;
  let pending = null;
  const flush = () => {
    if (pending) {
      const args = pending;
      pending = null;
      f(...args);
      setTimeout(flush, ms);
    } else {
      waiting = false;
    }
  };
  return (...args) => {
    if (waiting) {
      pending = args;
      return;
    }
    waiting = true;
    f(...args);
    setTimeout(flush, ms);
  };
}
