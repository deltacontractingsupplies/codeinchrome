/*
 * awk for cic.sh: the language as agents use it on the command line -
 * awk -F: '{print $2}', awk 'NR==2', '{s+=$3} END{print s}',
 * '{n[$1]++} END{for (k in n) print k, n[k]}', printf, sub/gsub, split.
 *
 * Parsed here and walked as a tree - nothing is ever evaluated as code.
 * Not here: getline, output redirection (print > "file"), user functions,
 * and uninitialized-value subtleties beyond "" and 0.
 */

class AwkError extends Error {}

const NUMERIC = /^\s*[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\s*$/;
const KEYWORDS = new Set(['BEGIN', 'END', 'if', 'else', 'while', 'for', 'do', 'in', 'next', 'exit', 'print', 'printf', 'delete', 'getline', 'function', 'return', 'break', 'continue']);
const BUILTINS = new Set(['length', 'substr', 'index', 'split', 'sub', 'gsub', 'match', 'sprintf', 'tolower', 'toupper', 'int', 'sqrt', 'exp', 'log', 'sin', 'cos', 'atan2', 'rand', 'srand']);

/* ───────────── tokens ───────────── */

function lex(src) {
  const toks = [];
  let i = 0;
  // A "/" starts a regular expression unless it follows something that has a value.
  const valueBefore = () => {
    const t = toks.at(-1);
    return t && (t.k === 'num' || t.k === 'str' || t.k === 'name' || t.k === 'builtin' || (t.k === 'op' && [')', ']', '$', '++', '--'].includes(t.v)));
  };
  while (i < src.length) {
    const c = src[i];
    if (c === '\\' && src[i + 1] === '\n') { i += 2; continue; }
    if (c === ' ' || c === '\t' || c === '\r') { i++; continue; }
    if (c === '#') { while (i < src.length && src[i] !== '\n') i++; continue; }
    if (c === '\n' || c === ';') { toks.push({ k: c === ';' ? 'semi' : 'nl' }); i++; continue; }
    if (c === '"') {
      let s = '';
      for (i++; i < src.length && src[i] !== '"'; i++) {
        if (src[i] === '\\') {
          const n = src[++i];
          s += n === 'n' ? '\n' : n === 't' ? '\t' : n === 'r' ? '\r' : n === '\\' ? '\\' : n === '"' ? '"' : n === '/' ? '/' : `\\${n}`;
        } else s += src[i];
      }
      if (i >= src.length) throw new AwkError('a string is not closed');
      i++;
      toks.push({ k: 'str', v: s });
      continue;
    }
    if (c === '/' && !valueBefore()) {
      let s = '';
      for (i++; i < src.length && src[i] !== '/'; i++) {
        if (src[i] === '\\' && src[i + 1] === '/') { s += '/'; i++; } else if (src[i] === '\\') { s += src[i] + src[i + 1]; i++; } else s += src[i];
      }
      if (i >= src.length) throw new AwkError('a regular expression is not closed');
      i++;
      toks.push({ k: 're', v: s });
      continue;
    }
    const num = /^(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?/.exec(src.slice(i));
    if (num) { toks.push({ k: 'num', v: Number(num[0]) }); i += num[0].length; continue; }
    const name = /^[A-Za-z_]\w*/.exec(src.slice(i));
    if (name) {
      const v = name[0];
      toks.push({ k: KEYWORDS.has(v) ? 'kw' : BUILTINS.has(v) ? 'builtin' : 'name', v });
      i += v.length;
      continue;
    }
    const op = /^(\+\+|--|\+=|-=|\*=|\/=|%=|\^=|==|!=|<=|>=|&&|\|\||!~|[-+*/%^<>!~?:=(){}[\],$|])/.exec(src.slice(i));
    if (!op) throw new AwkError(`unexpected "${c}"`);
    toks.push({ k: 'op', v: op[0] });
    i += op[0].length;
  }
  toks.push({ k: 'eof' });
  return toks;
}

/* ───────────── parsing ───────────── */

function parseAwk(src) {
  const toks = lex(src);
  let p = 0;
  const peek = (o = 0) => toks[p + o];
  const is = (k, v) => peek().k === k && (v === undefined || peek().v === v);
  const isOp = (v) => is('op', v);
  const eat = (k, v) => {
    if (!is(k, v)) throw new AwkError(`syntax error near "${peek().v ?? peek().k}"${v ? ` (expected "${v}")` : ''}`);
    return toks[p++];
  };
  const nls = () => { while (is('nl')) p++; };
  const terms = () => { while (is('nl') || is('semi')) p++; };

  // Expressions, lowest precedence first. noIn: inside for(;;) heads; noGt:
  // print's arguments, where > would be a redirection.
  function expr(o = {}) { return ternary(o); }
  function ternary(o) {
    const c = orExpr(o);
    if (isOp('?')) { p++; nls(); const a = ternary(o); nls(); eat('op', ':'); nls(); const b = ternary(o); return { t: 'cond', c, a, b }; }
    if (['=', '+=', '-=', '*=', '/=', '%=', '^='].includes(peek().v) && peek().k === 'op' && ['var', 'index', 'field'].includes(c.t)) {
      const op = toks[p++].v;
      nls();
      return { t: 'assign', op, target: c, value: ternary(o) };
    }
    return c;
  }
  function orExpr(o) { let l = andExpr(o); while (isOp('||')) { p++; nls(); l = { t: 'or', l, r: andExpr(o) }; } return l; }
  function andExpr(o) { let l = inExpr(o); while (isOp('&&')) { p++; nls(); l = { t: 'and', l, r: inExpr(o) }; } return l; }
  function inExpr(o) {
    let l = matchExpr(o);
    while (!o.noIn && is('kw', 'in')) { p++; l = { t: 'in', key: l, arr: eat('name').v }; }
    return l;
  }
  function matchExpr(o) {
    let l = compare(o);
    while (isOp('~') || isOp('!~')) { const neg = toks[p++].v === '!~'; l = { t: 'match', l, r: compare(o), neg }; }
    return l;
  }
  function compare(o) {
    let l = concat(o);
    while (['<', '<=', '>', '>=', '==', '!='].includes(peek().v) && peek().k === 'op' && !(o.noGt && peek().v === '>')) {
      const op = toks[p++].v;
      l = { t: 'cmp', op, l, r: concat(o) };
    }
    return l;
  }
  // Concatenation is two expressions side by side.
  const startsValue = () => {
    const t = peek();
    return ['num', 'str', 're', 'name', 'builtin'].includes(t.k) || (t.k === 'op' && ['$', '(', '!', '-', '+', '++', '--'].includes(t.v));
  };
  function concat(o) {
    let l = additive(o);
    while (startsValue() && !(peek().k === 'op' && ['-', '+'].includes(peek().v)) && !is('kw', 'in')) l = { t: 'concat', l, r: additive(o) };
    return l;
  }
  function additive(o) { let l = mult(o); while (isOp('+') || isOp('-')) { const op = toks[p++].v; l = { t: 'bin', op, l, r: mult(o) }; } return l; }
  function mult(o) { let l = unary(o); while (isOp('*') || isOp('/') || isOp('%')) { const op = toks[p++].v; l = { t: 'bin', op, l, r: unary(o) }; } return l; }
  function unary(o) {
    if (isOp('!')) { p++; return { t: 'not', e: unary(o) }; }
    if (isOp('-')) { p++; return { t: 'neg', e: unary(o) }; }
    if (isOp('+')) { p++; return { t: 'num', e: unary(o) }; }
    return power(o);
  }
  function power(o) {
    const b = postfix(o);
    if (isOp('^')) { p++; return { t: 'bin', op: '^', l: b, r: unary(o) }; }
    return b;
  }
  function postfix(o) {
    if (isOp('++') || isOp('--')) { const op = toks[p++].v; return { t: 'incr', op, pre: true, target: postfix(o) }; }
    const e = primary(o);
    if ((isOp('++') || isOp('--')) && ['var', 'index', 'field'].includes(e.t)) { const op = toks[p++].v; return { t: 'incr', op, pre: false, target: e }; }
    return e;
  }
  function primary(o) {
    const t = peek();
    if (t.k === 'num') { p++; return { t: 'lit', v: t.v }; }
    if (t.k === 'str') { p++; return { t: 'lit', v: t.v }; }
    if (t.k === 're') { p++; return { t: 're', v: t.v }; }
    if (isOp('$')) { p++; return { t: 'field', e: postfixNoIncr(o) }; }
    if (isOp('(')) {
      p++;
      const e = expr({});
      if (isOp(',')) { // (a, b) in arr
        const keys = [e];
        while (isOp(',')) { p++; keys.push(expr({})); }
        eat('op', ')');
        if (!is('kw', 'in')) throw new AwkError('a list in parentheses is only for "(a, b) in array"');
        p++;
        return { t: 'in', key: { t: 'keys', keys }, arr: eat('name').v };
      }
      eat('op', ')');
      return { t: 'group', e };
    }
    if (t.k === 'builtin') {
      p++;
      const args = [];
      if (isOp('(')) {
        p++;
        nls();
        if (!isOp(')')) { args.push(expr({})); while (isOp(',')) { p++; nls(); args.push(expr({})); } }
        eat('op', ')');
      }
      return { t: 'call', f: t.v, args };
    }
    if (t.k === 'name') {
      p++;
      if (isOp('[')) {
        p++;
        const keys = [expr({})];
        while (isOp(',')) { p++; keys.push(expr({})); }
        eat('op', ']');
        return { t: 'index', name: t.v, keys };
      }
      return { t: 'var', name: t.v };
    }
    if (t.k === 'kw' && t.v === 'getline') throw new AwkError('getline is not supported here');
    throw new AwkError(`syntax error near "${t.v ?? t.k}"`);
  }
  // $NF++ means ($NF)++: the field's operand takes no increment.
  function postfixNoIncr(o) {
    if (isOp('-')) { p++; return { t: 'neg', e: postfixNoIncr(o) }; }
    return primary(o);
  }

  function simpleOrBlock() {
    nls();
    return isOp('{') ? block() : statement();
  }
  function block() {
    eat('op', '{');
    const body = [];
    terms();
    while (!isOp('}')) {
      body.push(statement());
      terms();
    }
    eat('op', '}');
    return { t: 'block', body };
  }
  function exprList(o) {
    const list = [];
    if (is('nl') || is('semi') || isOp('}') || is('eof')) return list;
    list.push(expr(o));
    while (isOp(',')) { p++; nls(); list.push(expr(o)); }
    return list;
  }
  function statement() {
    const t = peek();
    if (isOp('{')) return block();
    if (t.k === 'kw') {
      if (t.v === 'if') {
        p++; eat('op', '('); const c = expr({}); eat('op', ')');
        const a = simpleOrBlock();
        const save = p;
        terms();
        if (is('kw', 'else')) { p++; return { t: 'if', c, a, b: simpleOrBlock() }; }
        p = save;
        return { t: 'if', c, a };
      }
      if (t.v === 'while') { p++; eat('op', '('); const c = expr({}); eat('op', ')'); return { t: 'while', c, body: simpleOrBlock() }; }
      if (t.v === 'do') {
        p++;
        const body = simpleOrBlock();
        terms();
        eat('kw', 'while'); eat('op', '('); const c = expr({}); eat('op', ')');
        return { t: 'do', c, body };
      }
      if (t.v === 'for') {
        p++; eat('op', '(');
        if (peek().k === 'name' && peek(1).k === 'kw' && peek(1).v === 'in' && peek(2).k === 'name' && peek(3).v === ')') {
          const key = toks[p].v; const arr = toks[p + 2].v; p += 4;
          return { t: 'forin', key, arr, body: simpleOrBlock() };
        }
        const init = isOp(';') || is('semi') ? null : expr({ noIn: true }); eat('semi');
        const c = is('semi') ? null : expr({}); eat('semi');
        const step = isOp(')') ? null : expr({}); eat('op', ')');
        return { t: 'for', init, c, step, body: simpleOrBlock() };
      }
      if (t.v === 'next') { p++; return { t: 'next' }; }
      if (t.v === 'break') { p++; return { t: 'break' }; }
      if (t.v === 'continue') { p++; return { t: 'continue' }; }
      if (t.v === 'exit') { p++; return { t: 'exit', e: is('nl') || is('semi') || isOp('}') || is('eof') ? null : expr({}) }; }
      if (t.v === 'delete') {
        p++;
        const name = eat('name').v;
        if (!isOp('[')) return { t: 'delete', name };
        p++; const keys = [expr({})]; while (isOp(',')) { p++; keys.push(expr({})); } eat('op', ']');
        return { t: 'delete', name, keys };
      }
      if (t.v === 'print' || t.v === 'printf') {
        p++;
        let args;
        // print(a, b) and print (a, b): the parentheses are the list's.
        if (isOp('(')) {
          const save = p;
          p++;
          const inner = [];
          if (!isOp(')')) { inner.push(expr({})); while (isOp(',')) { p++; inner.push(expr({})); } }
          if (isOp(')') && (peek(1).k === 'nl' || peek(1).k === 'semi' || peek(1).v === '}' || peek(1).k === 'eof' || peek(1).v === '>')) { p++; args = inner; } else { p = save; args = exprList({ noGt: true }); }
        } else args = exprList({ noGt: true });
        if (isOp('>') || isOp('|')) throw new AwkError(`${t.v} > file and ${t.v} | command are not supported here: use the shell's redirection on the whole awk`);
        return { t: t.v, args };
      }
      if (t.v === 'function' || t.v === 'return') throw new AwkError('user-defined functions are not supported here');
      if (t.v === 'getline') throw new AwkError('getline is not supported here');
    }
    return { t: 'expr', e: expr({}) };
  }

  const rules = [];
  terms();
  while (!is('eof')) {
    if (is('kw', 'BEGIN') || is('kw', 'END')) {
      const kind = toks[p++].v;
      nls();
      rules.push({ kind, action: block() });
    } else if (isOp('{')) {
      rules.push({ kind: 'main', action: block() });
    } else {
      const pat = expr({});
      let pat2 = null;
      if (isOp(',')) { p++; nls(); pat2 = expr({}); }
      const action = isOp('{') ? block() : null;
      rules.push({ kind: 'main', pat, pat2, action, inRange: false });
    }
    terms();
  }
  return rules;
}

/* ───────────── running ───────────── */

const num = (v) => {
  if (typeof v === 'number') return v;
  const m = /^\s*[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?/.exec(String(v ?? ''));
  return m ? Number(m[0]) : 0;
};
const fmtNum = (n) => (Number.isInteger(n) ? String(n) : String(Number(n.toPrecision(6))));
const str = (v) => (typeof v === 'number' ? fmtNum(v) : String(v ?? ''));
// A field or input value that looks like a number compares as one.
const strnum = (v) => typeof v === 'number' || (v instanceof StrNum);
class StrNum { constructor(s) { this.s = s; } toString() { return this.s; } }
const plain = (v) => (v instanceof StrNum ? v.s : v);
const looksNumeric = (v) => typeof v === 'number' || (v instanceof StrNum && NUMERIC.test(v.s));

export function sprintf(fmt, args) {
  let k = 0;
  return String(fmt).replace(/%([-+ 0#]*)(\*|\d+)?(?:\.(\*|\d+))?([diouxXeEfgGcs%])/g, (m, flags, width, prec, conv) => {
    if (conv === '%') return '%';
    if (width === '*') width = String(num(args[k++]));
    if (prec === '*') prec = String(num(args[k++]));
    const v = args[k++];
    let s;
    switch (conv) {
      case 'd': case 'i': s = String(Math.trunc(num(v))); break;
      case 'o': s = Math.trunc(num(v)).toString(8); break;
      case 'u': s = String(Math.abs(Math.trunc(num(v)))); break;
      case 'x': s = Math.trunc(num(v)).toString(16); break;
      case 'X': s = Math.trunc(num(v)).toString(16).toUpperCase(); break;
      case 'e': case 'E': s = num(v).toExponential(prec === undefined ? 6 : Number(prec)); if (conv === 'E') s = s.toUpperCase(); s = s.replace(/e([+-])(\d)$/i, (x, sg, d) => `${conv}${sg}0${d}`); break;
      case 'f': s = num(v).toFixed(prec === undefined ? 6 : Number(prec)); break;
      case 'g': case 'G': s = String(Number(num(v).toPrecision(prec === undefined ? 6 : Math.max(1, Number(prec))))); break;
      case 'c': s = typeof v === 'number' ? String.fromCharCode(v) : str(plain(v)).slice(0, 1); break;
      default: s = str(plain(v)); if (prec !== undefined) s = s.slice(0, Number(prec));
    }
    if (flags.includes('+') && /[dieEfgG]/.test(conv) && num(v) >= 0) s = `+${s}`;
    const w = Number(width ?? 0);
    if (s.length < w) {
      if (flags.includes('-')) s = s.padEnd(w);
      else if (flags.includes('0') && /[diouxXeEfgG]/.test(conv)) s = (s[0] === '-' || s[0] === '+') ? s[0] + s.slice(1).padStart(w - 1, '0') : s.padStart(w, '0');
      else s = s.padStart(w);
    }
    return s;
  });
}

// awk's regular expressions -> JavaScript's (ERE; the same for these purposes).
const reCache = new Map();
function regex(src, flags = '') {
  const key = `${flags}/${src}`;
  if (!reCache.has(key)) {
    try { reCache.set(key, new RegExp(src, flags)); } catch (e) { throw new AwkError(`bad regular expression /${src}/: ${e.message}`); }
  }
  return reCache.get(key);
}

class Next {}
class Exit { constructor(code) { this.code = code; } }
class Break {}
class Continue {}

/**
 * program, [{ name, text }], { fs, vars } -> { out, code }. Throws AwkError
 * for a program it cannot parse or run.
 */
export function runAwk(program, inputs, { fs = null, vars = {} } = {}) {
  const rules = parseAwk(program);
  const V = new Map([['FS', fs ?? ' '], ['OFS', ' '], ['ORS', '\n'], ['NR', 0], ['NF', 0], ['FNR', 0], ['FILENAME', ''], ['SUBSEP', '\x1c'], ['RSTART', 0], ['RLENGTH', -1]]);
  for (const [k, v] of Object.entries(vars)) V.set(k, new StrNum(v));
  const arrays = new Map();
  let fields = [''];
  let out = '';
  let steps = 0;

  const arr = (name) => {
    if (V.has(name) && !arrays.has(name)) throw new AwkError(`${name} is a scalar, not an array`);
    if (!arrays.has(name)) arrays.set(name, new Map());
    return arrays.get(name);
  };
  const split = (text, sep) => {
    const s = str(sep);
    if (s === ' ') return text.split(/[ \t\n]+/).filter(Boolean);
    if (text === '') return [];
    if (s.length === 1 && s !== '\\') return text.split(s);
    return text.split(regex(s));
  };
  const setRecord = (text) => {
    fields = [text, ...split(text, V.get('FS')).map((f) => new StrNum(f))];
    V.set('NF', fields.length - 1);
  };
  const rebuild = () => { fields[0] = fields.slice(1).map((f) => str(plain(f))).join(str(V.get('OFS'))); };
  const getField = (n) => {
    const i = Math.trunc(num(n));
    if (i < 0) throw new AwkError(`field $${i} does not exist`);
    if (i === 0) return new StrNum(str(fields[0]));
    return fields[i] ?? '';
  };
  const setField = (n, v) => {
    const i = Math.trunc(num(n));
    if (i === 0) { setRecord(str(plain(v))); return; }
    while (fields.length <= i) fields.push('');
    fields[i] = v;
    V.set('NF', fields.length - 1);
    rebuild();
  };
  const key = (keys) => keys.map((k) => str(plain(ev(k)))).join(str(V.get('SUBSEP')));

  const truthy = (v) => (looksNumeric(v) ? num(plain(v)) !== 0 : str(plain(v)) !== '');
  const matches = (v, re) => (re.t === 're' ? regex(re.v) : regex(str(plain(ev(re))))).test(str(plain(v)));

  function assign(target, value) {
    if (target.t === 'var') {
      if (arrays.has(target.name)) throw new AwkError(`${target.name} is an array`);
      V.set(target.name, value);
      if (target.name === 'NF') { const n = Math.trunc(num(value)); fields = fields.slice(0, n + 1); while (fields.length <= n) fields.push(''); rebuild(); }
    } else if (target.t === 'index') arr(target.name).set(key(target.keys), value);
    else if (target.t === 'field') setField(ev(target.e), value);
    return value;
  }
  function read(target) {
    if (target.t === 'var') {
      if (target.name === 'NF') return fields.length - 1;
      return V.has(target.name) ? V.get(target.name) : '';
    }
    if (target.t === 'index') { const a = arr(target.name); const k = key(target.keys); if (!a.has(k)) a.set(k, ''); return a.get(k); }
    return getField(ev(target.e));
  }

  function ev(e) {
    if (++steps > 5e6) throw new AwkError('stopped: the program ran too long');
    switch (e.t) {
      case 'lit': return e.v;
      case 're': return regex(e.v).test(str(fields[0])) ? 1 : 0;
      case 'group': return ev(e.e);
      case 'var': case 'index': case 'field': return read(e);
      case 'assign': {
        // A copied field keeps comparing as a number when it looks like one.
        const v = ev(e.value);
        if (e.op === '=') return assign(e.target, v);
        const cur = num(plain(read(e.target)));
        const r = num(plain(v));
        const n = e.op === '+=' ? cur + r : e.op === '-=' ? cur - r : e.op === '*=' ? cur * r : e.op === '/=' ? div(cur, r) : e.op === '%=' ? cur % r : cur ** r;
        return assign(e.target, n);
      }
      case 'incr': {
        const cur = num(plain(read(e.target)));
        const n = e.op === '++' ? cur + 1 : cur - 1;
        assign(e.target, n);
        return e.pre ? n : cur;
      }
      case 'cond': return truthy(ev(e.c)) ? ev(e.a) : ev(e.b);
      case 'or': return truthy(ev(e.l)) || truthy(ev(e.r)) ? 1 : 0;
      case 'and': return truthy(ev(e.l)) && truthy(ev(e.r)) ? 1 : 0;
      case 'not': return truthy(ev(e.e)) ? 0 : 1;
      case 'neg': return -num(plain(ev(e.e)));
      case 'num': return num(plain(ev(e.e)));
      case 'in': return arr(e.arr).has(e.key.t === 'keys' ? key(e.key.keys) : str(plain(ev(e.key)))) ? 1 : 0;
      case 'match': { const r = matches(ev(e.l), e.r); return (e.neg ? !r : r) ? 1 : 0; }
      case 'cmp': {
        const l = ev(e.l);
        const r = ev(e.r);
        const numeric = looksNumeric(l) && looksNumeric(r);
        const a = numeric ? num(plain(l)) : str(plain(l));
        const b = numeric ? num(plain(r)) : str(plain(r));
        switch (e.op) {
          case '<': return a < b ? 1 : 0;
          case '<=': return a <= b ? 1 : 0;
          case '>': return a > b ? 1 : 0;
          case '>=': return a >= b ? 1 : 0;
          case '==': return a === b ? 1 : 0;
          default: return a !== b ? 1 : 0;
        }
      }
      case 'concat': return str(plain(ev(e.l))) + str(plain(ev(e.r)));
      case 'bin': {
        const a = num(plain(ev(e.l)));
        const b = num(plain(ev(e.r)));
        switch (e.op) {
          case '+': return a + b;
          case '-': return a - b;
          case '*': return a * b;
          case '/': return div(a, b);
          case '%': if (b === 0) throw new AwkError('division by zero in %'); return a % b;
          default: return a ** b;
        }
      }
      case 'call': return call(e);
      default: throw new AwkError(`cannot evaluate ${e.t}`);
    }
  }
  function div(a, b) { if (b === 0) throw new AwkError('division by zero'); return a / b; }

  function call(e) {
    const a = e.args;
    const s = (k) => str(plain(ev(a[k])));
    switch (e.f) {
      case 'length':
        if (!a.length) return str(fields[0]).length;
        if (a[0].t === 'var' && arrays.has(a[0].name)) return arrays.get(a[0].name).size;
        return s(0).length;
      case 'substr': {
        const text = s(0);
        let m = Math.round(num(plain(ev(a[1]))));
        let n = a[2] ? Math.round(num(plain(ev(a[2])))) : Infinity;
        if (m < 1) { n += m - 1; m = 1; }
        return n <= 0 ? '' : text.slice(m - 1, n === Infinity ? undefined : m - 1 + n);
      }
      case 'index': return s(0).indexOf(s(1)) + 1;
      case 'split': {
        if (a[1]?.t !== 'var') throw new AwkError('split: the second argument must be an array name');
        const parts = split(s(0), a[2] ? (a[2].t === 're' ? a[2].v : s(2)) : V.get('FS'));
        const target = arr(a[1].name);
        target.clear();
        parts.forEach((x, k) => target.set(String(k + 1), new StrNum(x)));
        return parts.length;
      }
      case 'sub': case 'gsub': {
        const re = a[0].t === 're' ? regex(a[0].v, e.f === 'gsub' ? 'g' : '') : regex(s(0), e.f === 'gsub' ? 'g' : '');
        const repl = s(1);
        const target = a[2] ?? { t: 'field', e: { t: 'lit', v: 0 } };
        const before = str(plain(read(target)));
        let n = 0;
        const after = before.replace(re, (m) => { n++; return repl.replace(/\\\\|\\&|&/g, (x) => (x === '\\\\' ? '\\' : x === '\\&' ? '&' : m)); });
        if (n) assign(target, after);
        return n;
      }
      case 'match': {
        const m = (a[1].t === 're' ? regex(a[1].v) : regex(s(1))).exec(s(0));
        V.set('RSTART', m ? m.index + 1 : 0);
        V.set('RLENGTH', m ? m[0].length : -1);
        return m ? m.index + 1 : 0;
      }
      case 'sprintf': return sprintf(s(0), a.slice(1).map((x) => plain(ev(x))));
      case 'tolower': return s(0).toLowerCase();
      case 'toupper': return s(0).toUpperCase();
      case 'int': return Math.trunc(num(plain(ev(a[0]))));
      case 'sqrt': return Math.sqrt(num(plain(ev(a[0]))));
      case 'exp': return Math.exp(num(plain(ev(a[0]))));
      case 'log': return Math.log(num(plain(ev(a[0]))));
      case 'sin': return Math.sin(num(plain(ev(a[0]))));
      case 'cos': return Math.cos(num(plain(ev(a[0]))));
      case 'atan2': return Math.atan2(num(plain(ev(a[0]))), num(plain(ev(a[1]))));
      case 'rand': return Math.random();
      case 'srand': return 0;
      default: throw new AwkError(`${e.f} is not supported here`);
    }
  }

  function exec(st) {
    if (++steps > 5e6) throw new AwkError('stopped: the program ran too long');
    switch (st.t) {
      case 'block': for (const s of st.body) exec(s); return;
      case 'expr': ev(st.e); return;
      case 'print': {
        const items = st.args.length ? st.args.map((x) => str(plain(ev(x)))) : [str(fields[0])];
        out += items.join(str(V.get('OFS'))) + str(V.get('ORS'));
        if (out.length > 5e6) throw new AwkError('stopped: more than 5 MB of output');
        return;
      }
      case 'printf': {
        if (!st.args.length) throw new AwkError('printf needs a format');
        out += sprintf(str(plain(ev(st.args[0]))), st.args.slice(1).map((x) => plain(ev(x))));
        return;
      }
      case 'if': if (truthy(ev(st.c))) exec(st.a); else if (st.b) exec(st.b); return;
      case 'while': case 'do': case 'for': case 'forin': return loop(st);
      case 'next': throw new Next();
      case 'exit': throw new Exit(st.e ? Math.trunc(num(plain(ev(st.e)))) : 0);
      case 'break': throw new Break();
      case 'continue': throw new Continue();
      case 'delete': if (st.keys) arr(st.name).delete(key(st.keys)); else arr(st.name).clear(); return;
      default: throw new AwkError(`cannot run ${st.t}`);
    }
  }
  function loop(st) {
    const body = () => {
      try { exec(st.body); } catch (x) {
        if (x instanceof Break) return false;
        if (!(x instanceof Continue)) throw x;
      }
      return true;
    };
    if (st.t === 'forin') {
      for (const k of [...arr(st.arr).keys()]) {
        V.set(st.key, NUMERIC.test(k) ? new StrNum(k) : k);
        if (!body()) break;
      }
      return;
    }
    if (st.t === 'for' && st.init) ev(st.init);
    if (st.t === 'do') { if (!body()) return; }
    while (st.t === 'for' && !st.c ? true : truthy(ev(st.c))) {
      if (!body()) break;
      if (st.t === 'for' && st.step) ev(st.step);
    }
  }

  let code = 0;
  const runRules = (kind) => {
    for (const r of rules) if (r.kind === kind) exec(r.action);
  };
  try {
    runRules('BEGIN');
    const mains = rules.filter((r) => r.kind === 'main');
    if (mains.length || rules.some((r) => r.kind === 'END')) {
      for (const input of inputs) {
        V.set('FILENAME', input.name);
        V.set('FNR', 0);
        const lines = input.text.split('\n');
        if (lines.at(-1) === '') lines.pop();
        for (const line of lines) {
          V.set('NR', num(V.get('NR')) + 1);
          V.set('FNR', num(V.get('FNR')) + 1);
          setRecord(line);
          try {
            for (const r of mains) {
              let hit;
              if (!r.pat) hit = true;
              else if (r.pat2) {
                if (r.inRange) { hit = true; if (truthy(ev(r.pat2))) r.inRange = false; } else if (truthy(ev(r.pat))) { hit = true; r.inRange = !truthy(ev(r.pat2)); } else hit = false;
              } else hit = truthy(ev(r.pat));
              if (!hit) continue;
              if (r.action) exec(r.action);
              else out += str(fields[0]) + str(V.get('ORS'));
            }
          } catch (x) {
            if (!(x instanceof Next)) throw x;
          }
        }
      }
    }
    runRules('END');
  } catch (x) {
    if (x instanceof Exit) {
      code = x.code;
    } else if (x instanceof Break || x instanceof Continue) {
      throw new AwkError('break or continue outside a loop');
    } else throw x;
  }
  return { out, code };
}

export { AwkError };
