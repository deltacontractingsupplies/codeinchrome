/*
 * cic.sh - the shell's own commands for an agent that has only a browser.
 *
 * An agent that knows a terminal already knows how to work: ls, cat, grep -rn,
 * find, sed -i, heredocs, pipes, &&. It should not have to learn a second
 * vocabulary to build in the editor, so this speaks the first one. Every
 * command runs against the live site through the same API the editor uses
 * (the host checks every path); nothing runs on the agent's computer, and
 * there is no real shell anywhere - this is a parser and a set of commands.
 *
 * The module is pure: `io` is how it reaches the site, so the tests run it
 * against an in-memory tree (tests/js/shell.test.mjs) with `node --test`.
 */

import { runAwk, AwkError } from './awk.js';

const GLOB = /[*?[]/;

/* ───────────────────────── parsing ───────────────────────── */

class ShellSyntaxError extends Error {}

// src[open] is "(": the index of the ")" that closes it, past quotes and
// nested parentheses.
function matchClose(src, open) {
  let depth = 0;
  for (let j = open; j < src.length; j++) {
    const c = src[j];
    if (c === '\\') { j++; continue; }
    if (c === "'") { const e = src.indexOf("'", j + 1); if (e < 0) break; j = e; continue; }
    if (c === '"') {
      for (j++; j < src.length && src[j] !== '"'; j++) if (src[j] === '\\') j++;
      continue;
    }
    if (c === '(') depth++;
    if (c === ')' && --depth === 0) return j;
  }
  throw new ShellSyntaxError('unexpected end of input: a "(" is not closed');
}

/**
 * Words and operators, the way sh splits them: quotes, backslashes,
 * comments, fd redirections and here-documents.
 *
 * `$NAME`, `${NAME}`, `${NAME:-default}`, `$?`, `$(command)` and
 * `$((arithmetic))` are expansions, as in sh - outside single quotes, and not
 * in a here-document's body (so PHP written with `cat > f <<EOF` arrives as
 * written). A word keeps its raw text in `v`; `parts` says which pieces were
 * quoted and which expand. A `$NAME` the shell has no value for stays as
 * written: PHP, SQL and JavaScript in double quotes are not emptied out.
 */
export function tokenize(src) {
  const tokens = [];
  const pendingHeredocs = [];
  let i = 0;
  let word = null; // { v, glob, quoted, parts, exp }
  const flush = () => {
    if (word !== null) tokens.push({ t: 'word', ...word });
    word = null;
  };
  const start = () => (word ??= { v: '', glob: false, quoted: false, parts: [], exp: false });
  // q: '' unquoted, 's' single-quoted or escaped, 'd' double-quoted.
  const lit = (text, q) => {
    start();
    word.v += text;
    const last = word.parts.at(-1);
    if (last && last.s !== undefined && last.q === q) last.s += text;
    else word.parts.push({ s: text, q });
  };
  const add = (ch, glob = false) => {
    lit(ch, '');
    if (glob) word.glob = true;
  };
  const op = (v, nl = false) => {
    flush();
    tokens.push(nl ? { t: 'op', v, nl: true } : { t: 'op', v });
  };
  // src[i] is "$": an expansion, or a plain "$".
  const dollar = (q) => {
    const n = src[i + 1] ?? '';
    let part = null;
    let end = i + 1;
    if (n === '(' && src[i + 2] === '(') {
      const close = matchClose(src, i + 1);
      if (src[close - 1] !== ')') throw new ShellSyntaxError('$(( needs its closing "))"');
      part = { e: 'arith', v: src.slice(i + 3, close - 1) };
      end = close + 1;
    } else if (n === '(') {
      const close = matchClose(src, i + 1);
      part = { e: 'sub', v: src.slice(i + 2, close) };
      end = close + 1;
    } else if (n === '{') {
      const close = src.indexOf('}', i + 2);
      if (close < 0) throw new ShellSyntaxError('${ needs its closing "}"');
      const m = /^([A-Za-z_]\w*|\?)(?::?-(.*))?$/s.exec(src.slice(i + 2, close));
      if (m) { part = { e: 'var', v: m[1], ...(m[2] !== undefined ? { def: m[2] } : {}) }; end = close + 1; }
    } else if (/[A-Za-z_]/.test(n)) {
      let k = i + 1;
      while (k < src.length && /\w/.test(src[k])) k++;
      part = { e: 'var', v: src.slice(i + 1, k) };
      end = k;
    } else if (n === '?') {
      part = { e: 'var', v: '?' };
      end = i + 2;
    }
    if (!part) { lit('$', q); i++; return; }
    start();
    part.q = q;
    part.raw = src.slice(i, end);
    word.v += part.raw;
    word.parts.push(part);
    word.exp = true;
    i = end;
  };

  while (i < src.length) {
    const c = src[i];
    if (c === '\\') {
      if (src[i + 1] === '\n') { i += 2; continue; }
      if (i + 1 < src.length) lit(src[i + 1], 's');
      i += 2;
      continue;
    }
    if (c === "'") {
      const end = src.indexOf("'", i + 1);
      if (end < 0) throw new ShellSyntaxError('unexpected end of input: a single quote is not closed');
      start();
      word.quoted = true;
      lit(src.slice(i + 1, end), 's');
      i = end + 1;
      continue;
    }
    if (c === '"') {
      start();
      word.quoted = true;
      lit('', 'd');
      i++;
      while (i < src.length && src[i] !== '"') {
        if (src[i] === '\\' && '"\\$`\n'.includes(src[i + 1])) {
          if (src[i + 1] !== '\n') lit(src[i + 1], 'd');
          i += 2;
        } else if (src[i] === '$') {
          dollar('d');
        } else {
          lit(src[i++], 'd');
        }
      }
      if (i >= src.length) throw new ShellSyntaxError('unexpected end of input: a double quote is not closed');
      i++;
      continue;
    }
    if (c === '#' && word === null) {
      while (i < src.length && src[i] !== '\n') i++;
      continue;
    }
    if (c === '\n') {
      op(';', true);
      i++;
      // Here-documents start on the line after the command that asked for them.
      for (const h of pendingHeredocs.splice(0)) {
        const lines = [];
        let found = false;
        while (i <= src.length) {
          const nl = src.indexOf('\n', i);
          const line = src.slice(i, nl < 0 ? src.length : nl);
          i = nl < 0 ? src.length + 1 : nl + 1;
          const cmp = h.strip ? line.replace(/^\t+/, '') : line;
          if (cmp === h.delim) { found = true; break; }
          lines.push(h.strip ? line.replace(/^\t+/, '') : line);
          if (nl < 0) break;
        }
        if (!found) throw new ShellSyntaxError(`here-document is missing its closing line "${h.delim}"`);
        h.token.body = lines.length ? `${lines.join('\n')}\n` : '';
      }
      if (i > src.length) i = src.length;
      continue;
    }
    if (c === ' ' || c === '\t' || c === '\r') { flush(); i++; continue; }
    if (c === '|' ) { src[i + 1] === '|' ? (op('||'), i += 2) : (op('|'), i++); continue; }
    if (c === '&') {
      if (src[i + 1] === '&') { op('&&'); i += 2; continue; }
      if (src[i + 1] === '>') { op(src[i + 2] === '>' ? '&>>' : '&>'); i += src[i + 2] === '>' ? 3 : 2; continue; }
      op(';'); i++; continue; // no background jobs: run it now
    }
    if (c === ';') { op(';'); i++; continue; }
    if (c === '>' || c === '<') {
      // "2>" and "1>" are a descriptor, not a word.
      let fd = '';
      if (word && !word.quoted && (word.v === '1' || word.v === '2')) { fd = word.v; word = null; }
      flush();
      if (c === '<' && src[i + 1] === '<') {
        const strip = src[i + 2] === '-';
        i += strip ? 3 : 2;
        while (src[i] === ' ' || src[i] === '\t') i++;
        let delim = '';
        while (i < src.length && !/[\s;|&<>]/.test(src[i])) {
          if (src[i] === "'" || src[i] === '"') {
            const q = src[i];
            const end = src.indexOf(q, i + 1);
            if (end < 0) throw new ShellSyntaxError('unexpected end of input: a quote is not closed');
            delim += src.slice(i + 1, end);
            i = end + 1;
          } else if (src[i] === '\\') {
            delim += src[i + 1] ?? '';
            i += 2;
          } else {
            delim += src[i++];
          }
        }
        if (!delim) throw new ShellSyntaxError('<< needs a word that ends the here-document');
        const token = { t: 'heredoc', body: null };
        tokens.push(token);
        pendingHeredocs.push({ delim, strip, token });
        continue;
      }
      if (c === '>' && src[i + 1] === '&' && /[12]/.test(src[i + 2] ?? '')) {
        tokens.push({ t: 'op', v: `${fd || '1'}>&${src[i + 2]}` });
        i += 3;
        continue;
      }
      const two = c === '>' && src[i + 1] === '>';
      tokens.push({ t: 'op', v: `${fd}${two ? '>>' : c}` });
      i += two ? 2 : 1;
      continue;
    }
    if (c === '$') { dollar(''); continue; }
    add(c, c === '*' || c === '?' || c === '[');
    i++;
  }
  flush();
  if (pendingHeredocs.length) throw new ShellSyntaxError(`here-document is missing its closing line "${pendingHeredocs[0].delim}"`);
  return tokens;
}

/**
 * Tokens -> [{ pipeline: [element], then: '&&' | '||' | ';' }]. An element is
 * a command { words, redirects, stdin, heredoc? } - each word { v, glob,
 * parts, exp } - or a compound { compound: 'for' | 'while' | 'until' | 'if',
 * ..., redirects }: for NAME in WORDS; do LIST; done, while/until LIST; do
 * LIST; done, if LIST; then LIST; [elif LIST; then LIST;] [else LIST;] fi.
 */
const REDIRECTS = ['>', '>>', '<', '2>', '2>>', '1>', '1>>', '&>', '&>>'];
const KEYWORDS = ['for', 'while', 'until', 'if', 'then', 'elif', 'else', 'fi', 'do', 'done', 'in'];

export function parse(src) {
  const tokens = tokenize(src);
  let k = 0;
  const isOp = (tok, ...v) => tok && tok.t === 'op' && v.includes(tok.v);
  const isKw = (tok, ...names) => tok && tok.t === 'word' && !tok.quoted && !tok.exp && names.includes(tok.v);
  // A line may go on after |, && and || on the next line.
  const skipNewlines = () => { while (tokens[k] && tokens[k].t === 'op' && tokens[k].nl) k++; };

  function redirectsInto(node) {
    for (;;) {
      const tok = tokens[k];
      if (tok && tok.t === 'heredoc') { node.heredoc = tok; k++; continue; }
      if (tok && tok.t === 'op' && REDIRECTS.includes(tok.v)) {
        const target = tokens[k + 1];
        if (!target || target.t !== 'word') throw new ShellSyntaxError(`syntax error near "${tok.v}": it needs a file`);
        node.redirects.push({ op: tok.v.replace(/^1/, ''), target: target.v, ...(target.exp ? { word: target } : {}) });
        k += 2;
        continue;
      }
      if (isOp(tok, '2>&1', '1>&2')) { node.redirects.push({ op: tok.v }); k++; continue; }
      return;
    }
  }

  function expectKw(name) {
    while (isOp(tokens[k], ';')) k++;
    if (!isKw(tokens[k], name)) throw new ShellSyntaxError(`syntax error: "${name}" is missing`);
    k++;
  }

  function parseList(stops) {
    const list = [];
    for (;;) {
      while (isOp(tokens[k], ';')) k++;
      const tok = tokens[k];
      if (!tok) {
        if (stops.length) throw new ShellSyntaxError(`syntax error: "${stops.at(-1)}" is missing`);
        return list;
      }
      if (stops.length && isKw(tok, ...stops)) return list;
      if (tok.t === 'op' && tok.v !== ';') throw new ShellSyntaxError(`syntax error near "${tok.v}"`);
      const pipeline = parsePipeline();
      const nx = tokens[k];
      if (isOp(nx, '&&', '||')) { k++; skipNewlines(); list.push({ pipeline, then: nx.v }); } else if (isOp(nx, ';')) { k++; list.push({ pipeline, then: ';' }); } else list.push({ pipeline, then: ';' });
    }
  }

  function parsePipeline() {
    const pipeline = [];
    for (;;) {
      pipeline.push(parseElement());
      if (!isOp(tokens[k], '|')) return pipeline;
      k++;
      skipNewlines();
      const nx = tokens[k];
      if (!nx || (nx.t === 'op' && !REDIRECTS.includes(nx.v))) throw new ShellSyntaxError('syntax error: the command after "|" is missing');
    }
  }

  function parseElement() {
    const tok = tokens[k];
    if (isKw(tok, 'then', 'elif', 'else', 'fi', 'do', 'done')) throw new ShellSyntaxError(`syntax error near unexpected "${tok.v}"`);
    if (isKw(tok, 'for')) {
      k++;
      const name = tokens[k];
      if (!name || name.t !== 'word' || !/^[A-Za-z_]\w*$/.test(name.v)) throw new ShellSyntaxError('for: a variable name must follow "for"');
      k++;
      let words = [];
      if (isKw(tokens[k], 'in')) {
        k++;
        while (tokens[k] && tokens[k].t === 'word') words.push(tokens[k++]);
      }
      expectKw('do');
      const body = parseList(['done']);
      k++;
      const node = { compound: 'for', name: name.v, words, body, redirects: [] };
      redirectsInto(node);
      return node;
    }
    if (isKw(tok, 'while', 'until')) {
      k++;
      const cond = parseList(['do']);
      k++;
      const body = parseList(['done']);
      k++;
      const node = { compound: tok.v, cond, body, redirects: [] };
      redirectsInto(node);
      return node;
    }
    if (isKw(tok, 'if')) {
      k++;
      const branches = [];
      let otherwise = null;
      for (;;) {
        const cond = parseList(['then']);
        k++;
        const body = parseList(['elif', 'else', 'fi']);
        branches.push({ cond, body });
        const stop = tokens[k].v;
        k++;
        if (stop === 'elif') continue;
        if (stop === 'else') { otherwise = parseList(['fi']); k++; }
        break;
      }
      const node = { compound: 'if', branches, otherwise, redirects: [] };
      redirectsInto(node);
      return node;
    }
    // A simple command.
    const cmd = { words: [], redirects: [], stdin: null };
    for (;;) {
      const t = tokens[k];
      if (!t) break;
      if (t.t === 'word') { cmd.words.push({ v: t.v, glob: t.glob, parts: t.parts, exp: t.exp, quoted: t.quoted }); k++; continue; }
      const before = k;
      redirectsInto(cmd);
      if (k === before) break;
    }
    if (!cmd.words.length && !cmd.redirects.length && !cmd.heredoc) {
      throw new ShellSyntaxError(tokens[k] ? `syntax error near "${tokens[k].v}"` : 'syntax error: a command is missing');
    }
    return cmd;
  }

  const list = parseList([]);
  return list;
}

/* ───────────────────────── helpers ───────────────────────── */

/**
 * A unified diff -> [{ path, created, deleted, noNewline, hunks: [{ oldStart,
 * lines: [' kept', '-gone', '+new'] }] }]. `strip` drops leading path
 * components (patch -pN); `reverse` swaps what was removed and added.
 */
export function parsePatch(text, { strip = 0, reverse = false } = {}) {
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  const files = [];
  let cur = null;
  let hunk = null;
  const clean = (p) => {
    const path = p.replace(/\t.*$/, '').trim().replace(/^"(.*)"$/, '$1');
    if (path === '/dev/null') return null;
    return path.split('/').slice(strip).join('/') || path;
  };
  for (let k = 0; k < lines.length; k++) {
    const line = lines[k];
    if (line.startsWith('--- ') && lines[k + 1]?.startsWith('+++ ')) {
      const from = clean(line.slice(4));
      const to = clean(lines[k + 1].slice(4));
      cur = { path: (reverse ? from ?? to : to ?? from), created: reverse ? to === null : from === null, deleted: reverse ? from === null : to === null, hunks: [], noNewline: false };
      if (!cur.path) throw new Error('a diff names no file');
      files.push(cur);
      hunk = null;
      k++;
      continue;
    }
    const h = /^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/.exec(line);
    if (h && cur) {
      // The header's counts say where the hunk ends, as patch reads it.
      const [oldN, newN] = [Number(h[2] ?? 1), Number(h[4] ?? 1)];
      hunk = { oldStart: Number(reverse ? h[3] : h[1]), lines: [], left: reverse ? [newN, oldN] : [oldN, newN] };
      cur.hunks.push(hunk);
      continue;
    }
    if (line.startsWith('\\')) { if (cur) cur.noNewline = true; continue; }
    if (!hunk) continue;
    // An empty line is a context line whose leading space an editor trimmed.
    const c = line === '' ? ' ' : line[0];
    if (c !== ' ' && c !== '-' && c !== '+') { hunk = null; continue; }
    const kind = reverse && c !== ' ' ? (c === '-' ? '+' : '-') : c;
    hunk.lines.push(kind + line.slice(1));
    if (kind !== '+') hunk.left[0]--;
    if (kind !== '-') hunk.left[1]--;
    if (hunk.left[0] <= 0 && hunk.left[1] <= 0) hunk = null;
  }
  return files;
}

/**
 * Hunks applied to a file's text: each where its line numbers say, or - if
 * the file has moved on - where its old lines are found nearest to that.
 * -> { text, failed: [hunk numbers], offsets: [[hunk, lines]] }.
 */
export function applyHunks(text, hunks) {
  const endsWithNl = text.endsWith('\n');
  const lines = text === '' ? [] : text.split('\n');
  if (endsWithNl) lines.pop();
  const failed = [];
  const offsets = [];
  let shift = 0;
  hunks.forEach((h, n) => {
    const old = h.lines.filter((l) => l[0] !== '+').map((l) => l.slice(1));
    const neu = h.lines.filter((l) => l[0] !== '-').map((l) => l.slice(1));
    let pos;
    let want;
    if (!old.length) {
      // Only additions: "@@ -5,0 +6,2 @@" goes after line 5.
      want = Math.min(h.oldStart + shift, lines.length);
      pos = want;
    } else {
      want = Math.max(0, h.oldStart - 1 + shift);
      const at = (p) => p >= 0 && p + old.length <= lines.length && old.every((l, j) => lines[p + j] === l);
      pos = -1;
      for (let d = 0; d <= lines.length; d++) {
        if (at(want - d)) { pos = want - d; break; }
        if (at(want + d)) { pos = want + d; break; }
      }
      if (pos < 0) { failed.push(n + 1); return; }
    }
    if (pos !== want) offsets.push([n + 1, pos - want]);
    lines.splice(pos, old.length, ...neu);
    shift += neu.length - old.length + (pos - want);
  });
  return { text: lines.length ? lines.join('\n') + (endsWithNl || !text ? '\n' : '') : '', failed, offsets };
}

/**
 * A Perl one-liner's statements -> (record) => { text, printed }.
 * s/re/repl/flags (any delimiter, s{..}{..}), y/// and tr///, and
 * print [$_] [if|unless /re/]. Replacements take $1, \1, $&, \n and \t.
 */
function perlProgram(src) {
  const stmts = [];
  let i = 0;
  const skip = () => { while (i < src.length && /[\s;]/.test(src[i])) i++; };
  const CLOSE = { '{': '}', '(': ')', '[': ']', '<': '>' };
  const delimited = (open) => {
    const close = CLOSE[open] ?? open;
    let depth = 1;
    let out = '';
    for (i++; i < src.length; i++) {
      const c = src[i];
      if (c === '\\' && i + 1 < src.length) { out += c + src[i + 1]; i++; continue; }
      if (close !== open && c === open) depth++;
      if (c === close && --depth === 0) { i++; return out; }
      out += c;
    }
    throw new Error('Substitution pattern not terminated');
  };
  const regexOf = (pat, flags) => {
    let f = '';
    for (const c of flags) if ('gimsu'.includes(c) && !f.includes(c)) f += c;
    // Perl-only escapes JavaScript lacks.
    return new RegExp(pat.replace(/\\A/g, '^').replace(/\\[zZ]/g, '$'), f);
  };
  const replacement = (r) => r
    .replace(/\$\{(\d+)\}/g, '$$$1')
    .replace(/\\(\d)/g, '$$$1')
    .replace(/\\n/g, '\n').replace(/\\t/g, '\t')
    .replace(/\\([^$\d])/g, '$1');
  const condition = () => {
    skip();
    const m = /^(if|unless)\b/.exec(src.slice(i));
    if (!m) return null;
    i += m[1].length;
    skip();
    if (src[i] === 'm') i++;
    if (src[i] !== '/' && !(src[i - 1] === 'm')) throw new Error('only "if /regex/" and "unless /regex/" are supported');
    const pat = delimited(src[i]);
    const fl = /^[a-z]*/.exec(src.slice(i))[0];
    i += fl.length;
    const re = regexOf(pat, fl);
    return m[1] === 'if' ? (t) => re.test(t) : (t) => !re.test(t);
  };
  for (skip(); i < src.length; skip()) {
    const rest = src.slice(i);
    if (/^s\W/.test(rest)) {
      i++;
      const d = src[i];
      const pat = delimited(d);
      if (CLOSE[d]) { skip(); }
      const repl = CLOSE[d] ? delimited(src[i]) : (i--, delimited(d));
      const fl = /^[a-z]*/.exec(src.slice(i))[0];
      i += fl.length;
      if (fl.includes('e')) throw new Error('s///e runs code: not supported here');
      const re = regexOf(pat, fl);
      const to = replacement(repl);
      const when = condition();
      stmts.push((st) => { if (!when || when(st.text)) st.text = st.text.replace(re, to); });
    } else if (/^(y|tr)\W/.test(rest)) {
      i += rest.startsWith('tr') ? 2 : 1;
      const d = src[i];
      const from = expandTr(delimited(d));
      i--;
      const to = expandTr(delimited(d));
      stmts.push((st) => { st.text = [...st.text].map((c) => { const k = from.indexOf(c); return k < 0 ? c : (to[Math.min(k, to.length - 1)] ?? c); }).join(''); });
    } else if (/^print\b/.test(rest)) {
      i += 5;
      skip();
      if (src.startsWith('$_', i)) i += 2;
      const when = condition();
      stmts.push((st) => { if (!when || when(st.text)) st.printed += st.text; });
    } else if (/^(next|chomp)\b/.test(rest)) {
      throw new Error(`"${/^\w+/.exec(rest)[0]}" is not supported here: use s///, tr/// and print [if /re/]`);
    } else {
      throw new Error(`cannot run "${rest.slice(0, 30)}" here: s///, tr/// and print [if /re/] are supported`);
    }
  }
  return (record) => {
    const st = { text: record, printed: '' };
    for (const s of stmts) s(st);
    return st;
  };
}

function expandTr(set) {
  return set.replace(/(.)-(.)/g, (m, a, b) => {
    let out = '';
    for (let c = a.charCodeAt(0); c <= b.charCodeAt(0); c++) out += String.fromCharCode(c);
    return out;
  });
}

/**
 * {a,b} and {1..3}, as sh expands them - only where the braces are
 * unquoted: "{a,b}" and '{a,b}' stay as written, and so do {} and {x}.
 */
export function braceExpand(s) {
  for (let i = 0; i < s.length; i++) {
    if (s[i] !== '{') continue;
    let depth = 0;
    const commas = [];
    let j = i;
    for (; j < s.length; j++) {
      if (s[j] === '{') depth++;
      else if (s[j] === '}') { if (--depth === 0) break; } else if (s[j] === ',' && depth === 1) commas.push(j);
    }
    if (j >= s.length) return [s];
    const body = s.slice(i + 1, j);
    let alts = null;
    if (commas.length) {
      alts = [];
      let from = i + 1;
      for (const c of [...commas, j]) { alts.push(s.slice(from, c)); from = c + 1; }
    } else {
      const n = /^(-?\d+)\.\.(-?\d+)(?:\.\.(-?\d+))?$/.exec(body);
      const c = /^([a-zA-Z])\.\.([a-zA-Z])$/.exec(body);
      if (n) {
        const [a, b] = [Number(n[1]), Number(n[2])];
        const step = Math.abs(Number(n[3] ?? 1)) || 1;
        const width = /^-?0\d/.test(n[1]) || /^-?0\d/.test(n[2]) ? Math.max(n[1].length, n[2].length) : 0;
        if (Math.abs(b - a) / step > 10000) throw new Error(`{${body}}: at most 10000 items`);
        alts = [];
        for (let x = a; a <= b ? x <= b : x >= b; x += a <= b ? step : -step) alts.push(width ? String(x).padStart(width, '0') : String(x));
      } else if (c) {
        const [a, b] = [c[1].charCodeAt(0), c[2].charCodeAt(0)];
        alts = [];
        for (let x = a; a <= b ? x <= b : x >= b; x += a <= b ? 1 : -1) alts.push(String.fromCharCode(x));
      }
    }
    if (!alts) continue;
    return alts.flatMap((alt) => braceExpand(s.slice(0, i) + alt + s.slice(j + 1)));
  }
  return [s];
}

const BRACE = /\{[^{}]*(,|\.\.)[^{}]*\}/;

// A parsed word -> the words its unquoted braces make.
function braceWords(w) {
  if (!w.parts) return [w];
  const at = w.parts.findIndex((p) => p.s !== undefined && p.q === '' && BRACE.test(p.s));
  if (at < 0) return [w];
  return braceExpand(w.parts[at].s).flatMap((alt) => {
    const parts = [...w.parts.slice(0, at), { s: alt, q: '' }, ...w.parts.slice(at + 1)];
    return braceWords({ ...w, parts, v: parts.map((p) => p.s ?? p.raw).join(''), glob: w.glob || GLOB.test(alt) });
  });
}

/**
 * $(( ... )): integers, + - * / % **, comparisons, && || !, parentheses and
 * variables by name ($i or i; unset = 0). Parsed here - never eval'd.
 */
export function arith(expr, lookup) {
  const src = String(expr).replace(/\$\{?([A-Za-z_]\w*)\}?/g, '$1');
  const toks = src.match(/\s*(\d+|[A-Za-z_]\w*|\*\*|<=|>=|==|!=|&&|\|\||[-+*/%()<>!])/g)?.map((t) => t.trim()) ?? [];
  if (toks.join('') !== src.replace(/\s+/g, '')) throw new Error(`$((${expr})): not an arithmetic expression`);
  let k = 0;
  const peek = () => toks[k];
  const next = () => toks[k++];
  const num = (v) => Math.trunc(Number(v) || 0);
  const primary = () => {
    const t = next();
    if (t === '(') { const v = or(); if (next() !== ')') throw new Error(`$((${expr})): a ")" is missing`); return v; }
    if (t === '-') return -primary();
    if (t === '+') return primary();
    if (t === '!') return primary() ? 0 : 1;
    if (/^\d+$/.test(t ?? '')) return Number(t);
    if (/^[A-Za-z_]\w*$/.test(t ?? '')) return num(lookup(t));
    throw new Error(`$((${expr})): unexpected "${t ?? 'end'}"`);
  };
  const pow = () => { const b = primary(); return peek() === '**' ? (next(), b ** pow()) : b; };
  const mul = () => {
    let v = pow();
    while (['*', '/', '%'].includes(peek())) {
      const o = next();
      const r = pow();
      if (o !== '*' && r === 0) throw new Error(`$((${expr})): division by zero`);
      v = o === '*' ? v * r : o === '/' ? Math.trunc(v / r) : v % r;
    }
    return v;
  };
  const add = () => { let v = mul(); while (['+', '-'].includes(peek())) v = next() === '+' ? v + mul() : v - mul(); return v; };
  const rel = () => {
    let v = add();
    while (['<', '>', '<=', '>='].includes(peek())) {
      const o = next();
      const r = add();
      v = Number(o === '<' ? v < r : o === '>' ? v > r : o === '<=' ? v <= r : v >= r);
    }
    return v;
  };
  const eq = () => { let v = rel(); while (['==', '!='].includes(peek())) v = next() === '==' ? Number(v === rel()) : Number(v !== rel()); return v; };
  const and = () => { let v = eq(); while (peek() === '&&') { next(); const r = eq(); v = Number(Boolean(v) && Boolean(r)); } return v; };
  function or() { let v = and(); while (peek() === '||') { next(); const r = and(); v = Number(Boolean(v) || Boolean(r)); } return v; }
  if (!toks.length) return 0;
  const v = or();
  if (k < toks.length) throw new Error(`$((${expr})): unexpected "${toks[k]}"`);
  return v;
}

/** Absolute site path of p, from cwd; never above the site's root. */
export function resolvePath(cwd, p) {
  const s = String(p);
  const start = s.startsWith('/') ? [] : cwd.split('/').filter(Boolean);
  const parts = s === '~' || s.startsWith('~/') ? s.slice(1).split('/') : s.split('/');
  if (s === '~' || s.startsWith('~/')) start.length = 0;
  for (const part of parts) {
    if (!part || part === '.') continue;
    if (part === '..') start.pop();
    else start.push(part);
  }
  return `/${start.join('/')}`;
}

const baseName = (p) => p.split('/').filter(Boolean).pop() ?? '/';
const dirName = (p) => {
  const i = p.replace(/\/+$/, '').lastIndexOf('/');
  return i <= 0 ? (p.startsWith('/') ? '/' : '.') : p.slice(0, i);
};

/** fnmatch: * ? [abc] [!a-z], as find -name and globs use it. */
export function globToRegExp(glob, flags = '') {
  let re = '';
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i];
    if (c === '*') re += '[^/]*';
    else if (c === '?') re += '[^/]';
    else if (c === '[') {
      const end = glob.indexOf(']', i + 2);
      if (end < 0) { re += '\\['; continue; }
      let body = glob.slice(i + 1, end);
      if (body[0] === '!') body = `^${body.slice(1)}`;
      re += `[${body.replace(/\\/g, '\\\\')}]`;
      i = end;
    } else if (c === '\\' && i + 1 < glob.length) {
      re += glob[++i].replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
    } else {
      re += c.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
    }
  }
  return new RegExp(`^${re}$`, flags);
}

/**
 * grep's basic regular expressions (BRE) as extended ones: in a BRE `\|`
 * `\(` `\)` `\{` `\}` `\+` `\?` are the operators and the bare characters
 * are literal. Agents write `grep "a\|b"` constantly.
 */
export function breToEre(bre) {
  let out = '';
  for (let i = 0; i < bre.length; i++) {
    const c = bre[i];
    if (c === '\\' && i + 1 < bre.length) {
      const n = bre[++i];
      out += '|(){}+?'.includes(n) ? n : `\\${n}`;
    } else if ('|(){}+?'.includes(c)) {
      out += `\\${c}`;
    } else {
      out += c;
    }
  }
  return out;
}

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** A word as sh would need it quoted. */
export const shQuote = (w) => (/^[A-Za-z0-9_@%+=:,./-]+$/.test(w) ? w : `'${String(w).replace(/'/g, "'\\''")}'`);

/** getopt: combined short flags (-rn), values (-n 5, -n5, --include=x). */
function getopt(args, { bool = '', value = '', long = {}, numeric = false } = {}) {
  const flags = {};
  const operands = [];
  const put = (k, v) => {
    if (Array.isArray(flags[k])) flags[k].push(v);
    else if (flags[k] !== undefined && typeof flags[k] !== 'boolean') flags[k] = [flags[k], v];
    else flags[k] = v;
  };
  for (let k = 0; k < args.length; k++) {
    const a = args[k];
    if (a === '--') { operands.push(...args.slice(k + 1)); break; }
    if (a.startsWith('--') && a.length > 2) {
      const [name, inline] = a.slice(2).split(/=(.*)/s);
      const kind = long[name];
      if (!kind) throw new Error(`unrecognized option '--${name}'`);
      if (kind === 'bool') flags[name] = true;
      else if (inline !== undefined) put(name, inline);
      else if (k + 1 < args.length) put(name, args[++k]);
      else throw new Error(`option '--${name}' requires an argument`);
      continue;
    }
    if (numeric && /^-\d+$/.test(a)) { flags.n = a.slice(1); continue; }
    if (a.startsWith('-') && a.length > 1) {
      for (let j = 1; j < a.length; j++) {
        const f = a[j];
        if (value.includes(f)) {
          const rest = a.slice(j + 1);
          if (rest) put(f, rest);
          else if (k + 1 < args.length) put(f, args[++k]);
          else throw new Error(`option requires an argument -- '${f}'`);
          break;
        }
        if (!bool.includes(f)) throw new Error(`invalid option -- '${f}'`);
        flags[f] = true;
      }
      continue;
    }
    operands.push(a);
  }
  return { flags, operands };
}

const human = (n) => {
  const units = ['', 'K', 'M', 'G', 'T'];
  let v = n;
  let u = 0;
  while (v >= 1024 && u < units.length - 1) { v /= 1024; u++; }
  return u === 0 ? String(n) : `${v < 10 ? v.toFixed(1) : Math.round(v)}${units[u]}`;
};

const lsDate = (secs) => {
  const d = new Date(secs * 1000);
  const mon = d.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' });
  const recent = Math.abs(Date.now() - d.getTime()) < 180 * 86400e3;
  const time = recent ? d.toISOString().slice(11, 16) : ` ${d.getUTCFullYear()}`;
  return `${mon} ${String(d.getUTCDate()).padStart(2)} ${time}`;
};

const splitLines = (text) => {
  if (!text) return [];
  const lines = text.split('\n');
  if (lines.at(-1) === '') lines.pop();
  return lines;
};
const joinLines = (lines) => (lines.length ? `${lines.join('\n')}\n` : '');

/* Unified diff (Myers, O((N+M)D)) with three lines of context. */
/**
 * The line-by-line edit script from a to b (Myers): [[' ' | '-' | '+', line]],
 * or null when the texts are too different to align usefully. Shared by
 * unifiedDiff and the editor's highlight of what an agent changed.
 */
export function diffOps(a, b) {
  const A = splitLines(a);
  const B = splitLines(b);
  const n = A.length;
  const m = B.length;
  const max = n + m;
  const v = new Map([[1, 0]]);
  const trace = [];
  let found = false;
  for (let d = 0; d <= max && !found; d++) {
    trace.push(new Map(v));
    for (let k = -d; k <= d; k += 2) {
      let x = k === -d || (k !== d && (v.get(k - 1) ?? -1) < (v.get(k + 1) ?? -1)) ? (v.get(k + 1) ?? 0) : (v.get(k - 1) ?? 0) + 1;
      let y = x - k;
      while (x < n && y < m && A[x] === B[y]) { x++; y++; }
      v.set(k, x);
      if (x >= n && y >= m) { found = true; break; }
    }
    if (d > 20000) return null; // too different to align usefully
  }
  // Walk back to an edit script: ' ', '-', '+'.
  const ops = [];
  let x = n;
  let y = m;
  for (let d = trace.length - 1; d >= 0; d--) {
    const vd = trace[d];
    const k = x - y;
    const prevK = k === -d || (k !== d && (vd.get(k - 1) ?? -1) < (vd.get(k + 1) ?? -1)) ? k + 1 : k - 1;
    const prevX = vd.get(prevK) ?? 0;
    const prevY = prevX - prevK;
    while (x > prevX && y > prevY) { ops.push([' ', A[--x]]); y--; }
    if (d > 0) {
      if (x === prevX) ops.push(['+', B[--y]]);
      else ops.push(['-', A[--x]]);
    }
  }
  ops.reverse();
  return ops;
}

export function unifiedDiff(a, b, nameA, nameB, context = 3) {
  const ops = diffOps(a, b);
  if (ops === null) return null; // too different to show usefully
  if (!ops.some(([o]) => o !== ' ')) return '';
  // Group into hunks.
  const out = [`--- ${nameA}`, `+++ ${nameB}`];
  let i = 0;
  let ai = 1;
  let bi = 1;
  const pos = ops.map(([o]) => {
    const p = [ai, bi];
    if (o !== '+') ai++;
    if (o !== '-') bi++;
    return p;
  });
  while (i < ops.length) {
    while (i < ops.length && ops[i][0] === ' ') i++;
    if (i >= ops.length) break;
    let start = Math.max(0, i - context);
    let end = i;
    let lastChange = i;
    while (end < ops.length) {
      if (ops[end][0] !== ' ') lastChange = end;
      if (end - lastChange > context * 2) break;
      end++;
    }
    end = Math.min(ops.length, lastChange + context + 1);
    const slice = ops.slice(start, end);
    const aLen = slice.filter(([o]) => o !== '+').length;
    const bLen = slice.filter(([o]) => o !== '-').length;
    const [aStart, bStart] = pos[start];
    out.push(`@@ -${aLen ? aStart : aStart - 1},${aLen} +${bLen ? bStart : bStart - 1},${bLen} @@`);
    for (const [o, line] of slice) out.push(`${o}${line}`);
    i = end;
  }
  return `${out.join('\n')}\n`;
}

/* ───────────────────────── sed ───────────────────────── */

function parseSed(script, ere) {
  const cmds = [];
  let i = 0;
  const toRe = (src, flags) => new RegExp(ere ? src : breToEre(src), flags);
  const readDelimited = (delim) => {
    let s = '';
    while (i < script.length && script[i] !== delim) {
      if (script[i] === '\\' && script[i + 1] === delim) { s += delim; i += 2; continue; }
      if (script[i] === '\\' && i + 1 < script.length) { s += script[i] + script[i + 1]; i += 2; continue; }
      if (script[i] === '\n' && delim !== '\n') throw new Error('unterminated `s\' command');
      s += script[i++];
    }
    if (script[i] !== delim) throw new Error(`unterminated address or command near "${script.slice(0, 40)}"`);
    i++;
    return s;
  };
  const readAddr = () => {
    if (/\d/.test(script[i] ?? '')) {
      let n = '';
      while (/\d/.test(script[i] ?? '')) n += script[i++];
      return { line: Number(n) };
    }
    if (script[i] === '$') { i++; return { last: true }; }
    if (script[i] === '/' || script[i] === '\\') {
      const delim = script[i] === '\\' ? script[++i] : '/';
      i++;
      const src = readDelimited(delim);
      let flags = '';
      if (script[i] === 'I') { flags = 'i'; i++; }
      return { re: toRe(src, flags) };
    }
    return null;
  };
  while (i < script.length) {
    while (/[\s;]/.test(script[i] ?? '')) i++;
    if (i >= script.length) break;
    const a1 = readAddr();
    let a2 = null;
    if (a1 && script[i] === ',') { i++; a2 = readAddr(); if (!a2) throw new Error('unexpected `,\''); }
    while (script[i] === ' ') i++;
    let negate = false;
    if (script[i] === '!') { negate = true; i++; while (script[i] === ' ') i++; }
    const c = script[i++];
    const cmd = { a1, a2, negate, c, inRange: false };
    if (c === 's') {
      const delim = script[i++];
      const find = readDelimited(delim);
      const repl = readDelimited(delim);
      let flags = '';
      while (/[gpiIe0-9]/.test(script[i] ?? '')) flags += script[i++];
      const nth = Number((flags.match(/\d+/) ?? ['0'])[0]);
      cmd.re = toRe(find, `${flags.includes('g') ? 'g' : ''}${/[iI]/.test(flags) ? 'i' : ''}`);
      cmd.nth = nth;
      cmd.print = flags.includes('p');
      cmd.repl = repl;
    } else if (c === 'a' || c === 'i' || c === 'c') {
      if (script[i] === '\\') i++;
      if (script[i] === '\n') i++;
      while (script[i] === ' ') i++;
      let text = '';
      while (i < script.length && script[i] !== '\n') text += script[i++];
      cmd.text = text;
    } else if (c === 'y') {
      const delim = script[i++];
      const from = readDelimited(delim);
      const to = readDelimited(delim);
      if ([...from].length !== [...to].length) throw new Error('strings for `y\' command are different lengths');
      cmd.from = [...from];
      cmd.to = [...to];
    } else if (!'pdq=n'.includes(c ?? '')) {
      throw new Error(`unknown command: \`${c ?? ''}'`);
    }
    cmds.push(cmd);
  }
  return cmds;
}

function sedReplacement(repl, match, groups) {
  let out = '';
  for (let i = 0; i < repl.length; i++) {
    const c = repl[i];
    if (c === '\\' && i + 1 < repl.length) {
      const n = repl[++i];
      if (/\d/.test(n)) out += groups[Number(n) - 1] ?? (n === '0' ? match : '');
      else if (n === 'n') out += '\n';
      else if (n === 't') out += '\t';
      else out += n;
    } else if (c === '&') {
      out += match;
    } else {
      out += c;
    }
  }
  return out;
}

export function runSed(cmds, text, { quiet = false } = {}) {
  const lines = splitLines(text);
  const out = [];
  const matches = (addr, idx, line) => (addr.line !== undefined ? idx + 1 === addr.line : addr.last ? idx === lines.length - 1 : addr.re.test(line));
  for (let idx = 0; idx < lines.length; idx++) {
    let line = lines[idx];
    let deleted = false;
    const after = [];
    let quit = false;
    for (const cmd of cmds) {
      let hit = true;
      if (cmd.a1) {
        if (cmd.a2) {
          if (!cmd.inRange && matches(cmd.a1, idx, line)) {
            cmd.inRange = true;
            hit = true;
            // A line-number end already passed closes the range at once.
            if (cmd.a2.line !== undefined && cmd.a2.line <= idx + 1) cmd.inRange = false;
          } else if (cmd.inRange) {
            hit = true;
            if (matches(cmd.a2, idx, line)) cmd.inRange = false;
          } else {
            hit = false;
          }
        } else {
          if (cmd.a1.re) cmd.a1.re.lastIndex = 0;
          hit = matches(cmd.a1, idx, line);
        }
      }
      if (cmd.negate) hit = !hit;
      if (!hit) continue;
      if (cmd.c === 's') {
        // s/a/b/ first match, /g all, /N the Nth, /Ng the Nth onwards.
        const g = new RegExp(cmd.re.source, `g${cmd.re.flags.replace('g', '')}`);
        let n = 0;
        let changed = false;
        line = line.replace(g, (m, ...rest) => {
          n++;
          const want = cmd.nth ? (cmd.re.global ? n >= cmd.nth : n === cmd.nth) : (cmd.re.global || n === 1);
          if (!want) return m;
          changed = true;
          return sedReplacement(cmd.repl, m, rest.slice(0, rest.findIndex((x) => typeof x === 'number')));
        });
        if (changed && cmd.print) out.push(line);
      } else if (cmd.c === 'p') {
        out.push(line);
      } else if (cmd.c === 'd') {
        deleted = true;
        break;
      } else if (cmd.c === 'q') {
        quit = true;
        break;
      } else if (cmd.c === '=') {
        out.push(String(idx + 1));
      } else if (cmd.c === 'a') {
        after.push(cmd.text);
      } else if (cmd.c === 'i') {
        out.push(cmd.text);
      } else if (cmd.c === 'c') {
        if (!cmd.a2 || !cmd.inRange) out.push(cmd.text);
        deleted = true;
        break;
      } else if (cmd.c === 'y') {
        line = [...line].map((ch) => { const k = cmd.from.indexOf(ch); return k < 0 ? ch : cmd.to[k]; }).join('');
      } else if (cmd.c === 'n') {
        if (!quiet) out.push(line);
        idx++;
        if (idx >= lines.length) { deleted = true; break; }
        line = lines[idx];
      }
    }
    if (!deleted && !quiet) out.push(line);
    out.push(...after);
    if (quit) break;
  }
  return joinLines(out);
}

/* ───────────────────────── the shell ───────────────────────── */

/**
 * io: how the shell reaches the site. Every call answers { ok, ... } or
 * { ok: false, hint } the way window.cic does.
 *   list(path) read(path) readMany(paths) write(path, content, expect)
 *   writeMany([{ path, content, expect }]) mkdir move copy remove
 *   removeTree(path, confirm) grep(opts) find(opts) history(path)
 *   versionAt(path, rev) command(tool, args, confirm) eval(code) clone(o)
 *   request(path, opts) query(sql, write) siteUrl
 */
export function createShell(rawIo, { cwd = '/' } = {}) {
  // vars: the shell's variables (NAME=value, for, read), kept between calls
  // like cwd; last: $?.
  const state = { cwd, vars: new Map(), last: 0 };
  // Listings are remembered within one command line (cp and mv stat the
  // same folder more than once) and forgotten the moment anything writes.
  const listed = new Map();
  const writes = ['write', 'writeMany', 'mkdir', 'move', 'copy', 'remove', 'removeTree', 'removeEmptyDir', 'clone', 'command', 'eval', 'query'];
  const io = { ...rawIo };
  io.list = (p) => {
    if (!listed.has(p)) listed.set(p, rawIo.list(p));
    return listed.get(p);
  };
  for (const name of writes) {
    if (rawIo[name]) io[name] = async (...a) => { listed.clear(); try { return await rawIo[name](...a); } finally { listed.clear(); } };
  }

  const fail = (code, err) => ({ code, out: '', err: err.endsWith('\n') ? err : `${err}\n` });
  // A refusal that only confirmation lifts, as data - never recognised by
  // matching text, which the site's own output could imitate. `command` is
  // exactly what confirming would run: that one command, not the whole line.
  const gated = (command, why) => ({ code: 1, out: '', err: `${why}\n`, needsConfirm: [command] });
  const ok = (out = '') => ({ code: 0, out, err: '' });

  async function stat(abs) {
    if (abs === '/') return { ok: true, dir: true, size: 0, mtime: 0, mode: 'drwxr-xr-x', name: '/' };
    const r = await io.list(dirName(abs));
    if (!r.ok) return { ok: false, hint: r.hint };
    const e = r.entries.find((x) => x.name === baseName(abs));
    return e ? { ok: true, ...e } : { ok: false, missing: true, hint: 'No such file or directory' };
  }

  // Display form of a site path found under `given` (as the user typed it).
  const shown = (given, givenAbs, abs) => {
    if (abs === givenAbs) return given;
    const rest = abs.slice(givenAbs === '/' ? 1 : givenAbs.length + 1);
    return given === '.' && state.cwd === givenAbs ? `./${rest}` : `${given.replace(/\/+$/, '')}/${rest}`;
  };
  const relToCwd = (abs) => {
    if (state.cwd === '/') return abs.slice(1);
    return abs.startsWith(`${state.cwd}/`) ? abs.slice(state.cwd.length + 1) : abs;
  };

  async function expandGlobs(words) {
    const out = [];
    for (const w of words) {
      if (!w.glob || !GLOB.test(w.v) || w.v.startsWith('-')) { out.push(w.v); continue; }
      const dirPart = w.v.includes('/') ? w.v.slice(0, w.v.lastIndexOf('/') + 1) : '';
      const pattern = w.v.slice(dirPart.length);
      if (GLOB.test(dirPart) || !pattern) { out.push(w.v); continue; }
      const r = await io.find({ under: resolvePath(state.cwd, dirPart || '.'), maxdepth: 1, name: pattern });
      const names = (r.ok ? r.entries : [])
        .map((e) => baseName(e.path))
        .filter((n) => pattern.startsWith('.') || !n.startsWith('.'))
        .sort();
      if (!names.length) out.push(w.v);
      else out.push(...names.map((n) => `${dirPart}${n}`));
    }
    return out;
  }

  async function readInputs(files, stdin, name) {
    // [{ name, text }] for each operand, '-' or no operand meaning stdin.
    if (!files.length) return { inputs: [{ name: '(standard input)', text: stdin ?? '' }], errors: '' };
    const abs = files.filter((f) => f !== '-').map((f) => resolvePath(state.cwd, f));
    const r = abs.length ? await io.readMany(abs) : { files: {}, errors: {} };
    const inputs = [];
    let errors = '';
    for (const f of files) {
      if (f === '-') { inputs.push({ name: '(standard input)', text: stdin ?? '' }); continue; }
      const p = resolvePath(state.cwd, f);
      if (r.files[p] !== undefined) inputs.push({ name: f, text: r.files[p], path: p });
      else {
        const hint = String(r.errors[p] ?? 'No such file or directory');
        errors += `${name}: ${f}: ${/director/i.test(hint) ? 'Is a directory' : /not.?found|no such|does not exist/i.test(hint) ? 'No such file or directory' : hint}\n`;
      }
    }
    return { inputs, errors };
  }

  async function writeFile(abs, content, { append = false } = {}) {
    let expect = '';
    if (append) {
      const cur = await io.read(abs);
      if (cur.ok) { content = cur.content + content; expect = cur.revision; }
    }
    return io.write(abs, content, expect);
  }

  const lintNote = (res) => (res.syntaxErrors
    ? Object.entries(res.syntaxErrors).map(([p, e]) => `cic.sh: ${p}: PHP syntax error: ${e}\n`).join('')
    : '');

  /* The commands. Each: async (args, stdin, ctx) -> { code, out, err } */
  const commands = {
    async pwd() { return ok(`${state.cwd}\n`); },
    async cd(args) {
      const target = resolvePath(state.cwd, args[0] ?? '/');
      const s = await stat(target);
      if (!s.ok) return fail(1, `cd: ${args[0]}: No such file or directory`);
      if (!s.dir) return fail(1, `cd: ${args[0]}: Not a directory`);
      state.cwd = target;
      return ok();
    },
    async echo(args) {
      let n = false;
      let e = false;
      while (args[0] && /^-[neE]+$/.test(args[0])) {
        if (args[0].includes('n')) n = true;
        if (args[0].includes('e')) e = true;
        args = args.slice(1);
      }
      let text = args.join(' ');
      if (e) text = text.replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\\\/g, '\\');
      return ok(n ? text : `${text}\n`);
    },
    async printf(args) {
      if (!args.length) return fail(2, 'printf: usage: printf format [arguments]');
      const esc = (s) => s.replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\\\/g, '\\');
      const fmt = args[0];
      let rest = args.slice(1);
      let out = '';
      do {
        let used = 0;
        out += esc(fmt).replace(/%([-0-9.]*)([sdf%])/g, (m, w, t) => {
          if (t === '%') return '%';
          const v = rest[used++] ?? '';
          if (t === 'd') return String(Math.trunc(Number(v) || 0));
          if (t === 'f') return (Number(v) || 0).toFixed(Number(w.split('.')[1] ?? 6));
          return v;
        });
        rest = rest.slice(used);
        if (!used) break;
      } while (rest.length);
      return ok(out);
    },
    async ls(args) {
      const { flags, operands } = getopt(args, { bool: 'alhA1RtSrdF' });
      const targets = operands.length ? operands : ['.'];
      let out = '';
      let err = '';
      const fmt = (e, name) => {
        const shownName = `${name}${flags.F && e.dir ? '/' : ''}`;
        if (!flags.l) return shownName;
        const mode = `${e.dir ? 'd' : '-'}${String(e.mode ?? '').replace(/^[-d]/, '').padEnd(9, '-').slice(0, 9)}`;
        return `${mode} ${String(flags.h ? human(e.size) : e.size).padStart(8)} ${lsDate(e.mtime)} ${shownName}`;
      };
      const sortEntries = (list) => {
        const s = [...list];
        if (flags.t) s.sort((a, b) => b.mtime - a.mtime);
        else if (flags.S) s.sort((a, b) => b.size - a.size);
        else s.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
        return flags.r ? s.reverse() : s;
      };
      const visible = (e) => flags.a || flags.A || !e.name.startsWith('.');
      const dirs = [];
      const files = [];
      // One request per name in the usual case: list it as a folder, and
      // only if that fails ask whether it is a file. All names at once.
      const looked = await Promise.all(targets.map(async (t) => {
        const abs = resolvePath(state.cwd, t);
        if (!flags.d) {
          const l = await io.list(abs);
          if (l.ok) return { t, abs, dir: true };
        }
        return { t, abs, s: await stat(abs) };
      }));
      for (const x of looked) {
        if (x.dir) { dirs.push(x); continue; }
        if (!x.s.ok) { err += `ls: cannot access '${x.t}': No such file or directory\n`; continue; }
        if (x.s.dir && !flags.d) dirs.push(x);
        else files.push(fmt(x.s, x.t));
      }
      if (files.length) out += `${files.join('\n')}\n`;
      const many = targets.length > 1 || flags.R;
      for (const [k, { t, abs }] of dirs.entries()) {
        if (flags.R) {
          const r = await io.find({ under: abs });
          if (!r.ok) { err += `ls: ${t}: ${r.hint}\n`; continue; }
          const byDir = new Map([[abs, []]]);
          for (const e of r.entries) {
            const parent = dirName(e.path);
            if (!byDir.has(parent)) byDir.set(parent, []);
            byDir.get(parent).push({ ...e, name: baseName(e.path) });
            if (e.dir && !byDir.has(e.path)) byDir.set(e.path, []);
          }
          const blocks = [];
          for (const [d, list] of byDir) {
            const entries = sortEntries(list.filter(visible)).map((e) => fmt(e, e.name));
            blocks.push(`${shown(t, abs, d)}:\n${entries.join('\n')}${entries.length ? '\n' : ''}`);
          }
          out += blocks.join('\n');
          continue;
        }
        const r = await io.list(abs);
        if (!r.ok) { err += `ls: ${t}: ${r.hint}\n`; continue; }
        const entries = sortEntries(r.entries.filter(visible));
        if (many) out += `${k || files.length ? '\n' : ''}${t}:\n`;
        const lines = entries.map((e) => fmt(e, e.name));
        if (flags.a) lines.unshift(...(flags.l ? [] : ['.', '..']));
        out += joinLines(lines);
      }
      return { code: err ? 2 : 0, out, err };
    },
    async cat(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'nAbsvE' });
      const { inputs, errors } = await readInputs(operands, stdin, 'cat');
      let text = inputs.map((x) => x.text).join('');
      if (flags.n) text = joinLines(splitLines(text).map((l, i) => `${String(i + 1).padStart(6)}\t${l}`));
      return { code: errors ? 1 : 0, out: text, err: errors };
    },
    async head(args, stdin) { return headTail('head', args, stdin); },
    async tail(args, stdin) { return headTail('tail', args, stdin); },
    async wc(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'lwcm' });
      const { inputs, errors } = await readInputs(operands, stdin, 'wc');
      const all = !flags.l && !flags.w && !flags.c && !flags.m;
      const rows = inputs.map((x) => {
        const cols = [];
        if (all || flags.l) cols.push((x.text.match(/\n/g) ?? []).length);
        if (all || flags.w) cols.push((x.text.match(/\S+/g) ?? []).length);
        if (all || flags.c || flags.m) cols.push(flags.m ? [...x.text].length : new TextEncoder().encode(x.text).length);
        return { cols, name: operands.length ? x.name : '' };
      });
      if (rows.length > 1) rows.push({ cols: rows[0].cols.map((_, k) => rows.reduce((s, r) => s + r.cols[k], 0)), name: 'total' });
      const width = Math.max(1, ...rows.flatMap((r) => r.cols.map((c) => String(c).length)));
      const out = joinLines(rows.map((r) => `${r.cols.map((c) => String(c).padStart(rows.length > 1 || operands.length ? width : 0)).join(' ')}${r.name ? ` ${r.name}` : ''}`));
      return { code: errors ? 1 : 0, out, err: errors };
    },
    async grep(args, stdin) { return grep(args, stdin); },
    async egrep(args, stdin) { return grep(['-E', ...args], stdin); },
    async fgrep(args, stdin) { return grep(['-F', ...args], stdin); },
    async rg(args, stdin) {
      // ripgrep's defaults: recursive, line numbers, regex, smart case.
      const hasUpper = args.some((a) => !a.startsWith('-') && /[A-Z]/.test(a));
      return grep(['-rnE', ...(hasUpper ? [] : ['-i']), ...args.map((a) => (a === '-g' ? '--include' : a))], stdin);
    },
    // -exec CMD {} \; runs CMD once per path, -exec CMD {} + once with all of
    // them; -delete is rm of each. The list comes first, then the runs, as
    // xargs does them (bounded the same way).
    async find(args, stdin, ctx) {
      const at = args.findIndex((a) => a === '-exec' || a === '-execdir' || a === '-delete');
      if (at < 0) return find(args);
      let template;
      let batch;
      let rest;
      if (args[at] === '-delete') {
        template = ['rm', '-f', '{}'];
        batch = true;
        rest = [...args.slice(0, at), ...args.slice(at + 1)];
      } else {
        const end = args.findIndex((a, k) => k > at && (a === ';' || a === '+'));
        if (end < 0) return fail(1, "find: missing argument to `-exec'");
        template = args.slice(at + 1, end);
        batch = args[end] === '+';
        rest = [...args.slice(0, at), ...args.slice(end + 1)];
      }
      if (!template.length) return fail(1, "find: -exec needs a command");
      const listed = await find(rest);
      if (listed.code) return listed;
      const paths = splitLines(listed.out);
      if (!paths.length) return ok();
      const runs = batch
        ? [template.flatMap((a) => (a === '{}' ? paths : [a]))]
        : paths.map((p) => template.map((a) => a.split('{}').join(p)));
      if (runs.length > 100) return fail(1, `find: -exec would run ${runs.length} times; at most 100 - end it with {} + to run once, or narrow the search`);
      let out = '';
      let err = '';
      let code = 0;
      const needsConfirm = [];
      for (const argv of runs) {
        const r = await runArgv(argv, null, ctx);
        out += r.out;
        err += r.err;
        if (r.code) code = 1;
        if (r.needsConfirm) needsConfirm.push(...r.needsConfirm);
      }
      return { code, out, err, ...(needsConfirm.length ? { needsConfirm } : {}) };
    },
    async tree(args) {
      const { flags, operands } = getopt(args, { bool: 'adf', value: 'L' });
      const t = operands[0] ?? '.';
      const abs = resolvePath(state.cwd, t);
      const r = await io.find({ under: abs, maxdepth: flags.L ? Number(flags.L) : undefined, type: flags.d ? 'd' : undefined });
      if (!r.ok) return fail(2, `tree: ${t}: ${r.hint}`);
      const kids = new Map();
      for (const e of r.entries) {
        if (!flags.a && baseName(e.path).startsWith('.')) continue;
        const p = dirName(e.path);
        if (!kids.has(p)) kids.set(p, []);
        kids.get(p).push(e);
      }
      const lines = [t];
      let nd = 0;
      let nf = 0;
      const walk = (dir, prefix) => {
        const list = (kids.get(dir) ?? []).sort((a, b) => (a.path < b.path ? -1 : 1));
        list.forEach((e, k) => {
          const last = k === list.length - 1;
          lines.push(`${prefix}${last ? '└── ' : '├── '}${flags.f ? shown(t, abs, e.path) : baseName(e.path)}`);
          if (e.dir) { nd++; walk(e.path, `${prefix}${last ? '    ' : '│   '}`); } else nf++;
        });
      };
      walk(abs, '');
      lines.push('', `${nd} directories${flags.d ? '' : `, ${nf} files`}`);
      return { code: 0, out: joinLines(lines), err: r.truncated ? 'tree: more entries than can be listed; name a folder, or -L\n' : '' };
    },
    async du(args) {
      const { flags, operands } = getopt(args, { bool: 'shacbk', value: 'd', long: { 'max-depth': 'value', summarize: 'bool', 'apparent-size': 'bool' } });
      const depth = flags.s || flags.summarize ? 0 : flags.d ?? flags['max-depth'];
      const size = (b) => (flags.h ? human(b) : flags.b ? String(b) : String(Math.ceil(b / 1024)));
      let out = '';
      let err = '';
      let total = 0;
      // One request per name, all at once: find on a file answers its size.
      const names = operands.length ? operands : ['.'];
      const found = await Promise.all(names.map((t) => io.find({ under: resolvePath(state.cwd, t), all: true, limit: depth === 0 ? 1 : 5000 })));
      names.forEach((t, k) => {
        const abs = resolvePath(state.cwd, t);
        const r = found[k];
        if (!r.ok) { err += /no such/i.test(r.hint ?? '') ? `du: cannot access '${t}': No such file or directory\n` : `du: ${t}: ${r.hint}\n`; return; }
        total += r.bytes;
        if (depth !== 0 && r.entries.length) {
          const sums = new Map();
          const levels = depth === undefined ? Infinity : Number(depth);
          for (const e of r.entries) {
            if (e.dir) continue;
            let d = dirName(e.path);
            while (d.length >= abs.length && d !== dirName(abs)) {
              sums.set(d, (sums.get(d) ?? 0) + e.size);
              if (d === abs) break;
              d = dirName(d);
            }
          }
          const rows = [...sums].filter(([d]) => d !== abs)
            .filter(([d]) => d.slice(abs.length).split('/').filter(Boolean).length <= levels)
            .sort((a, b) => (a[0] < b[0] ? -1 : 1));
          for (const [d, b] of rows) out += `${size(b)}\t${shown(t, abs, d)}\n`;
          if (r.truncated) err += `du: ${t}: more than 5000 entries: the per-folder lines are partial, the total is whole\n`;
        }
        out += `${size(r.bytes)}\t${t}\n`;
      });
      if (flags.c) out += `${size(total)}\ttotal\n`;
      return { code: err ? 1 : 0, out, err };
    },
    async stat(args) {
      const { operands } = getopt(args, { bool: 'Lt', value: 'cf' });
      let out = '';
      let err = '';
      for (const t of operands) {
        const s = await stat(resolvePath(state.cwd, t));
        if (!s.ok) { err += `stat: cannot statx '${t}': No such file or directory\n`; continue; }
        out += `  File: ${t}\n  Size: ${s.size}\t${s.dir ? 'directory' : 'regular file'}\nAccess: (${s.dir ? 'd' : '-'}${String(s.mode).slice(1)})\nModify: ${new Date(s.mtime * 1000).toISOString()}\n`;
      }
      return { code: err ? 1 : 0, out, err };
    },
    async mkdir(args) {
      const { flags, operands } = getopt(args, { bool: 'pv', value: 'm' });
      if (!operands.length) return fail(1, 'mkdir: missing operand');
      let err = '';
      for (const t of operands) {
        const abs = resolvePath(state.cwd, t);
        if (!flags.p) {
          const s = await stat(abs);
          if (s.ok) { err += `mkdir: cannot create directory '${t}': File exists\n`; continue; }
        }
        const r = await io.mkdir(abs);
        if (!r.ok && !(flags.p && /exist/i.test(r.hint ?? ''))) err += `mkdir: cannot create directory '${t}': ${r.hint}\n`;
      }
      return { code: err ? 1 : 0, out: '', err };
    },
    async touch(args) {
      const { operands } = getopt(args, { bool: 'acm' });
      if (!operands.length) return fail(1, 'touch: missing file operand');
      let err = '';
      for (const t of operands) {
        const abs = resolvePath(state.cwd, t);
        const s = await stat(abs);
        if (s.ok) continue; // times cannot be set from here; an existing file is left as it is
        const r = await io.write(abs, '', '');
        if (!r.ok) err += `touch: cannot touch '${t}': ${r.hint}\n`;
      }
      return { code: err ? 1 : 0, out: '', err };
    },
    async cp(args) { return copyMove('cp', args); },
    async mv(args) { return copyMove('mv', args); },
    async rm(args, _stdin, ctx) {
      const { flags, operands } = getopt(args, { bool: 'rRfidv', long: { recursive: 'bool', force: 'bool' } });
      const recursive = flags.r || flags.R || flags.recursive;
      const force = flags.f || flags.force;
      const gatedHere = [];
      if (!operands.length) return force ? ok() : fail(1, 'rm: missing operand');
      let err = '';
      for (const t of operands) {
        const abs = resolvePath(state.cwd, t);
        if (abs === '/' || abs === state.cwd && (t === '.' || t === './')) { err += `rm: refusing to remove '${t}'\n`; continue; }
        const s = await stat(abs);
        if (!s.ok) { if (!force) err += `rm: cannot remove '${t}': No such file or directory\n`; continue; }
        if (s.dir) {
          if (!recursive && !flags.d) { err += `rm: cannot remove '${t}': Is a directory\n`; continue; }
          const r = await io.removeTree(abs, ctx.confirm);
          if (!r.ok) {
            if (r.error === 'needs_confirm') {
              // The absolute path: cd may change before it is confirmed.
              gatedHere.push(`rm -r ${shQuote(abs)}`);
              err += `rm: '${t}' is a folder: deleting it needs confirmation (its files go to the bin and can be restored)\n`;
            } else {
              err += `rm: cannot remove '${t}': ${r.hint}\n`;
            }
          }
          continue;
        }
        const r = await io.remove(abs);
        if (!r.ok) err += `rm: cannot remove '${t}': ${r.hint}\n`;
      }
      return { code: err ? 1 : 0, out: '', err, ...(gatedHere.length ? { needsConfirm: gatedHere } : {}) };
    },
    async rmdir(args) {
      const { operands } = getopt(args, { bool: 'pv' });
      let err = '';
      for (const t of operands) {
        const abs = resolvePath(state.cwd, t);
        // The host removes it only if it is empty at that moment.
        const d = await io.removeEmptyDir(abs);
        if (!d.ok) err += `rmdir: failed to remove '${t}': ${/not empty/i.test(d.hint ?? '') ? 'Directory not empty' : /no such/i.test(d.hint ?? '') ? 'No such file or directory' : d.hint}\n`;
      }
      return { code: err ? 1 : 0, out: '', err };
    },
    async sed(args, stdin) {
      // -i and -i.bak (the suffix is attached, and no backup is kept: every
      // save is a version already), taken out before getopt sees them.
      let inPlace = false;
      const rest = [];
      for (let k = 0; k < args.length; k++) {
        const a = args[k];
        // BSD's form, sed -i '' ...: the empty suffix is its own word.
        if (a === '-i' && args[k + 1] === '') { inPlace = true; k++; continue; }
        if (/^-i/.test(a) || a === '--in-place' || a.startsWith('--in-place=')) inPlace = true;
        else if (/^-[nErsuz]+i/.test(a)) { inPlace = true; rest.push(a.slice(0, a.indexOf('i'))); } else rest.push(a);
      }
      const parsed = getopt(rest, { bool: 'nErsuz', value: 'ef', long: { 'in-place': 'bool', quiet: 'bool', 'regexp-extended': 'bool', expression: 'value' } });
      const scripts = [parsed.flags.e, parsed.flags.expression].flat().filter((x) => x !== undefined);
      const files = [...parsed.operands];
      if (!scripts.length) {
        if (!files.length) return fail(1, 'sed: no script given');
        scripts.push(files.shift());
      }
      const ere = parsed.flags.E || parsed.flags.r || parsed.flags['regexp-extended'];
      const quiet = parsed.flags.n || parsed.flags.quiet;
      let cmds;
      try {
        cmds = parseSed(scripts.join('\n'), ere);
      } catch (e) {
        return fail(1, `sed: -e expression #1: ${e.message}`);
      }
      if (!inPlace) {
        const { inputs, errors } = await readInputs(files, stdin, 'sed');
        const out = inputs.map((x) => runSed(cmds.map((c) => ({ ...c, inRange: false })), x.text, { quiet })).join('');
        return { code: errors ? 2 : 0, out, err: errors };
      }
      if (!files.length) return fail(1, 'sed: no input files');
      const abs = files.map((f) => resolvePath(state.cwd, f));
      const reads = await Promise.all(abs.map((p) => io.read(p)));
      let err = '';
      const changes = [];
      reads.forEach((r, k) => {
        if (!r.ok) { err += `sed: can't read ${files[k]}: No such file or directory\n`; return; }
        const next = runSed(cmds.map((c) => ({ ...c, inRange: false })), r.content, { quiet });
        if (next !== r.content) changes.push({ path: abs[k], content: next, expect: r.revision });
      });
      if (changes.length) {
        // One version for all of them, and each checked against what was read.
        const w = await io.writeMany(changes);
        if (!w.ok) err += `sed: ${w.hint ?? 'could not write'}\n`;
        else err += lintNote(w);
      }
      return { code: err && !/syntax error/.test(err) ? 4 : 0, out: '', err };
    },
    // perl -pi -e 's/a/b/g' FILE..., perl -pe/-ne ..., -0/-0777 for whole files:
    // the one-liners an agent edits with. s///, y/// (tr), print [if|unless
    // /re/], with JavaScript's regular expressions (Perl's, for these).
    async perl(args, stdin) {
      const scripts = [];
      const files = [];
      let inPlace = false;
      let print = false;
      let loop = false;
      let slurp = false;
      for (let k = 0; k < args.length; k++) {
        const a = args[k];
        if (a === '--') { files.push(...args.slice(k + 1)); break; }
        if (/^-[a-zA-Z0-9]/.test(a) && files.length === 0) {
          const flags = a.slice(1);
          for (let j = 0; j < flags.length; j++) {
            const f = flags[j];
            if (f === 'p') { print = true; loop = true; } else if (f === 'n') loop = true;
            else if (f === 'i') { inPlace = true; break; } // -i or -i.bak: no backup, every save is a version
            else if (f === '0') { slurp = true; while (/\d/.test(flags[j + 1] ?? '')) j++; } else if (f === 'e' || f === 'E') {
              const rest = flags.slice(j + 1);
              scripts.push(rest || args[++k]);
              break;
            } else if (f === 'l' || f === 'w' || f === 's' || f === 'C') { /* no effect here */ } else return fail(2, `perl: -${f} is not supported here (-p -n -i -e -0 are)`);
          }
          continue;
        }
        files.push(a);
      }
      if (!scripts.length) return fail(2, "perl: only one-liners run here: perl -pi -e 's/old/new/g' FILE");
      if (!loop) return fail(2, "perl: only -p and -n one-liners run here (perl -pi -e 's/a/b/g' FILE, perl -ne 'print if /re/')");
      let program;
      try {
        program = perlProgram(scripts.join(';'));
      } catch (e) {
        return fail(255, `perl: ${e.message}`);
      }
      const apply = (text) => {
        const records = slurp ? [text] : text.match(/[^\n]*\n|[^\n]+$/g) ?? [];
        let out = '';
        for (const rec of records) {
          // Perl's $ matches before a line's newline; JavaScript's only at the
          // very end - so a line is run without it, and it is put back.
          const nl = !slurp && rec.endsWith('\n') ? '\n' : '';
          const r = program(nl ? rec.slice(0, -1) : rec);
          out += r.printed.replace(/([^\n])$/, nl ? `$1${nl}` : '$1') + (print ? r.text + nl : '');
        }
        return out;
      };
      if (!inPlace) {
        const { inputs, errors } = await readInputs(files, stdin, 'perl');
        return { code: errors ? 2 : 0, out: inputs.map((x) => apply(x.text)).join(''), err: errors };
      }
      if (!files.length) return fail(2, 'perl: -i needs a file');
      const abs = files.map((f) => resolvePath(state.cwd, f));
      const reads = await Promise.all(abs.map((p) => io.read(p)));
      let err = '';
      const changes = [];
      reads.forEach((r, k) => {
        if (!r.ok) { err += `Can't open ${files[k]}: No such file or directory.\n`; return; }
        const next = apply(r.content);
        if (next !== r.content) changes.push({ path: abs[k], content: next, expect: r.revision });
      });
      if (changes.length) {
        const w = await io.writeMany(changes);
        if (!w.ok) err += `perl: ${w.hint ?? 'could not write'}\n`;
        else err += lintNote(w);
      }
      return { code: err && !/syntax error/.test(err) ? 2 : 0, out: '', err };
    },
    // awk [-F sep] [-v var=value] 'program' [file...] (resources/js/awk.js).
    async awk(args, stdin) {
      let fs = null;
      const vars = {};
      let program = null;
      const files = [];
      const unescape = (v) => v.replace(/\\t/g, '\t').replace(/\\n/g, '\n').replace(/\\\\/g, '\\');
      for (let k = 0; k < args.length; k++) {
        const a = args[k];
        if (program === null && a === '--') { program = args[++k] ?? ''; continue; }
        if (program === null && a === '-F') { fs = unescape(args[++k] ?? ' '); continue; }
        if (program === null && a.startsWith('-F')) { fs = unescape(a.slice(2)); continue; }
        if (program === null && (a === '-v' || a.startsWith('-v'))) {
          const kv = a === '-v' ? args[++k] ?? '' : a.slice(2);
          const m = /^([A-Za-z_]\w*)=(.*)$/s.exec(kv);
          if (!m) return fail(2, `awk: -v needs var=value, not "${kv}"`);
          vars[m[1]] = unescape(m[2]);
          continue;
        }
        if (program === null && a === '-f') {
          const r = await io.read(resolvePath(state.cwd, args[++k] ?? ''));
          if (!r.ok) return fail(2, `awk: can't open file ${args[k]}`);
          program = r.content;
          continue;
        }
        if (program === null) { program = a; continue; }
        // var=value among the files is an assignment, as in awk.
        const m = /^([A-Za-z_]\w*)=(.*)$/s.exec(a);
        if (m) vars[m[1]] = m[2]; else files.push(a);
      }
      if (program === null) return fail(2, "usage: awk [-F sep] [-v var=value] 'program' [file ...]");
      const { inputs, errors } = await readInputs(files, stdin, 'awk');
      try {
        const r = runAwk(program, inputs, { fs, vars });
        return { code: errors ? 2 : r.code, out: r.out, err: errors };
      } catch (e) {
        if (e instanceof AwkError) return fail(2, `awk: ${e.message}`);
        throw e;
      }
    },
    async diff(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'uqNawbBr', value: 'U' });
      if (operands.length !== 2) return fail(2, 'diff: two files are needed');
      const { inputs, errors } = await readInputs(operands, stdin, 'diff');
      if (errors) return { code: 2, out: '', err: errors };
      const [a, b] = inputs;
      if (a.text === b.text) return ok();
      if (flags.q) return { code: 1, out: `Files ${operands[0]} and ${operands[1]} differ\n`, err: '' };
      const d = unifiedDiff(a.text, b.text, operands[0], operands[1], flags.U ? Number(flags.U) : 3);
      return d === null ? { code: 1, out: `Files ${operands[0]} and ${operands[1]} differ (too different to show line by line)\n`, err: '' } : { code: 1, out: d, err: '' };
    },
    async sort(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'rnufV', value: 'kt' });
      const { inputs, errors } = await readInputs(operands, stdin, 'sort');
      let lines = inputs.flatMap((x) => splitLines(x.text));
      const key = (l) => {
        if (!flags.k) return l;
        const [start] = String(flags.k).split(',');
        const fields = flags.t ? l.split(flags.t) : l.trim().split(/\s+/);
        return fields.slice(Number(start) - 1).join(flags.t ?? ' ');
      };
      const cmp = flags.n
        ? (a, b) => (parseFloat(key(a)) || 0) - (parseFloat(key(b)) || 0)
        : flags.V
          ? (a, b) => key(a).localeCompare(key(b), undefined, { numeric: true })
          : (a, b) => {
            const x = flags.f ? key(a).toLowerCase() : key(a);
            const y = flags.f ? key(b).toLowerCase() : key(b);
            return x < y ? -1 : x > y ? 1 : 0;
          };
      lines.sort(cmp);
      if (flags.r) lines.reverse();
      if (flags.u) lines = lines.filter((l, k) => k === 0 || cmp(l, lines[k - 1]) !== 0);
      return { code: errors ? 2 : 0, out: joinLines(lines), err: errors };
    },
    async uniq(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'cdui' });
      const { inputs, errors } = await readInputs(operands.slice(0, 1), stdin, 'uniq');
      const groups = [];
      for (const l of splitLines(inputs[0]?.text ?? '')) {
        const last = groups.at(-1);
        if (last && (flags.i ? last.l.toLowerCase() === l.toLowerCase() : last.l === l)) last.n++;
        else groups.push({ l, n: 1 });
      }
      const out = groups.filter((g) => (flags.d ? g.n > 1 : flags.u ? g.n === 1 : true))
        .map((g) => (flags.c ? `${String(g.n).padStart(7)} ${g.l}` : g.l));
      return { code: errors ? 1 : 0, out: joinLines(out), err: errors };
    },
    async cut(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 's', value: 'dfc' });
      const { inputs, errors } = await readInputs(operands, stdin, 'cut');
      const spec = String(flags.f ?? flags.c ?? '');
      if (!spec) return fail(1, 'cut: you must specify a list of bytes, characters, or fields');
      const wanted = (n) => spec.split(',').some((r) => {
        const [a, b] = r.split('-');
        if (b === undefined) return n === Number(a);
        return n >= (a ? Number(a) : 1) && n <= (b ? Number(b) : Infinity);
      });
      const delim = flags.d ?? '\t';
      const out = inputs.flatMap((x) => splitLines(x.text)).flatMap((l) => {
        if (flags.c) return [[...l].filter((_, k) => wanted(k + 1)).join('')];
        if (!l.includes(delim)) return flags.s ? [] : [l];
        return [l.split(delim).filter((_, k) => wanted(k + 1)).join(delim)];
      });
      return { code: errors ? 1 : 0, out: joinLines(out), err: errors };
    },
    async tr(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'ds' });
      const expand = (set) => set.replace(/(.)-(.)/g, (_, a, b) => {
        let s = '';
        for (let c = a.charCodeAt(0); c <= b.charCodeAt(0); c++) s += String.fromCharCode(c);
        return s;
      }).replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace('[:upper:]', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ').replace('[:lower:]', 'abcdefghijklmnopqrstuvwxyz');
      const from = [...expand(operands[0] ?? '')];
      const to = [...expand(operands[1] ?? '')];
      let text = stdin ?? '';
      if (flags.d) text = [...text].filter((c) => !from.includes(c)).join('');
      else text = [...text].map((c) => { const k = from.indexOf(c); return k < 0 ? c : (to[Math.min(k, to.length - 1)] ?? c); }).join('');
      if (flags.s) {
        const squeeze = new Set(flags.d ? to : to.length ? to : from);
        text = [...text].filter((c, k, all) => !(k && c === all[k - 1] && squeeze.has(c))).join('');
      }
      return ok(text);
    },
    async nl(args, stdin) {
      const { inputs, errors } = await readInputs(getopt(args, { value: 'bw' }).operands, stdin, 'nl');
      let n = 0;
      const out = inputs.flatMap((x) => splitLines(x.text)).map((l) => (l.trim() ? `${String(++n).padStart(6)}\t${l}` : l));
      return { code: errors ? 1 : 0, out: joinLines(out), err: errors };
    },
    async tee(args, stdin) {
      const { flags, operands } = getopt(args, { bool: 'a' });
      let err = '';
      for (const t of operands) {
        const r = await writeFile(resolvePath(state.cwd, t), stdin ?? '', { append: flags.a });
        if (!r.ok) err += `tee: ${t}: ${r.hint}\n`;
        else err += lintNote(r);
      }
      return { code: err && !/syntax error/.test(err) ? 1 : 0, out: stdin ?? '', err };
    },
    async xargs(args, stdin, ctx) {
      const f = {};
      let k = 0;
      while (k < args.length && args[k].startsWith('-') && args[k] !== '-') {
        const a = args[k];
        if (/^-[nIL]$/.test(a)) { f[a[1]] = args[k + 1]; k += 2; } else if (/^-[nIL]./.test(a)) { f[a[1]] = a.slice(2); k++; } else { for (const c of a.slice(1)) f[c] = true; k++; }
      }
      const cmd = args.slice(k);
      if (!cmd.length) cmd.push('echo');
      const items = f.I ? splitLines(stdin ?? '') : (stdin ?? '').split(f['0'] ? '\0' : /\s+/).filter(Boolean);
      if (!items.length && f.r) return ok();
      // Bounded: each run is a request to the site's host.
      if (items.length > 1000) return fail(1, `xargs: ${items.length} items; at most 1000 - narrow the list first`);
      const batches = [];
      if (f.I) for (const it of items) batches.push(cmd.map((a) => a.split(f.I).join(it)));
      else {
        const n = Number(f.n ?? f.L) || items.length || 1;
        for (let s = 0; s < Math.max(items.length, 1); s += n) batches.push([...cmd, ...items.slice(s, s + n)]);
      }
      if (batches.length > 100) return fail(1, `xargs: that would run the command ${batches.length} times; at most 100 (use a larger -n, or no -n/-I)`);
      let out = '';
      let err = '';
      let code = 0;
      const needsConfirm = [];
      for (const argv of batches) {
        const r = await runArgv(argv, null, ctx);
        out += r.out;
        err += r.err;
        if (r.code) code = 123;
        if (r.needsConfirm) needsConfirm.push(...r.needsConfirm);
      }
      return { code, out, err, ...(needsConfirm.length ? { needsConfirm } : {}) };
    },
    async basename(args) { return args[0] ? ok(`${baseName(args[0]).replace(args[1] && baseName(args[0]).endsWith(args[1]) ? new RegExp(`${escapeRe(args[1])}$`) : /$^/, '')}\n`) : fail(1, 'basename: missing operand'); },
    async dirname(args) { return args[0] ? ok(`${dirName(args[0])}\n`) : fail(1, 'dirname: missing operand'); },
    async realpath(args) { return ok(joinLines(args.map((a) => resolvePath(state.cwd, a)))); },
    async date(args) {
      const d = new Date();
      if (args[0] === '+%s') return ok(`${Math.floor(d.getTime() / 1000)}\n`);
      if (args[0]?.startsWith('+')) {
        const pad = (n) => String(n).padStart(2, '0');
        return ok(`${args[0].slice(1).replace(/%Y/g, d.getUTCFullYear()).replace(/%m/g, pad(d.getUTCMonth() + 1)).replace(/%d/g, pad(d.getUTCDate()))
          .replace(/%H/g, pad(d.getUTCHours())).replace(/%M/g, pad(d.getUTCMinutes())).replace(/%S/g, pad(d.getUTCSeconds())).replace(/%s/g, Math.floor(d.getTime() / 1000))}\n`);
      }
      return ok(`${d.toUTCString()}\n`);
    },
    async sleep(args) {
      const s = Math.min(30, Number(args[0]) || 0);
      await new Promise((r) => setTimeout(r, s * 1000));
      return ok();
    },
    async true() { return ok(); },
    async ':'() { return ok(); },
    // read [-r] [NAME...]: one line of the loop's (or the pipe's) input.
    async read(args, stdin, ctx) {
      const names = [];
      let raw = false;
      for (let k = 0; k < args.length; k++) {
        if (args[k] === '-r') raw = true;
        else if (/^-[pdnt]$/.test(args[k])) k++;
        else if (!args[k].startsWith('-')) names.push(args[k]);
      }
      const src = stdin !== null && stdin !== undefined ? { text: stdin, pos: 0 } : ctx.stream;
      if (!src || src.pos >= src.text.length) return { code: 1, out: '', err: '' };
      const nl = src.text.indexOf('\n', src.pos);
      let line = src.text.slice(src.pos, nl < 0 ? src.text.length : nl);
      src.pos = nl < 0 ? src.text.length : nl + 1;
      if (!raw) line = line.replace(/\\(.)/g, '$1');
      const ifs = state.vars.has('IFS') ? state.vars.get('IFS') : ' \t\n';
      if (!names.length) { state.vars.set('REPLY', line); return ok(); }
      const trimmed = ifs === '' ? line : line.replace(/^[ \t]+|[ \t]+$/g, '');
      if (names.length === 1) { state.vars.set(names[0], trimmed); return ok(); }
      const fields = trimmed.split(/[ \t]+/);
      names.forEach((n, k) => state.vars.set(n, k === names.length - 1 ? fields.slice(k).join(' ') : (fields[k] ?? '')));
      return ok();
    },
    async break(args) { return { code: 0, out: '', err: '', brk: Math.max(1, Number(args[0]) || 1) }; },
    async continue(args) { return { code: 0, out: '', err: '', cont: Math.max(1, Number(args[0]) || 1) }; },
    async export(args) {
      for (const a of args) {
        const m = /^([A-Za-z_]\w*)=(.*)$/s.exec(a);
        if (m) state.vars.set(m[1], m[2]);
        else if (!/^-/.test(a) && !state.vars.has(a)) state.vars.set(a, '');
      }
      return ok();
    },
    async local(args) { return commands.export(args); },
    async unset(args) { for (const a of args) if (!a.startsWith('-')) state.vars.delete(a); return ok(); },
    async env() { return ok(joinLines([...state.vars].map(([k, v]) => `${k}=${v}`))); },
    async printenv(args) {
      if (!args.length) return commands.env();
      const vals = args.filter((a) => state.vars.has(a)).map((a) => state.vars.get(a));
      return { code: vals.length === args.length ? 0 : 1, out: joinLines(vals), err: '' };
    },
    // set -e (stop at a failure nothing tests); -u, -x, -o pipefail are accepted.
    async set(args, stdin, ctx) {
      for (const a of args) {
        if (/^-[a-z]*e/.test(a)) ctx.opts.errexit = true;
        if (/^\+[a-z]*e/.test(a)) ctx.opts.errexit = false;
      }
      return ok();
    },
    async seq(args) {
      let sep = '\n';
      const ns = [];
      for (let k = 0; k < args.length; k++) {
        if (args[k] === '-s') sep = args[++k] ?? '';
        else if (args[k].startsWith('-s')) sep = args[k].slice(2);
        else if (args[k] === '-w') continue;
        else ns.push(Number(args[k]));
      }
      if (!ns.length || ns.length > 3 || ns.some((x) => Number.isNaN(x))) return fail(1, 'seq: seq LAST, seq FIRST LAST or seq FIRST STEP LAST');
      const [first, step, last] = ns.length === 1 ? [1, 1, ns[0]] : ns.length === 2 ? [ns[0], 1, ns[1]] : ns;
      if (!step || Math.abs((last - first) / step) > 100000) return fail(1, 'seq: at most 100000 numbers');
      const out = [];
      for (let x = first; step > 0 ? x <= last : x >= last; x += step) out.push(String(x));
      return ok(out.length ? `${out.join(sep)}\n` : '');
    },
    // time CMD: the command, then how long it took (as bash prints it).
    async time(args, stdin, ctx) {
      const t0 = Date.now();
      const r = await runArgv(args, stdin, ctx);
      const s = (Date.now() - t0) / 1000;
      return { ...r, err: `${r.err}\nreal\t${Math.floor(s / 60)}m${(s % 60).toFixed(3)}s\n` };
    },
    async '[['(args) {
      if (args.at(-1) !== ']]') return fail(2, '[[: missing `]]\'');
      return testExpr(args.slice(0, -1).map((a) => (a === '==' ? '=' : a)));
    },
    async false() { return { code: 1, out: '', err: '' }; },
    async test(args) { return testExpr(args); },
    async '['(args) {
      if (args.at(-1) !== ']') return fail(2, '[: missing `]\'');
      return testExpr(args.slice(0, -1));
    },
    async which(args) {
      const out = args.map((a) => (commands[a] || aliases[a] ? `${a}: cic.sh built-in` : null));
      return { code: out.some((x) => x === null) ? 1 : 0, out: joinLines(out.filter(Boolean)), err: '' };
    },
    async clear() { return ok(); },
    async help() {
      return ok(`${[
        'cic.sh - the shell\'s commands, run against this live site (no real shell anywhere).',
        'Files: ls [-laRth] cat [-n] head/tail [-n N] wc [-lwc] stat touch mkdir [-p] cp [-r] mv rm [-rf] rmdir tree [-L N] du [-sh] diff [-u]',
        'Search: grep [-rniEFPwlLcovq] [-A/-B/-C N] [--include=GLOB] [--exclude-dir=D]  find [-name/-iname/-path GLOB] [-type f|d] [-maxdepth N] [-newer F] [-exec CMD {} \\; | {} +] [-delete]  rg  git grep',
        'Edit: sed [-n] [-i [\'\']] [-E] \'s/a/b/g; 3,5d; /re/p\'   perl -pi -e \'s/a(?=b)/c/g\' [-0]   patch -p1 < x.diff / git apply   echo/printf ... > file   cat > file <<\'EOF\' ... EOF   tee [-a]',
        'Text: awk [-F -v] \'{print $2}\'  sort [-rnuk] uniq [-cdu] cut [-d -f] tr nl seq xargs [-n -I] basename dirname',
        'Laravel: php artisan ...  composer ...  php -r \'code\' (runs in the booted app)  php -l FILE  mysql -e \'SQL\'  curl [-i -X -d -H -o -w] /path',
        'History: git log/diff/show over every saved version (there is no git repository: every save is already a version; git add/commit do nothing)',
        'Shell: X=1 $X ${X:-d} $? $(cmd) $((i+1)) {a,b} {1..5}  for/while/until/if ... read break continue set -e  cd pwd | && || ; > >> < 2>&1 globs (*.php)',
        'A here-document body is never expanded, and an unset $name stays as written: PHP in double quotes arrives as sent.',
        'Deleting a folder, a destructive artisan command or a SQL write needs cic.sh(command, { confirm: true }).',
      ].join('\n')}`);
    },
    async history() { return fail(1, 'history: use git log -- FILE: every save of every file is a version'); },
    async git(args, stdin, ctx) { return git(args, ctx, stdin); },
    async patch(args, stdin) { return applyPatch(args, stdin, { strip: 0 }); },
    async php(args, stdin, ctx) { return php(args, stdin, ctx); },
    async artisan(args, stdin, ctx) { return tool('artisan', args, ctx); },
    async composer(args, stdin, ctx) { return tool('composer', args, ctx); },
    async curl(args) { return curl(args); },
    async mysql(args, stdin, ctx) { return mysql(args, stdin, ctx); },
  };
  const aliases = { 'll': ['ls', '-l'], 'la': ['ls', '-la'], 'l': ['ls', '-CF'], 'vendor/bin/pest': ['php', 'artisan', 'test'], 'vendor/bin/phpunit': ['php', 'artisan', 'test'], 'sail': [] };
  const absent = {
    npm: 'there is no Node on a site: Vite assets are built into public/build, or use the Tailwind CDN',
    npx: 'there is no Node on a site', node: 'there is no Node on a site', yarn: 'there is no Node on a site', pnpm: 'there is no Node on a site',
    python: 'there is no Python on a site', python3: 'there is no Python on a site', pip: 'there is no Python on a site',
    sudo: 'there is no root here: the site runs as its own user', apt: 'packages cannot be installed on a site; composer can add PHP ones', 'apt-get': 'packages cannot be installed on a site; composer can add PHP ones',
    docker: 'the site already is a container', ssh: 'there is no SSH from a site', scp: 'there is no SSH from a site',
    wget: 'only this site can be requested: curl /path', vim: 'use cic.edit or sed -i', vi: 'use cic.edit or sed -i', nano: 'use cic.edit or sed -i',
    chmod: 'modes are set by the host: PHP files are readable, only public/index.php runs', chown: 'files belong to the site already',
    kill: 'processes are managed by the host', ps: 'processes are managed by the host', top: 'processes are managed by the host',
    zip: 'use cic.zip(from, to)', unzip: 'use cic.unzip(archive, into)', tar: 'use cic.zip / cic.unzip',
    jq: 'no jq: read the JSON in your script - JSON.parse((await cic.read(path)).content) - or php -r \'print_r(json_decode(file_get_contents("composer.json"), true));\'',
    ruby: 'there is no Ruby on a site', go: 'there is no Go on a site',
    bash: 'this is the shell: run the commands themselves (a script file: cat it and pass the text to cic.sh)', sh: 'this is the shell: run the commands themselves',
    source: 'there are no shell script files to source: pass the lines to cic.sh', '.': 'there are no shell script files to source: pass the lines to cic.sh',
  };

  async function headTail(name, args, stdin) {
    const { flags, operands } = getopt(args, { bool: 'qvf', value: 'nc', numeric: true });
    if (flags.f) return fail(1, `${name}: -f cannot follow a file here; read the log with cic.logs()`);
    const { inputs, errors } = await readInputs(operands, stdin, name);
    const raw = String(flags.n ?? flags.c ?? '10');
    const fromStart = raw.startsWith('+');
    const count = Math.abs(parseInt(raw, 10) || 0);
    const negative = raw.startsWith('-');
    let out = '';
    inputs.forEach((x, k) => {
      if (inputs.length > 1 && !flags.q) out += `${k ? '\n' : ''}==> ${x.name} <==\n`;
      if (flags.c !== undefined) {
        out += name === 'head' ? x.text.slice(0, negative ? -count : count) : fromStart ? x.text.slice(count - 1) : x.text.slice(-count || x.text.length);
        return;
      }
      const lines = splitLines(x.text);
      let pick;
      if (name === 'head') pick = negative ? lines.slice(0, Math.max(0, lines.length - count)) : lines.slice(0, count);
      else pick = fromStart ? lines.slice(Math.max(0, count - 1)) : count ? lines.slice(-count) : [];
      out += joinLines(pick);
    });
    return { code: errors ? 1 : 0, out, err: errors };
  }

  async function testExpr(args) {
    let negate = false;
    if (args[0] === '!') { negate = true; args = args.slice(1); }
    const result = async () => {
      if (args.length === 1) return args[0] !== '';
      if (args.length === 2) {
        const [f, v] = args;
        if (f === '-z') return v === '';
        if (f === '-n') return v !== '';
        const s = await stat(resolvePath(state.cwd, v));
        if (f === '-e') return s.ok;
        if (f === '-f') return s.ok && !s.dir;
        if (f === '-d') return s.ok && s.dir;
        if (f === '-s') return s.ok && s.size > 0;
        if (f === '-r' || f === '-w') return s.ok;
        throw new Error(`${f}: unary operator expected`);
      }
      if (args.length === 3) {
        const [a, o, b] = args;
        const n = (x) => Number(x);
        switch (o) {
          case '=': case '==': return a === b;
          case '!=': return a !== b;
          case '-eq': return n(a) === n(b);
          case '-ne': return n(a) !== n(b);
          case '-lt': return n(a) < n(b);
          case '-le': return n(a) <= n(b);
          case '-gt': return n(a) > n(b);
          case '-ge': return n(a) >= n(b);
          default: throw new Error(`${o}: binary operator expected`);
        }
      }
      return args.length === 0 ? false : Promise.reject(new Error('too many arguments'));
    };
    try {
      const r = await result();
      return { code: (r !== negate) ? 0 : 1, out: '', err: '' };
    } catch (e) {
      return fail(2, `test: ${e.message}`);
    }
  }

  async function copyMove(name, args) {
    const { flags, operands } = getopt(args, { bool: 'rRafinvuT' });
    if (operands.length < 2) return fail(1, `${name}: missing destination file operand after '${operands[0] ?? ''}'`);
    const dest = operands.at(-1);
    const destAbs = resolvePath(state.cwd, dest);
    const sources = operands.slice(0, -1);
    // Every folder the checks below will list, asked for at once: one round
    // trip instead of one per stat (listings are remembered for this line).
    // The destination itself is listed too, in case it is a folder to copy
    // into; if it is not, that answer is simply not used.
    await Promise.all([...new Set([dirName(destAbs), destAbs, ...sources.map((s) => dirName(resolvePath(state.cwd, s)))])]
      .map((d) => io.list(d)));
    const ds = await stat(destAbs);
    const intoDir = ds.ok && ds.dir && !flags.T;
    if (sources.length > 1 && !intoDir) return fail(1, `${name}: target '${dest}' is not a directory`);
    let err = '';
    for (const src of sources) {
      const srcAbs = resolvePath(state.cwd, src);
      const ss = await stat(srcAbs);
      if (!ss.ok) { err += `${name}: cannot stat '${src}': No such file or directory\n`; continue; }
      if (name === 'cp' && ss.dir && !(flags.r || flags.R || flags.a)) { err += `cp: -r not specified; omitting directory '${src}'\n`; continue; }
      const to = intoDir ? `${destAbs === '/' ? '' : destAbs}/${baseName(srcAbs)}` : destAbs;
      if (to === srcAbs) { err += `${name}: '${src}' and '${dest}' are the same file\n`; continue; }
      const existing = intoDir ? await stat(to) : ds;
      if (existing.ok) {
        if (existing.dir) { err += `${name}: cannot overwrite directory '${to}'\n`; continue; }
        if (flags.n) continue;
        // The shell replaces an existing file; the old one goes to the bin.
        const rm = await io.remove(to);
        if (!rm.ok) { err += `${name}: cannot overwrite '${to}': ${rm.hint}\n`; continue; }
      }
      const r = name === 'cp' ? await io.copy(srcAbs, to) : await io.move(srcAbs, to);
      if (!r.ok) err += `${name}: cannot ${name === 'cp' ? 'copy' : 'move'} '${src}': ${r.hint}\n`;
    }
    return { code: err ? 1 : 0, out: '', err };
  }

  async function grep(args, stdin) {
    let opts;
    try {
      opts = getopt(args, {
        bool: 'rRnHhiylLcvoqsEFPwxIUZ', value: 'eABCmf',
        long: { include: 'value', exclude: 'value', 'exclude-dir': 'value', 'include-dir': 'value', color: 'value', colour: 'value', 'line-number': 'bool', recursive: 'bool', 'ignore-case': 'bool', 'files-with-matches': 'bool', count: 'bool', 'only-matching': 'bool', 'invert-match': 'bool', 'word-regexp': 'bool', 'extended-regexp': 'bool', 'perl-regexp': 'bool', 'fixed-strings': 'bool', quiet: 'bool', 'no-filename': 'bool', 'with-filename': 'bool', 'max-count': 'value', 'files-without-match': 'bool', context: 'value', 'after-context': 'value', 'before-context': 'value', 'no-messages': 'bool', regexp: 'value', 'null': 'bool', 'binary-files': 'value' },
      });
    } catch (e) {
      return fail(2, `grep: ${e.message}`);
    }
    const f = opts.flags;
    const list = (v) => (v === undefined ? [] : [v].flat());
    const patterns = [...list(f.e), ...list(f.regexp)];
    const operands = [...opts.operands];
    if (!patterns.length) {
      if (!operands.length) return fail(2, 'Usage: grep [OPTION]... PATTERNS [FILE]...');
      patterns.push(...operands.shift().split('\n'));
    }
    const recursive = f.r || f.R || f.recursive;
    const icase = f.i || f.y || f['ignore-case'];
    const fixed = f.F || f['fixed-strings'];
    // -P: JavaScript's regular expressions, which are Perl's for grep's purposes.
    const ere = f.E || f['extended-regexp'] || f.P || f['perl-regexp'];
    const word = f.w || f['word-regexp'];
    const invert = f.v || f['invert-match'];
    const only = f.o || f['only-matching'];
    const count = f.c || f.count;
    const filesWith = f.l || f['files-with-matches'];
    const filesWithout = f.L || f['files-without-match'];
    const quiet = f.q || f.quiet;
    const numbered = f.n || f['line-number'];
    const maxCount = f.m ?? f['max-count'];
    const after = Number(f.A ?? f['after-context'] ?? f.C ?? f.context ?? 0);
    const before = Number(f.B ?? f['before-context'] ?? f.C ?? f.context ?? 0);
    const includes = [...list(f.include)];
    const excludes = [...list(f.exclude)];
    const excludeDirs = [...list(f['exclude-dir'])];
    const exprs = patterns.map((p) => (fixed ? escapeRe(p) : ere ? p : breToEre(p)));
    let source = exprs.length > 1 ? exprs.map((e) => `(?:${e})`).join('|') : exprs[0];
    if (f.x) source = `^(?:${source})$`;
    let re;
    try {
      re = new RegExp(word ? `\\b(?:${source})\\b` : source, icase ? 'i' : '');
    } catch (e) {
      return fail(2, `grep: ${e.message}`);
    }
    const sites = operands.length ? operands : recursive ? ['.'] : [];
    const multi = recursive || sites.length > 1;
    const withName = f.H || f['with-filename'] ? true : f.h || f['no-filename'] ? false : multi;
    const excluded = (path) => {
      const name = baseName(path);
      if (includes.length && !includes.some((g) => globToRegExp(g).test(name))) return true;
      if (excludes.some((g) => globToRegExp(g).test(name))) return true;
      return excludeDirs.some((d) => path.split('/').slice(0, -1).some((seg) => globToRegExp(d).test(seg)));
    };

    // Lines of text -> output for one input, grep's formats.
    const emit = (name, text) => {
      const out = [];
      let hits = 0;
      let lastPrinted = -1;
      const src = splitLines(text);
      for (let k = 0; k < src.length; k++) {
        const line = src[k];
        re.lastIndex = 0;
        const matched = re.test(line) !== Boolean(invert);
        if (!matched) continue;
        hits++;
        if (count || filesWith || filesWithout || quiet) { if (maxCount && hits >= Number(maxCount)) break; continue; }
        const from = Math.max(0, k - before, lastPrinted + 1);
        if (lastPrinted >= 0 && from > lastPrinted + 1 && (before || after)) out.push('--');
        for (let c = from; c < k; c++) out.push(`${withName ? `${name}-` : ''}${numbered ? `${c + 1}-` : ''}${src[c]}`);
        if (only && !invert) {
          const g = new RegExp(re.source, `g${re.flags}`);
          for (const m of line.matchAll(g)) if (m[0]) out.push(`${withName ? `${name}:` : ''}${numbered ? `${k + 1}:` : ''}${m[0]}`);
        } else {
          out.push(`${withName ? `${name}:` : ''}${numbered ? `${k + 1}:` : ''}${line}`);
        }
        lastPrinted = k;
        let a = 0;
        while (a < after && k + 1 < src.length) {
          const next = src[k + 1];
          re.lastIndex = 0;
          if ((re.test(next) !== Boolean(invert))) break;
          out.push(`${withName ? `${name}-` : ''}${numbered ? `${k + 2}-` : ''}${next}`);
          k++;
          lastPrinted = k;
          a++;
        }
        if (maxCount && hits >= Number(maxCount)) break;
      }
      return { out, hits };
    };
    const finish = (results, errors) => {
      let out = '';
      let any = false;
      for (const r of results) {
        if (r.hits) any = true;
        if (quiet) continue;
        if (filesWith) { if (r.hits) out += `${r.name}\n`; continue; }
        if (filesWithout) { if (!r.hits) out += `${r.name}\n`; continue; }
        if (count) { out += `${withName ? `${r.name}:` : ''}${r.hits}\n`; continue; }
        out += joinLines(r.out);
      }
      if (quiet && any) return { code: 0, out: '', err: '' };
      return { code: errors && !any ? 2 : any ? 0 : 1, out, err: f.s ? '' : errors };
    };

    // From a pipe.
    if (!sites.length) {
      const r = emit('(standard input)', stdin ?? '');
      return finish([{ name: '(standard input)', ...r }], '');
    }

    const results = [];
    let errors = '';
    // Plain files named on the line: read them (in one call) and match here.
    const plain = [];
    const trees = [];
    // Lookaround is not in RE2 (the host's search): such a pattern is matched
    // here, in whole files.
    const lookaround = /\(\?<?[=!]/.test(source);
    const local = invert || filesWithout || before || after || lookaround;
    for (const s of sites) {
      const abs = resolvePath(state.cwd, s);
      if (!recursive) { plain.push(s); continue; }
      // The host searches a file or a folder alike: no need to ask which,
      // unless the answer is built here from whole files.
      if (!local) { trees.push(s); continue; }
      const st = await stat(abs);
      if (!st.ok) { errors += `grep: ${s}: No such file or directory\n`; continue; }
      (st.dir ? trees : plain).push(s);
    }
    if (plain.length) {
      const { inputs, errors: e } = await readInputs(plain, stdin, 'grep');
      errors += e;
      for (const x of inputs) results.push({ name: x.name, ...emit(x.name, x.text) });
    }
    // Folders: the host searches (RE2, linear time), unless the answer needs
    // whole files (-v, -L, context lines), then candidates are read here.
    for (const t of trees) {
      const abs = resolvePath(state.cwd, t);
      const display = (p) => (operands.length ? shown(t, abs, p) : relToCwd(p));
      let files;
      if (local) {
        const r = await io.find({ under: abs, type: 'f', limit: 200 });
        if (!r.ok) { errors += `grep: ${t}: ${r.hint}\n`; continue; }
        files = r.entries.map((e) => e.path).filter((p) => !excluded(p) && !/(^|\/)\.env(\..*)?$/.test(p));
        if (r.truncated) errors += `grep: ${t}: only the first 200 files were read for -v/-L/context; name a smaller folder\n`;
        if (!invert && !filesWithout && !lookaround) {
          const g = await io.grep({ pattern: source, regex: true, icase, word, under: abs, include: includes, limit: 500 });
          const hitFiles = new Set((g.hits ?? []).map((h) => h.path));
          files = files.filter((p) => hitFiles.has(p));
        }
        const read = await io.readMany(files);
        for (const p of files) if (read.files[p] !== undefined) results.push({ name: display(p), ...emit(display(p), read.files[p]) });
        continue;
      }
      // -c names every file, 0 included, as grep does: the list comes with the search.
      const [g, every] = await Promise.all([
        io.grep({ pattern: source, regex: true, icase, word, under: abs, include: includes, limit: 500 }),
        count && !quiet ? io.find({ under: abs, type: 'f', limit: 2000 }) : null,
      ]);
      if (!g.ok) { errors += /no such/i.test(g.hint ?? '') ? `grep: ${t}: No such file or directory\n` : `grep: ${g.hint}\n`; continue; }
      if (every?.ok) {
        const seen = new Set(g.hits.map((h) => h.path));
        const zeros = every.entries.map((e) => e.path)
          .filter((p) => !seen.has(p) && !excluded(p) && !/(^|\/)\.env(\..*)?$/.test(p) && (!includes.length || includes.some((x) => globToRegExp(x).test(baseName(p)))));
        for (const p of zeros) results.push({ name: display(p), out: [], hits: 0, path: p });
      }
      if (g.truncated) errors += 'grep: stopped at 500 matching lines; narrow the pattern or the folder\n';
      const byFile = new Map();
      for (const h of g.hits) {
        if (excluded(h.path)) continue;
        if (!byFile.has(h.path)) byFile.set(h.path, []);
        byFile.get(h.path).push(h);
      }
      for (const [p, hits] of byFile) {
        const name = display(p);
        const capped = maxCount ? hits.slice(0, Number(maxCount)) : hits;
        const out = [];
        for (const h of capped) {
          if (only) {
            for (const m of h.text.matchAll(new RegExp(re.source, `g${re.flags}`))) if (m[0]) out.push(`${withName ? `${name}:` : ''}${numbered ? `${h.line}:` : ''}${m[0]}`);
          } else {
            out.push(`${withName ? `${name}:` : ''}${numbered ? `${h.line}:` : ''}${h.text}`);
          }
        }
        results.push({ name, out, hits: capped.length });
      }
    }
    if (count) results.sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0));
    return finish(results, errors);
  }

  async function find(args) {
    const paths = [];
    let k = 0;
    while (k < args.length && !args[k].startsWith('-') && args[k] !== '!' && args[k] !== '(') paths.push(args[k++]);
    if (!paths.length) paths.push('.');
    const o = { name: null, iname: null, type: null, maxdepth: null, mindepth: 0, newer: null, older: null, path: null, ipath: null, notName: [], notPath: [], empty: false, size: null };
    let negate = false;
    const need = (flag) => {
      if (k + 1 >= args.length) throw new Error(`missing argument to \`${flag}'`);
      return args[++k];
    };
    const now = Date.now() / 1000;
    try {
      for (; k < args.length; k++) {
        const a = args[k];
        if (a === '!' || a === '-not') { negate = true; continue; }
        if (a === '-name' || a === '-iname') {
          const v = need(a);
          if (negate) o.notName.push({ re: globToRegExp(v, a === '-iname' ? 'i' : '') });
          else o[a.slice(1)] = v;
        } else if (a === '-path' || a === '-ipath' || a === '-wholename') {
          const v = need(a);
          const re = new RegExp(globToRegExp(v, a === '-ipath' ? 'i' : '').source.replace(/\[\^\/\]\*/g, '.*'), a === '-ipath' ? 'i' : '');
          if (negate) o.notPath.push(re);
          else o.path = re;
        } else if (a === '-type') {
          o.type = need(a);
          if (!['f', 'd'].includes(o.type)) throw new Error(`-type ${o.type}: only f and d exist here (no links, no devices)`);
        } else if (a === '-maxdepth') o.maxdepth = Number(need(a));
        else if (a === '-mindepth') o.mindepth = Number(need(a));
        else if (a === '-newer') {
          const ref = need(a);
          const s = await stat(resolvePath(state.cwd, ref));
          if (!s.ok) throw new Error(`'${ref}': No such file or directory`);
          o.newer = s.mtime;
        } else if (a === '-mmin' || a === '-mtime') {
          const v = need(a);
          const unit = a === '-mmin' ? 60 : 86400;
          const n = Math.abs(Number(v));
          if (v.startsWith('-')) o.newer = now - n * unit;
          else if (v.startsWith('+')) o.older = now - n * unit;
          else { o.newer = now - (n + 1) * unit; o.older = now - n * unit; }
        } else if (a === '-empty') o.empty = true;
        else if (a === '-size') o.size = need(a);
        else if (a === '-print' || a === '-print0' || a === '-follow' || a === '-xdev') { /* the default */ }
        else if (a === '-ok') {
          throw new Error('-ok asks at a terminal, and there is none: use -exec');
        } else if (a === '-o' || a === '-or' || a === '(' || a === ')') {
          throw new Error(`${a}: alternatives are not supported; run find twice, or use -regex-free globs`);
        } else throw new Error(`unknown predicate \`${a}'`);
        if (a !== '!' && a !== '-not') negate = false;
      }
    } catch (e) {
      return fail(1, `find: ${e.message}`);
    }
    const sizeOk = (e) => {
      if (!o.size) return true;
      const m = o.size.match(/^([+-]?)(\d+)([ckMG]?)$/);
      if (!m) return true;
      const mult = { c: 1, k: 1024, M: 1 << 20, G: 1 << 30, '': 512 }[m[3]];
      const want = Number(m[2]) * mult;
      return m[1] === '+' ? e.size > want : m[1] === '-' ? e.size < want : Math.ceil(e.size / mult) === Number(m[2]);
    };
    let out = '';
    let err = '';
    for (const p of paths) {
      const abs = resolvePath(state.cwd, p);
      const [s, r] = await Promise.all([stat(abs), io.find({
        under: abs, name: o.name ?? o.iname ?? undefined, icase: Boolean(o.iname), type: o.type ?? undefined,
        newer: o.newer ? Math.floor(o.newer) : undefined, maxdepth: o.maxdepth ?? undefined,
      })]);
      if (!s.ok) { err += `find: '${p}': No such file or directory\n`; continue; }
      if (!r.ok) { err += `find: '${p}': ${r.hint}\n`; continue; }
      const base = abs === '/' ? 0 : abs.split('/').length - 1;
      // Every test, for an entry the host returned and for the starting
      // point, which find prints too when it matches.
      const keep = (e, disp, name, depth) => {
        if (depth < o.mindepth) return false;
        if (o.type && (o.type === 'd') !== e.dir) return false;
        if (o.name && !globToRegExp(o.name).test(name)) return false;
        if (o.iname && !globToRegExp(o.iname, 'i').test(name)) return false;
        if (o.notName.some((n) => n.re.test(name))) return false;
        if (o.path && !o.path.test(disp)) return false;
        if (o.notPath.some((re) => re.test(disp))) return false;
        if (o.newer && e.mtime <= o.newer) return false;
        if (o.older && e.mtime >= o.older) return false;
        if (o.empty && (e.dir || e.size > 0)) return false;
        return sizeOk(e);
      };
      const lines = [];
      if (keep(s, p, p === '.' ? '.' : baseName(abs), 0)) lines.push(p);
      for (const e of r.entries) {
        const disp = shown(p, abs, e.path);
        if (keep(e, disp, baseName(e.path), e.path.split('/').length - 1 - base)) lines.push(disp);
      }
      out += joinLines(lines);
      if (r.truncated) err += `find: '${p}': stopped at 5000 entries; add -name, -type or -maxdepth\n`;
      const skipped = (r.skipped ?? []).map((d) => shown(p, abs, d));
      if (skipped.length) {
        err += `find: not entered (dependencies and caches): ${skipped.join(', ')} - name one to look inside, e.g. find vendor/laravel -name '*.php'\n`;
      }
    }
    return { code: err && !out ? 1 : 0, out, err };
  }

  async function git(args, ctx, stdin) {
    const sub = args[0];
    const rest = args.slice(1);
    // What an agent types out of habit, mapped to what it means here.
    if (sub === 'grep') return commands.grep(['-r', '-I', ...rest.filter((a) => a !== '--')], null);
    if (sub === 'mv') return copyMove('mv', rest.filter((a) => a !== '--' && a !== '-f'));
    if (sub === 'rm') return commands.rm(rest.filter((a) => a !== '--' && a !== '--cached' && a !== '-q'), null, ctx);
    if (sub === 'apply') return applyPatch(rest, stdin, { strip: 1, check: rest.includes('--check') });
    if (['add', 'commit', 'stage', 'push', 'pull', 'fetch'].includes(sub)) {
      return { code: 0, out: '', err: `git ${sub}: nothing to do - every save is already a version (git log -- FILE lists them)\n` };
    }
    const pathArg = () => {
      const dd = rest.indexOf('--');
      const cands = dd >= 0 ? rest.slice(dd + 1) : rest.filter((a) => !a.startsWith('-') && !/^[0-9a-f]{4,40}$/i.test(a) && !/^HEAD/.test(a));
      return cands[0];
    };
    if (sub === 'log') {
      const p = pathArg();
      if (!p) return fail(128, 'git log: name a file: git log -- routes/web.php (every save of every file is a version; there is no site-wide log)');
      const r = await io.history(resolvePath(state.cwd, p));
      if (!r.ok) return fail(128, `git log: ${r.hint}`);
      const nArg = rest.find((a) => /^-n\d*$|^-\d+$|^--max-count/.test(a));
      let n = Infinity;
      if (nArg) n = Number(nArg.replace(/^--max-count=|^-n|^-/, '') || rest[rest.indexOf(nArg) + 1]);
      const oneline = rest.includes('--oneline');
      const out = r.versions.slice(0, n).map((v) => (oneline
        ? `${v.commit.slice(0, 7)} ${v.message}`
        : `commit ${v.commit}\nDate:   ${v.at}\n\n    ${v.message}\n`));
      return ok(joinLines(out));
    }
    if (sub === 'diff' || sub === 'show') {
      let rev = rest.find((a) => /^[0-9a-f]{4,40}(:.*)?$/i.test(a) || /^HEAD/.test(a));
      let p = pathArg();
      if (rev?.includes(':')) { [rev, p] = [rev.split(':')[0], rev.split(':').slice(1).join(':')]; }
      if (!p) return fail(128, `git ${sub}: name a file: git ${sub} -- app/Models/Item.php`);
      const abs = resolvePath(state.cwd, p);
      const h = await io.history(abs);
      if (!h.ok) return fail(128, `git ${sub}: ${h.hint}`);
      const pick = (r) => {
        if (!r) return null;
        const m = r.match(/^HEAD(?:~(\d+)|(\^+))?$/);
        if (m) return h.versions[m[1] ? Number(m[1]) : m[2] ? m[2].length : 0]?.commit;
        return h.versions.find((v) => v.commit.startsWith(r))?.commit;
      };
      if (sub === 'show') {
        const c = pick(rev ?? 'HEAD');
        if (!c) return fail(128, `git show: no such version of ${p}`);
        const v = await io.versionAt(abs, c);
        return v.ok ? ok(v.content) : fail(128, `git show: ${v.hint}`);
      }
      // diff: the working file against a version (default: the one before the last save).
      const c = pick(rev ?? 'HEAD~1') ?? h.versions[h.versions.length - 1]?.commit;
      if (!c) return ok();
      const [old, cur] = await Promise.all([io.versionAt(abs, c), io.read(abs)]);
      if (!old.ok) return fail(128, `git diff: ${old.hint}`);
      const d = unifiedDiff(old.content, cur.ok ? cur.content : '', `a/${p.replace(/^\//, '')}`, `b/${p.replace(/^\//, '')}`);
      return ok(d ?? 'the versions are too different to show line by line\n');
    }
    if (sub === 'status') {
      const r = await io.find({ under: '/', type: 'f', newer: Math.floor(Date.now() / 1000) - 3600 });
      const changed = (r.entries ?? []).map((e) => `\tmodified:   ${e.path.slice(1)}`);
      return ok(`Not a git repository: every save is already a version (git log -- FILE).\nChanged in the last hour:\n${changed.length ? changed.join('\n') : '\t(nothing)'}\n`);
    }
    if (sub === 'clone') {
      // git clone [-b REF] [--depth N] URL [DIR]: the host fetches GitHub's
      // archive of it (there is no git history to clone: depth is always 1).
      let ref = '';
      const ops = [];
      for (let k = 0; k < rest.length; k++) {
        const a = rest[k];
        if (a === '-b' || a === '--branch') ref = rest[++k] ?? '';
        else if (a.startsWith('--branch=')) ref = a.slice(9);
        else if (a === '--depth' || a === '-o' || a === '--origin' || a === '-c' || a === '--config') k++;
        else if (a.startsWith('-')) continue; // --depth=1, -q, --single-branch, --recursive
        else ops.push(a);
      }
      if (rest.includes('--status')) return operationStatus();
      if (!ops.length) return fail(128, 'usage: git clone [-b branch] https://github.com/owner/repo [folder]  (folder / replaces the whole site)');
      const repo = ops[0].replace(/^git@github\.com:/, 'https://github.com/');
      const into = ops[1] ? resolvePath(state.cwd, ops[1]) : resolvePath(state.cwd, repo.replace(/\.git\/?$/, '').split('/').filter(Boolean).pop() ?? '');
      if (into === '/') return replaceSite(repo, ref, ctx);
      const t0 = Date.now();
      const r = await io.clone({ repository: repo, ref, into });
      if (!r.ok) return fail(128, `fatal: ${r.hint ?? r.error}`);
      const c = r.clone ?? {};
      return { code: 0, out: '', err: `Cloning into '${ops[1] ?? baseName(into)}'...\n${c.files ?? 0} files, ${human(c.bytes ?? 0)}B from ${c.repository}@${c.ref} in ${((c.ms ?? Date.now() - t0) / 1000).toFixed(1)} s (scanned for malware)\n` };
    }
    if (['add', 'commit', 'push', 'pull', 'init', 'checkout', 'branch', 'stash', 'reset', 'restore', 'fetch', 'merge', 'rebase'].includes(sub)) {
      return fail(128, `git ${sub}: there is no repository here: every save is already a version, and the site is live as soon as a file is saved. To undo a file: cic.history(path), then cic.restore(path, commit)`);
    }
    return fail(1, 'git: log, diff, show and status read the saved versions; clone brings a public repository in');
  }

  // git clone URL / : the site itself becomes the repository. Checked and
  // scanned first, then backed up and swapped by the host in the background;
  // this waits up to 90 s, then says how to follow it.
  async function replaceSite(repo, ref, ctx) {
    const r = await io.clone({ repository: repo, ref, replace: true, confirm: ctx.confirm });
    if (!r.ok) {
      if (r.error === 'needs_confirm') {
        return gated(`git clone ${ref ? `-b ${shQuote(ref)} ` : ''}${shQuote(repo)} /`,
          `git clone: this replaces the whole site with ${repo} - backed up first, and .env and storage/ are kept - it needs confirmation`);
      }
      return fail(128, `fatal: ${r.hint ?? r.error}`);
    }
    let err = `Replacing the site with ${repo}: checked and scanned; now backing up, then swapping and composer install...\n`;
    for (let waited = 0; waited < 90000; waited += 3000) {
      await (io.sleep ?? ((ms) => new Promise((res) => setTimeout(res, ms))))(3000);
      const s = await io.operation();
      const op = s.operation;
      if (op && op.kind === 'clone-replace' && op.state !== 'running') {
        return { code: op.state === 'done' ? 0 : 1, out: '', err: `${err}${op.state === 'done' ? '' : 'fatal: '}${op.message}\n` };
      }
    }
    return { code: 0, out: '', err: `${err}Still running (backups of a big site take a while). Follow it with: git clone --status\n` };
  }

  async function operationStatus() {
    const s = await io.operation();
    if (!s.ok) return fail(1, `git clone --status: ${s.hint ?? s.error}`);
    const op = s.operation;
    if (!op) return ok('nothing running, and nothing has run\n');
    return { code: op.state === 'failed' ? 1 : 0, out: `${op.kind}: ${op.state} - ${op.message ?? ''}${op.savedAs ? ` (backup ${op.savedAs})` : ''}\n`, err: '' };
  }

  async function tool(name, args, ctx) {
    if (!args.length) return fail(1, `${name}: name a command, e.g. ${name === 'artisan' ? 'php artisan route:list' : 'composer require vendor/package'}`);
    const r = await io.command(name, args, ctx.confirm);
    if (!r.ok && !r.result) {
      if (r.error === 'needs_confirm') {
        return gated(`${name === 'artisan' ? 'php artisan' : name} ${args.map(shQuote).join(' ')}`, `${name} ${args[0]}: this destroys data; it needs confirmation`);
      }
      return fail(1, `${name}: ${r.hint ?? r.error}`);
    }
    const res = r.result;
    const text = (res.text ?? res.output ?? '').replace(/\x1b\[[0-9;]*[A-Za-z]/g, '');
    return { code: res.timedOut ? 124 : res.exitCode, out: text.endsWith('\n') || !text ? text : `${text}\n`, err: res.timedOut ? `${name}: stopped at the time limit\n` : res.truncated ? `${name}: output truncated\n` : '' };
  }

  async function php(args, stdin, ctx) {
    if (args[0] === 'artisan' || args[0] === './artisan') {
      const rest = args.slice(1);
      if (rest[0] === 'tinker') {
        const exec = rest.find((a) => a.startsWith('--execute='))?.slice(10) ?? (rest.includes('--execute') ? rest[rest.indexOf('--execute') + 1] : null) ?? stdin;
        if (!exec) return fail(1, 'tinker: pass --execute="code", or pipe the code in');
        return evalPhp(exec);
      }
      return tool('artisan', rest, ctx);
    }
    if (args[0] === '-r') return evalPhp(args[1] ?? '');
    if (args[0] === '-v' || args[0] === '--version') return evalPhp('echo "PHP ".PHP_VERSION." (codeinchrome site)\\n";');
    if (args[0] === '-m') return evalPhp('echo implode("\\n", get_loaded_extensions())."\\n";');
    if (args[0] === '-l') return phpLint(args.slice(1).filter((a) => !a.startsWith('-')));
    if (args[0] === 'vendor/bin/pest' || args[0] === 'vendor/bin/phpunit') return tool('artisan', ['test', ...args.slice(1)], ctx);
    if (!args.length && stdin) return evalPhp(stdin.replace(/^<\?php\s*/, ''));
    return fail(1, 'php: php artisan ..., php -r \'code\' (in the booted app), php -v, php -m');
  }

  // patch [-pN] [-R] [--dry-run] [-i FILE] [FILE] < diff, git apply [--check]
  // [-R] [FILE...]: a unified diff applied to the site - every file read in
  // one call, every change written in one, each checked against what was read.
  async function applyPatch(args, stdin, { strip = 0, check = false } = {}) {
    let reverse = false;
    const sources = [];
    let target = null;
    for (let k = 0; k < args.length; k++) {
      const a = args[k];
      if (/^-p\d+$/.test(a)) strip = Number(a.slice(2));
      else if (a === '-p') strip = Number(args[++k]);
      else if (a === '-R' || a === '--reverse') reverse = true;
      else if (a === '--dry-run' || a === '--check') check = true;
      else if (a === '-i' || a === '--input') sources.push(args[++k]);
      else if (a.startsWith('-')) continue; // -u, -N, --verbose, -f: no effect here
      else if (!sources.length && /\.(diff|patch)$/.test(a)) sources.push(a);
      else target = a;
    }
    let text = stdin ?? '';
    if (sources.length) {
      const r = await io.readMany(sources.map((f) => resolvePath(state.cwd, f)));
      const missing = sources.find((f) => r.files[resolvePath(state.cwd, f)] === undefined);
      if (missing) return fail(2, `can't open patch file ${missing}`);
      text = sources.map((f) => r.files[resolvePath(state.cwd, f)]).join('\n');
    }
    let patches;
    try {
      patches = parsePatch(text, { strip, reverse });
    } catch (e) {
      return fail(2, `patch: ${e.message}`);
    }
    if (!patches.length) return fail(2, 'patch: no diff found in the input (it needs --- a/FILE, +++ b/FILE and @@ lines)');
    if (target && patches.length === 1) patches[0].path = target;
    const paths = [...new Set(patches.filter((x) => !x.created).map((x) => resolvePath(state.cwd, x.path)))];
    const reads = await Promise.all(paths.map((p) => io.read(p)));
    const current = new Map(paths.map((p, k) => [p, reads[k].ok ? reads[k].content : undefined]));
    const revisions = Object.fromEntries(paths.map((p, k) => [p, reads[k].revision ?? '']));
    let out = '';
    let err = '';
    const writes = new Map();
    const removals = [];
    for (const x of patches) {
      const abs = resolvePath(state.cwd, x.path);
      out += `patching file ${x.path}\n`;
      if (x.created) {
        if (current.get(abs) !== undefined || writes.has(abs)) { err += `patch: ${x.path} already exists\n`; continue; }
        writes.set(abs, { content: x.hunks.flatMap((h) => h.lines.filter((l) => l[0] === '+').map((l) => l.slice(1))).join('\n') + (x.noNewline ? '' : '\n'), expect: 'absent' });
        continue;
      }
      const before = writes.get(abs)?.content ?? current.get(abs);
      if (before === undefined) { err += `patch: can't find file to patch: ${x.path}\n`; continue; }
      if (x.deleted) { removals.push(abs); continue; }
      const r = applyHunks(before, x.hunks);
      if (r.failed.length) { err += r.failed.map((n) => `Hunk #${n} FAILED on ${x.path}: its lines are not in the file\n`).join(''); continue; }
      if (r.offsets.length) out += r.offsets.map(([n, off]) => `Hunk #${n} succeeded at an offset of ${off} lines.\n`).join('');
      writes.set(abs, { content: r.text, expect: writes.get(abs)?.expect ?? revisions[abs] ?? '' });
    }
    if (err) return { code: 1, out, err: `${err}patch: nothing was changed - every hunk must apply\n` };
    if (check) return { code: 0, out: out.replace(/^patching file /gm, 'checking file '), err: '' };
    if (writes.size) {
      const w = await io.writeMany([...writes].map(([path, f]) => ({ path, content: f.content, expect: f.expect })));
      if (!w.ok) return fail(1, `patch: ${w.hint ?? 'could not write'}`);
      err += lintNote(w);
    }
    for (const p of removals) {
      const r = await io.remove(p);
      if (!r.ok) err += `patch: cannot remove ${p}: ${r.hint}\n`;
    }
    return { code: err && !/syntax error/.test(err) ? 1 : 0, out, err };
  }

  // php -l FILE...: PHP's own parser (token_get_all with TOKEN_PARSE raises
  // the ParseError php -l reports), run in the site for every file at once.
  async function phpLint(files) {
    if (!files.length) return fail(1, 'php -l: name the files to check');
    const pairs = files.map((f) => [resolvePath(state.cwd, f).replace(/^\/+/, ''), f]);
    const b64 = btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(pairs))));
    const code = `$out = ''; $bad = 0;
foreach (json_decode(base64_decode('${b64}'), true) as [$rel, $shown]) {
  $p = base_path($rel);
  if (! is_file($p)) { $out .= "Could not open input file: $shown\\n"; $bad = 1; continue; }
  try { token_get_all(file_get_contents($p), TOKEN_PARSE); $out .= "No syntax errors detected in $shown\\n"; }
  catch (\\ParseError $e) { $out .= 'PHP Parse error:  '.$e->getMessage()." in $shown on line ".$e->getLine()."\\nErrors parsing $shown\\n"; $bad = 255; }
}
return $out.'__cic_exit='.$bad;`;
    const r = await io.eval(code);
    const text = String(r.output ?? '');
    const m = /__cic_exit=(\d+)\s*$/.exec(text);
    if (!m) return fail(1, `php -l: ${r.hint ?? r.error ?? (text || 'no answer from the site')}`);
    return { code: Number(m[1]), out: text.slice(0, m.index), err: '' };
  }

  async function evalPhp(code) {
    const r = await io.eval(code);
    if (r.ok === false && r.output === undefined) return fail(255, `php: ${r.hint ?? r.error}`);
    const out = r.output ?? '';
    return { code: r.exitCode ?? (r.ok ? 0 : 255), out: out && !out.endsWith('\n') ? `${out}\n` : out, err: r.timedOut ? 'php: stopped at the time limit\n' : '' };
  }

  async function curl(args) {
    let opts;
    try {
      opts = getopt(args, {
        bool: 'sSiILkfvg', value: 'XdHoAbwue',
        long: { data: 'value', 'data-raw': 'value', 'data-urlencode': 'value', json: 'value', header: 'value', request: 'value', silent: 'bool', include: 'bool', head: 'bool', location: 'bool', output: 'value', 'write-out': 'value', 'user-agent': 'value', cookie: 'value', fail: 'bool', insecure: 'bool', compressed: 'bool', 'show-error': 'bool', 'max-time': 'value' },
      });
    } catch (e) {
      return fail(2, `curl: ${e.message}`);
    }
    const f = opts.flags;
    const url = opts.operands[0];
    if (!url) return fail(2, 'curl: no URL specified');
    let path;
    try {
      // As curl reads it: "localhost/x" is a host and a path, "/x" a path on this site.
      const u = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(url) || url.startsWith('/') ? url : `http://${url}`, io.siteUrl);
      const site = new URL(io.siteUrl);
      if (u.host !== site.host && !/^(localhost|127\.0\.0\.1)(:\d+)?$/.test(u.host)) {
        return fail(6, `curl: only this site can be requested from here (${site.host}); use a path such as /api/items`);
      }
      path = u.pathname + u.search;
    } catch {
      return fail(3, `curl: (3) URL rejected: ${url}`);
    }
    const headers = {};
    for (const h of [f.H, f.header].flat().filter(Boolean)) {
      const i = h.indexOf(':');
      if (i > 0) headers[h.slice(0, i).trim()] = h.slice(i + 1).trim();
    }
    if (f.A ?? f['user-agent']) headers['User-Agent'] = f.A ?? f['user-agent'];
    const data = [f.d, f.data, f['data-raw'], f['data-urlencode']].flat().filter((x) => x !== undefined);
    const options = { method: String(f.X ?? f.request ?? (f.I || f.head ? 'HEAD' : data.length || f.json ? 'POST' : 'GET')).toUpperCase(), headers, follow: Boolean(f.L || f.location) };
    if (f.json !== undefined) {
      try { options.json = JSON.parse(f.json); } catch { return fail(2, 'curl: --json is not valid JSON'); }
    } else if (data.length) {
      options.body = data.join('&');
      if (!Object.keys(headers).some((h) => h.toLowerCase() === 'content-type')) headers['Content-Type'] = 'application/x-www-form-urlencoded';
      if (/json/i.test(headers['Content-Type'] ?? headers['content-type'] ?? '')) {
        try { options.json = JSON.parse(options.body); delete options.body; } catch { /* sent as written */ }
      }
    }
    const t0 = Date.now();
    const r = await io.request(path, options);
    if (!r.ok) return fail(7, `curl: ${r.hint ?? r.error}`);
    const head = `HTTP/1.1 ${r.status}\n${Object.entries(r.headers ?? {}).map(([k, v]) => `${k}: ${v}`).join('\n')}\n\n`;
    let out = f.I || f.head ? head : `${f.i || f.include ? head : ''}${r.body ?? ''}`;
    const target = f.o ?? f.output;
    if (target && target !== '/dev/null') {
      const w = await io.write(resolvePath(state.cwd, target), r.body ?? '', '');
      if (!w.ok) return fail(23, `curl: (23) ${w.hint}`);
      out = '';
    } else if (target === '/dev/null') out = '';
    const wo = f.w ?? f['write-out'];
    if (wo) {
      out += wo.replace(/%\{http_code\}|%\{response_code\}/g, String(r.status))
        .replace(/%\{time_total\}/g, ((r.ms ?? Date.now() - t0) / 1000).toFixed(6))
        .replace(/%\{redirect_url\}/g, r.location ?? '')
        .replace(/%\{size_download\}/g, String((r.body ?? '').length))
        .replace(/%\{content_type\}/g, r.headers?.['content-type'] ?? '')
        .replace(/\\n/g, '\n');
    }
    if ((f.f || f.fail) && r.status >= 400) return { code: 22, out: '', err: `curl: (22) The requested URL returned error: ${r.status}\n` };
    return { code: 0, out, err: r.truncated ? 'curl: the body was cut at the size limit\n' : '' };
  }

  async function mysql(args, stdin, ctx) {
    const { flags } = getopt(args, { bool: 'NBstvnrfq', value: 'euphPD', long: { execute: 'value', 'skip-column-names': 'bool', batch: 'bool', table: 'bool', raw: 'bool', silent: 'bool', user: 'value', password: 'value', host: 'value', database: 'value', port: 'value' } });
    const sql = flags.e ?? flags.execute ?? stdin;
    if (!sql || !String(sql).trim()) return fail(1, 'mysql: pass the SQL with -e "SELECT ..." or pipe it in (this is the site\'s own database; no password needed)');
    const r = await io.query(String(sql), ctx.confirm);
    if (!r.ok) {
      if (r.error === 'needs_write') return gated(`mysql -e ${shQuote(String(sql))}`, 'mysql: this statement changes data; it needs confirmation');
      return fail(1, `ERROR: ${r.hint ?? r.error}`);
    }
    const res = r.result;
    if (!res.columns?.length) return ok(res.affected !== undefined ? `Query OK, ${res.affected} row(s) affected\n` : '');
    const noHead = flags.N || flags['skip-column-names'];
    const cell = (v) => (v === null ? 'NULL' : String(v).replace(/\n/g, '\\n').replace(/\t/g, '\\t'));
    const rows = res.rows.map((row) => (Array.isArray(row) ? row : res.columns.map((c) => row[c])).map(cell).join('\t'));
    return { code: 0, out: joinLines([...(noHead ? [] : [res.columns.join('\t')]), ...rows]), err: res.truncated ? 'mysql: only the first 500 rows are shown\n' : '' };
  }

  /* ───────────── running ───────────── */

  async function runArgv(argv, stdin, ctx) {
    let [name, ...args] = argv;
    if (aliases[name]) [name, ...args] = [...aliases[name], ...args];
    if (!name) return ok();
    if (name.startsWith('./') && commands[name.slice(2)]) name = name.slice(2);
    const fn = commands[name];
    if (!fn) {
      if (absent[name]) return fail(127, `${name}: ${absent[name]}`);
      return fail(127, `cic.sh: ${name}: command not found (cic.sh('help') lists the commands)`);
    }
    try {
      return await fn(args, stdin, ctx);
    } catch (e) {
      return fail(1, `${name}: ${e.message}`);
    }
  }

  /* ───────────── expansion ───────────── */

  const assignmentOf = (w) => {
    const p0 = w.parts?.[0];
    if (!p0 || p0.s === undefined || p0.q !== '') return null;
    return /^([A-Za-z_]\w*)=/.exec(p0.s)?.[1] ?? null;
  };

  function varValue(part) {
    const n = part.v;
    if (n === '?') return String(state.last);
    if (state.vars.has(n)) return state.vars.get(n);
    if (n === 'PWD') return state.cwd;
    if (n === 'HOME') return '/';
    if (part.def !== undefined) return part.def;
    return null; // no value: left as written
  }

  // One expansion's text. `literal` when it stays as written.
  async function partValue(part, ctx, errs) {
    if (part.e === 'var') {
      const v = varValue(part);
      return v === null ? { text: part.raw, literal: true } : { text: v };
    }
    if (part.e === 'arith') return { text: String(arith(part.v, (n) => state.vars.get(n))) };
    // $(command): the same shell, its output without trailing newlines.
    let list;
    try {
      list = parse(part.v);
    } catch (e) {
      errs.push(`cic.sh: $(${part.v}): ${e.message}\n`);
      return { text: '' };
    }
    const r = await runList(list, { ...ctx, stream: null, cond: false });
    errs.push(r.err);
    state.last = r.code;
    if (r.needsConfirm) errs.push(`cic.sh: $(${part.v}) needs confirmation; it did not run\n`);
    return { text: r.out.replace(/\n+$/, '') };
  }

  // A word's text with every expansion done and no splitting (assignments,
  // redirect targets, "for" items that were quoted).
  async function wordValue(w, ctx, errs, skip = 0) {
    if (!w.parts) return w.v.slice(skip);
    let text = '';
    for (let k = 0; k < w.parts.length; k++) {
      const p = w.parts[k];
      if (p.s !== undefined) text += k === 0 ? p.s.slice(skip) : p.s;
      else text += (await partValue(p, ctx, errs)).text;
    }
    return text;
  }

  // Words -> [{ v, glob }]: braces, then expansions, then splitting of what an
  // unquoted expansion produced, as sh does. An unquoted expansion that is
  // empty leaves no word at all.
  async function expandWords(words, ctx, errs) {
    const out = [];
    for (const word of words.flatMap(braceWords)) {
      if (!word.exp) { out.push({ v: word.v, glob: word.glob }); continue; }
      const fields = [];
      let cur = null;
      let glob = false;
      for (const p of word.parts) {
        if (p.s !== undefined) {
          cur = (cur ?? '') + p.s;
          if (p.q === '' && GLOB.test(p.s)) glob = true;
          continue;
        }
        const { text, literal } = await partValue(p, ctx, errs);
        if (p.q === 'd' || literal) { cur = (cur ?? '') + text; continue; }
        const pieces = text.split(/[ \t\n]+/);
        for (let j = 0; j < pieces.length; j++) {
          if (j > 0 && cur !== null) { fields.push(cur); cur = null; }
          if (pieces[j] !== '') cur = (cur ?? '') + pieces[j];
        }
      }
      if (cur !== null) fields.push(cur);
      for (const f of fields) out.push({ v: f, glob });
    }
    return out;
  }

  /* ───────────── running ───────────── */

  const flagsOf = (r) => ({
    ...(r.exit ? { exit: true } : {}), ...(r.brk ? { brk: r.brk } : {}), ...(r.cont ? { cont: r.cont } : {}),
    ...(r.needsConfirm?.length ? { needsConfirm: r.needsConfirm } : {}),
  });

  async function targetOf(r, ctx, errs) {
    return r.word ? wordValue(r.word, ctx, errs) : r.target;
  }

  async function inputOf(node, stdin, ctx, errs) {
    let input = stdin;
    for (const r of node.redirects) {
      if (r.op !== '<') continue;
      const target = await targetOf(r, ctx, errs);
      const f = await io.read(resolvePath(state.cwd, target));
      if (!f.ok) return { missing: target };
      input = f.content;
    }
    if (node.heredoc) input = node.heredoc.body;
    return { input };
  }

  async function redirectOutput(res, redirects, ctx, errs) {
    let { out, err } = res;
    let { code } = res;
    for (const r of redirects) {
      if (r.op === '<') continue;
      if (r.op === '2>&1') { out += err; err = ''; continue; }
      if (r.op === '1>&2') { err += out; out = ''; continue; }
      const toErr = r.op.startsWith('2');
      const both = r.op.startsWith('&');
      const data = both ? out + err : toErr ? err : out;
      if (toErr) err = ''; else if (both) { out = ''; err = ''; } else out = '';
      const target = await targetOf(r, ctx, errs);
      if (target === '/dev/null') continue;
      if (target === '/dev/stderr') { err += data; continue; }
      if (target === '/dev/stdout') { out += data; continue; }
      const w = await writeFile(resolvePath(state.cwd, target), data, { append: r.op.endsWith('>>') });
      if (!w.ok) { err += `cic.sh: ${target}: ${w.hint}\n`; code = 1; } else err += lintNote(w);
    }
    return { ...res, code, out, err: errs.join('') + err };
  }

  async function runCommand(cmd, stdin, ctx) {
    const errs = [];
    let n = 0;
    while (n < cmd.words.length && assignmentOf(cmd.words[n])) n++;
    const assigns = cmd.words.slice(0, n);
    const words = cmd.words.slice(n);
    const values = [];
    for (const a of assigns) {
      const name = assignmentOf(a);
      values.push([name, await wordValue(a, ctx, errs, name.length + 1)]);
    }
    // NAME=value alone sets it for what follows; before a command, only for it.
    if (!words.length && !cmd.redirects.length && !cmd.heredoc) {
      for (const [n, v] of values) state.vars.set(n, v);
      return { code: 0, out: '', err: errs.join('') };
    }
    const saved = values.map(([n]) => [n, state.vars.has(n) ? state.vars.get(n) : undefined]);
    for (const [n, v] of values) state.vars.set(n, v);
    try {
      let argv;
      try {
        argv = await expandGlobs(await expandWords(words, ctx, errs));
      } catch (e) {
        return { code: 1, out: '', err: `${errs.join('')}cic.sh: ${e.message}\n` };
      }
      const { input, missing } = await inputOf(cmd, stdin, ctx, errs);
      if (missing !== undefined) return fail(1, `${errs.join('')}cic.sh: ${missing}: No such file or directory`);
      const res = argv.length ? await runArgv(argv.map((w) => (typeof w === 'string' ? w : w.v)), input, ctx) : ok(input ?? '');
      return redirectOutput(res, cmd.redirects, ctx, errs);
    } finally {
      if (words.length) for (const [n, v] of saved) (v === undefined ? state.vars.delete(n) : state.vars.set(n, v));
    }
  }

  async function runCompound(node, stdin, ctx) {
    const errs = [];
    const { input, missing } = await inputOf(node, stdin, ctx, errs);
    if (missing !== undefined) return fail(1, `cic.sh: ${missing}: No such file or directory`);
    const inner = { ...ctx, stream: input !== null && input !== undefined ? { text: input, pos: 0 } : ctx.stream };
    const res = { code: 0, out: '', err: '' };
    let flags = {};
    const take = (r) => { res.out += r.out; res.err += r.err; res.code = r.code; };
    // What a loop body's break/continue/exit means for the loop: true = stop it.
    const loopStops = (r) => {
      if (r.exit || r.needsConfirm) { flags = flagsOf(r); return true; }
      if (r.brk) { if (r.brk > 1) flags = { brk: r.brk - 1 }; return true; }
      if (r.cont > 1) { flags = { cont: r.cont - 1 }; return true; }
      return false;
    };
    if (node.compound === 'if') {
      let ran = false;
      for (const b of node.branches) {
        const c = await runList(b.cond, { ...inner, cond: true });
        take(c);
        if (c.exit || c.needsConfirm) { flags = flagsOf(c); ran = true; break; }
        if (c.code === 0) { const r = await runList(b.body, inner); take(r); flags = flagsOf(r); ran = true; break; }
      }
      if (!ran) {
        if (node.otherwise) { const r = await runList(node.otherwise, inner); take(r); flags = flagsOf(r); } else res.code = 0;
      }
    } else if (node.compound === 'for') {
      let items;
      try {
        items = (await expandGlobs(await expandWords(node.words, ctx, errs))).map((w) => (typeof w === 'string' ? w : w.v));
      } catch (e) {
        return fail(1, `for: ${e.message}`);
      }
      if (items.length > 10000) return fail(1, `for: ${items.length} items; at most 10000`);
      for (const it of items) {
        state.vars.set(node.name, it);
        const r = await runList(node.body, inner);
        take(r);
        if (loopStops(r)) break;
      }
    } else {
      // while / until: bounded, since every turn may be a request to the site.
      for (let turn = 1; ; turn++) {
        if (turn > 1000) { res.err += `${node.compound}: stopped after 1000 turns - does the loop ever end?\n`; res.code = 1; break; }
        const c = await runList(node.cond, { ...inner, cond: true });
        res.out += c.out;
        res.err += c.err;
        if (c.exit || c.needsConfirm) { flags = flagsOf(c); break; }
        if ((c.code === 0) !== (node.compound === 'while')) break;
        const r = await runList(node.body, inner);
        take(r);
        if (loopStops(r)) break;
      }
    }
    return redirectOutput({ ...res, ...flags }, node.redirects, ctx, errs);
  }

  async function runPipeline(pipeline, ctx) {
    let input = null;
    let out = '';
    let err = '';
    let code = 0;
    let flags = {};
    const needsConfirm = [];
    for (let k = 0; k < pipeline.length; k++) {
      const el = pipeline[k];
      const r = el.compound ? await runCompound(el, input, ctx) : await runCommand(el, input, ctx);
      err += r.err;
      code = r.code;
      if (k === pipeline.length - 1) out += r.out;
      else input = r.out;
      flags = { ...flags, ...flagsOf({ ...r, needsConfirm: undefined }) };
      if (r.needsConfirm) needsConfirm.push(...r.needsConfirm);
    }
    return { code, out, err, ...flags, ...(needsConfirm.length ? { needsConfirm } : {}) };
  }

  // A list: a && b runs b only if a succeeded, a || b only if it failed; a
  // skipped pipeline leaves the status as it was, as in sh. A refusal that
  // needs confirming stops everything: what follows may depend on it.
  async function runList(list, ctx) {
    let out = '';
    let err = '';
    let code = 0;
    let joined = ';';
    for (const { pipeline, then } of list) {
      const skip = (joined === '&&' && code !== 0) || (joined === '||' && code === 0);
      joined = then;
      if (skip) continue;
      const r = await runPipeline(pipeline, ctx);
      out += r.out;
      err += r.err;
      code = r.code;
      state.last = code;
      if (r.needsConfirm || r.exit || r.brk || r.cont) return { code, out, err, ...flagsOf(r) };
      // set -e: a failure that nothing tests ends the line.
      if (ctx.opts?.errexit && code !== 0 && !ctx.cond && then === ';') return { code, out, err, exit: true };
    }
    return { code, out, err };
  }

  /** Run a command line -> { code, stdout, stderr }. */
  async function run(line, { confirm = false } = {}) {
    let list;
    try {
      list = parse(String(line ?? ''));
    } catch (e) {
      return { code: 2, stdout: '', stderr: `cic.sh: ${e.message}\n` };
    }
    listed.clear();
    const r = await runList(list, { confirm, opts: { errexit: false }, stream: null, cond: false });
    let stderr = r.err;
    if (r.needsConfirm?.length) {
      stderr += `cic.sh: stopped - nothing after this ran. To go ahead: ${r.needsConfirm.map((c) => `cic.sh(${JSON.stringify(c)}, { confirm: true })`).join(', then ')}\n`;
    }
    return { code: r.code, stdout: r.out, stderr, ...(r.needsConfirm?.length ? { needsConfirm: r.needsConfirm } : {}) };
  }

  commands.exit = async (args) => ({ code: Number(args[0] ?? 0) || 0, out: '', err: '', exit: true });
  return { run, get cwd() { return state.cwd; }, commands: Object.keys(commands).sort() };
}
