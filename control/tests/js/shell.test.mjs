// cic.sh against an in-memory site: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createShell, parse, breToEre, unifiedDiff, globToRegExp } from '../../resources/js/shell.js';

const SKIP = ['vendor', 'node_modules', '.git', 'storage/framework', 'bootstrap/cache', 'public/build'];

/** A site in memory, answering the way the editor's API does. */
function memorySite(files = {}) {
  const now = Math.floor(Date.now() / 1000);
  const nodes = new Map([['/', { dir: true, mtime: now }]]);
  const versions = new Map();
  const calls = [];
  let rev = 0;
  const parent = (p) => p.slice(0, p.lastIndexOf('/')) || '/';
  const ensureDirs = (p) => {
    for (let d = parent(p); !nodes.has(d); d = parent(d)) nodes.set(d, { dir: true, mtime: now });
    for (let d = parent(p); d !== '/'; d = parent(d)) if (!nodes.has(d)) nodes.set(d, { dir: true, mtime: now });
  };
  const put = (p, content, mtime = now) => {
    ensureDirs(p);
    const r = `r${++rev}`;
    nodes.set(p, { dir: false, content, mtime, revision: r });
    if (!versions.has(p)) versions.set(p, []);
    versions.get(p).unshift({ commit: `${String(rev).padStart(7, 'c')}abcdef`, at: '2026-09-25T10:00:00Z', message: `save ${p}`, content });
    return r;
  };
  for (const [p, c] of Object.entries(files)) put(p, c);
  const children = (d) => [...nodes.keys()].filter((p) => p !== '/' && parent(p) === d);
  const skipped = (rel) => SKIP.includes(rel);
  const walk = (start, fn, all, depth = 0, max = Infinity, onSkip = () => {}) => {
    for (const p of children(start).sort()) {
      const n = nodes.get(p);
      fn(p, n, depth + 1);
      if (n.dir && depth + 1 < max && !all && skipped(p.slice(1))) onSkip(p);
      else if (n.dir && depth + 1 < max) walk(p, fn, all, depth + 1, max, onSkip);
    }
  };
  const glob = (g, f = '') => globToRegExp(g, f);

  const io = {
    siteUrl: 'https://shop.codeinchrome.com',
    calls,
    nodes,
    async list(p) {
      calls.push(['list', p]);
      const n = nodes.get(p);
      if (!n) return { ok: false, hint: `${p} does not exist` };
      if (!n.dir) return { ok: false, hint: 'not a folder' };
      return { ok: true, entries: children(p).map((c) => ({ name: c.split('/').pop(), path: c, dir: nodes.get(c).dir, size: nodes.get(c).content?.length ?? 64, mode: '-rw-r--r--', mtime: nodes.get(c).mtime })) };
    },
    async read(p) {
      calls.push(['read', p]);
      const n = nodes.get(p);
      if (!n) return { ok: false, error: 'not_found', hint: `${p} does not exist` };
      if (n.dir) return { ok: false, hint: 'is a directory' };
      return { ok: true, content: n.content, revision: n.revision };
    },
    async readMany(paths) {
      calls.push(['readMany', paths]);
      const out = { files: {}, errors: {} };
      for (const p of paths) {
        const n = nodes.get(p);
        if (n && !n.dir) out.files[p] = n.content;
        else out.errors[p] = n ? 'is a directory' : 'not found';
      }
      return out;
    },
    async write(p, content, expect) {
      calls.push(['write', p]);
      const n = nodes.get(p);
      if (expect && n?.revision !== expect) return { ok: false, hint: 'changed since it was read' };
      put(p, content);
      return { ok: true, ...(p.endsWith('.php') && content.includes('<?php syntax error') ? { syntaxErrors: { [p]: 'unexpected end of file' } } : {}) };
    },
    async writeMany(list) {
      calls.push(['writeMany', list.map((f) => f.path)]);
      if (list.some((f) => f.expect && nodes.get(f.path)?.revision !== f.expect)) return { ok: false, hint: 'a file changed since it was read' };
      for (const f of list) put(f.path, f.content);
      return { ok: true, written: list.map((f) => ({ path: f.path })) };
    },
    async mkdir(p) {
      calls.push(['mkdir', p]);
      if (nodes.has(p)) return { ok: false, hint: 'already exists' };
      ensureDirs(`${p}/x`);
      return { ok: true };
    },
    async move(a, b) {
      calls.push(['move', a, b]);
      if (nodes.has(b)) return { ok: false, hint: 'something already exists at the destination' };
      for (const k of [...nodes.keys()]) if (k === a || k.startsWith(`${a}/`)) { ensureDirs(b + k.slice(a.length)); nodes.set(b + k.slice(a.length), nodes.get(k)); nodes.delete(k); }
      return { ok: true };
    },
    async copy(a, b) {
      calls.push(['copy', a, b]);
      if (nodes.has(b)) return { ok: false, hint: 'something already exists at the destination' };
      for (const k of [...nodes.keys()]) if (k === a || k.startsWith(`${a}/`)) { ensureDirs(b + k.slice(a.length)); nodes.set(b + k.slice(a.length), { ...nodes.get(k) }); }
      return { ok: true };
    },
    async remove(p) {
      calls.push(['remove', p]);
      if (!nodes.get(p) || nodes.get(p).dir) return { ok: false, hint: 'no such file' };
      nodes.delete(p);
      return { ok: true };
    },
    async removeTree(p, confirm) {
      calls.push(['removeTree', p, confirm]);
      if (!confirm) return { ok: false, status: 409, error: 'needs_confirm', hint: 'Pass confirm' };
      for (const k of [...nodes.keys()]) if (k === p || k.startsWith(`${p}/`)) nodes.delete(k);
      return { ok: true };
    },
    async removeEmptyDir(p) {
      calls.push(['removeEmptyDir', p]);
      if (!nodes.get(p)?.dir) return { ok: false, hint: 'no such folder' };
      if (children(p).length) return { ok: false, hint: 'the folder is not empty' };
      nodes.delete(p);
      return { ok: true };
    },
    async grep(o) {
      calls.push(['grep', o]);
      let re;
      try {
        re = new RegExp(o.regex ? o.pattern : o.pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), o.icase ? 'i' : '');
        if (o.word) re = new RegExp(`\\b(?:${re.source})\\b`, re.flags);
      } catch (e) { return { ok: false, hint: e.message }; }
      const start = o.under ?? '/';
      if (!nodes.has(start)) return { ok: false, hint: 'no such file or folder' };
      const inSkip = start !== '/' && SKIP.some((s) => start.slice(1) === s || start.slice(1).startsWith(`${s}/`));
      const hits = [];
      const scan = (p, n) => {
        if (n.dir || /\/\.env/.test(p)) return;
        if (o.include?.length && !o.include.some((g) => glob(g).test(p.split('/').pop()))) return;
        n.content.split('\n').forEach((l, i) => { if (re.test(l)) hits.push({ path: p, line: i + 1, text: l }); });
      };
      if (!nodes.get(start).dir) scan(start, nodes.get(start));
      else walk(start, (p, n) => scan(p, n), inSkip);
      return { ok: true, hits: hits.slice(0, o.limit ?? 200), truncated: hits.length > (o.limit ?? 200) };
    },
    async find(o) {
      calls.push(['find', o]);
      const start = o.under ?? '/';
      if (!nodes.has(start)) return { ok: false, hint: 'no such file or folder' };
      const inSkip = start !== '/' && SKIP.some((s) => start.slice(1) === s || start.slice(1).startsWith(`${s}/`));
      if (!nodes.get(start).dir) return { ok: true, entries: [], files: 1, bytes: nodes.get(start).content.length, truncated: false, skipped: [] };
      const entries = [];
      const skippedDirs = [];
      let files = 0;
      let bytes = 0;
      walk(start, (p, n) => {
        if (!n.dir) { files++; bytes += n.content.length; }
        const name = p.split('/').pop();
        if (o.type === 'f' && n.dir || o.type === 'd' && !n.dir) return;
        if (o.name && !glob(o.name, o.icase ? 'i' : '').test(name)) return;
        if (o.newer && n.mtime <= o.newer) return;
        entries.push({ path: p, dir: n.dir, size: n.content?.length ?? 64, mtime: n.mtime });
      }, o.all || inSkip, 0, o.maxdepth ?? Infinity, (p) => skippedDirs.push(p));
      const limit = o.limit ?? 5000;
      return { ok: true, entries: entries.slice(0, limit), files, bytes, truncated: entries.length > limit, skipped: skippedDirs };
    },
    async history(p) {
      calls.push(['history', p]);
      return versions.has(p) ? { ok: true, versions: versions.get(p).map(({ commit, at, message }) => ({ commit, at, message })) } : { ok: false, hint: 'no versions' };
    },
    async versionAt(p, rev) {
      const v = versions.get(p)?.find((x) => x.commit === rev);
      return v ? { ok: true, content: v.content } : { ok: false, hint: 'no such version' };
    },
    async command(tool, args, confirm) {
      calls.push(['command', tool, args, confirm]);
      if (args[0] === 'migrate:fresh' && !confirm) return { ok: false, status: 409, error: 'needs_confirm' };
      return { ok: true, result: { output: `\x1b[32m${tool} ${args.join(' ')}\x1b[0m done`, exitCode: args[0] === 'test' && args.includes('--fail') ? 1 : 0 } };
    },
    async eval(code) {
      calls.push(['eval', code]);
      return { ok: true, output: `ran: ${code}`, exitCode: 0 };
    },
    async request(path, opts) {
      calls.push(['request', path, opts]);
      if (path === '/missing') return { ok: true, status: 404, headers: { 'content-type': 'text/html' }, body: 'Not Found' };
      return { ok: true, status: 200, headers: { 'content-type': 'text/html' }, body: `${opts.method} ${path}${opts.body ? ` ${opts.body}` : ''}${opts.json ? ` ${JSON.stringify(opts.json)}` : ''}`, ms: 12 };
    },
    async clone(o) {
      calls.push(['clone', o]);
      if (nodes.has(o.into)) return { ok: false, hint: `destination path '${o.into}' already exists` };
      put(`${o.into}/composer.json`, '{}');
      return { ok: true, clone: { repository: 'acme/demo', ref: o.ref || 'HEAD', into: o.into, files: 1, bytes: 2048, ms: 900 } };
    },
    async query(sql, write) {
      calls.push(['query', sql, write]);
      if (/^\s*(delete|update|insert|drop)/i.test(sql) && !write) return { ok: false, status: 409, error: 'needs_write' };
      if (/^\s*select/i.test(sql)) return { ok: true, result: { columns: ['id', 'name'], rows: [[1, 'Tea'], [2, null]] } };
      return { ok: true, result: { columns: [], rows: [], affected: 3 } };
    },
  };
  return io;
}

const site = () => memorySite({
  '/routes/web.php': "<?php\nuse App\\Http\\Controllers\\CartController;\nRoute::get('/', fn () => view('home'));\nRoute::post('/cart', [CartController::class, 'add']);\n",
  '/app/Models/Cart.php': '<?php\n\nnamespace App\\Models;\n\nclass Cart extends Model\n{\n    protected $fillable = [\'total\'];\n}\n',
  '/app/Models/Item.php': '<?php\n\nclass Item extends Model {}\n',
  '/resources/views/home.blade.php': '<h1>Cart</h1>\n<p>{{ $total }}</p>\n',
  '/storage/logs/a.log': 'one\n',
  '/storage/logs/b.log': 'two\n',
  '/vendor/acme/lib/Cart.php': '<?php class VendorCart {}\n',
  '/.env': 'APP_KEY=secret\n',
  '/notes.txt': 'b\na\nb\nc\na\nb\n',
});

async function sh(io, line, opts) {
  const shell = io.shell ?? (io.shell = createShell(io));
  return shell.run(line, opts);
}

test('parsing: quotes, escapes, operators, redirections and here-documents', () => {
  const [a] = parse(`echo 'a $b' "c \\"d\\" $e" f\\ g # comment`);
  assert.deepEqual(a.pipeline[0].words.map((w) => w.v), ['echo', 'a $b', 'c "d" $e', 'f g']);
  const list = parse('ls | grep x && echo ok || echo no; pwd');
  assert.deepEqual(list.map((l) => [l.pipeline.length, l.then]), [[2, '&&'], [1, '||'], [1, ';'], [1, ';']]);
  const [r] = parse('php artisan test > out.txt 2>&1');
  assert.deepEqual(r.pipeline[0].redirects, [{ op: '>', target: 'out.txt' }, { op: '2>&1' }]);
  const [h] = parse("cat > a.php <<'EOF'\n<?php echo \"$x\";\n  indented\nEOF\necho after");
  assert.equal(h.pipeline[0].heredoc.body, '<?php echo "$x";\n  indented\n');
  const [t] = parse('cat <<-END\n\t\tkept\n\tEND');
  assert.equal(t.pipeline[0].heredoc.body, 'kept\n');
  assert.throws(() => parse("echo 'open"), /single quote/);
  assert.throws(() => parse('cat <<EOF\nno end'), /closing line "EOF"/);
  assert.throws(() => parse('| grep x'), /syntax error/);
  assert.throws(() => parse('ls |'), /missing/);
  assert.equal(breToEre('a\\|b\\(c\\)+'), 'a|b(c)\\+');
});

test('ls, cat, head, tail and wc read the site the way the shell does', async () => {
  const io = site();
  assert.equal((await sh(io, 'ls')).stdout, 'app\nnotes.txt\nresources\nroutes\nstorage\nvendor\n');
  assert.match((await sh(io, 'ls -la app/Models')).stdout, /^-rw-r--r-- +\d+ \w{3} +\d+ [\d: ]+ Cart\.php\n/);
  assert.match((await sh(io, 'ls -a')).stdout, /\.env/);
  assert.equal((await sh(io, 'ls missing')).stderr, "ls: cannot access 'missing': No such file or directory\n");
  assert.equal((await sh(io, 'cat routes/web.php | head -n 2')).stdout, '<?php\nuse App\\Http\\Controllers\\CartController;\n');
  assert.equal((await sh(io, 'head -2 notes.txt')).stdout, 'b\na\n');
  assert.equal((await sh(io, 'tail -n 2 notes.txt')).stdout, 'a\nb\n');
  assert.equal((await sh(io, 'tail -n +5 notes.txt')).stdout, 'a\nb\n');
  assert.equal((await sh(io, 'cat -n app/Models/Item.php')).stdout, '     1\t<?php\n     2\t\n     3\tclass Item extends Model {}\n');
  assert.equal((await sh(io, 'wc -l notes.txt')).stdout, '6 notes.txt\n');
  assert.equal((await sh(io, 'cat notes.txt | wc -l')).stdout, '6\n');
  const nope = await sh(io, 'cat nope.txt');
  assert.equal(nope.code, 1);
  assert.equal(nope.stderr, 'cat: nope.txt: No such file or directory\n');
  // Globs expand from the site, in one listing.
  assert.equal((await sh(io, 'ls app/Models/*.php')).stdout, 'app/Models/Cart.php\napp/Models/Item.php\n');
  assert.equal((await sh(io, 'cat app/Models/I*.php | wc -l')).stdout, '3\n');
  // Many files are read in ONE request.
  io.calls.length = 0;
  await sh(io, 'cat app/Models/Cart.php app/Models/Item.php routes/web.php');
  assert.deepEqual(io.calls.map((c) => c[0]), ['readMany']);
});

test('writing: heredocs, echo, >> and tee, with PHP written exactly as sent', async () => {
  const io = site();
  const php = "<?php\n\nnamespace App\\Models;\n\nclass Order extends Model\n{\n    protected $casts = ['paid' => 'bool'];\n}\n";
  let r = await sh(io, `mkdir -p app/Models && cat > app/Models/Order.php <<'EOF'\n${php}EOF`);
  assert.equal(r.code, 0, r.stderr);
  assert.equal(io.nodes.get('/app/Models/Order.php').content, php);
  await sh(io, 'echo "APP_NAME=Shop" > config.txt && echo second >> config.txt');
  assert.equal(io.nodes.get('/config.txt').content, 'APP_NAME=Shop\nsecond\n');
  r = await sh(io, 'echo hi | tee a.txt b.txt');
  assert.equal(r.stdout, 'hi\n');
  assert.equal(io.nodes.get('/b.txt').content, 'hi\n');
  // A PHP syntax error is reported as the save reports it.
  r = await sh(io, "echo '<?php syntax error' > broken.php");
  assert.match(r.stderr, /broken\.php: PHP syntax error/);
  // printf and 2> /dev/null
  await sh(io, "printf '%s=%d\\n' a 1 b 2 > kv.txt");
  assert.equal(io.nodes.get('/kv.txt').content, 'a=1\nb=2\n');
  r = await sh(io, 'cat nope 2>/dev/null; echo $?');
  assert.equal(r.stderr, '');
  assert.equal(r.stdout, '$?\n'); // no variables: $ is an ordinary character
});

test('grep speaks grep: -rn, BRE alternation, -E, -i, -w, -l, -c, -o, -v, context, --include', async () => {
  const io = site();
  let r = await sh(io, 'grep -rn "Cart" app routes');
  assert.equal(r.stdout, "app/Models/Cart.php:5:class Cart extends Model\nroutes/web.php:2:use App\\Http\\Controllers\\CartController;\nroutes/web.php:4:Route::post('/cart', [CartController::class, 'add']);\n");
  // No folder named: the working directory, without "./".
  r = await sh(io, 'grep -rl Cart');
  assert.equal(r.stdout, 'app/Models/Cart.php\nresources/views/home.blade.php\nroutes/web.php\n');
  // Dependencies and .env are not searched unless named.
  assert.doesNotMatch(r.stdout, /vendor|\.env/);
  assert.equal((await sh(io, 'grep -r VendorCart vendor/acme')).stdout, 'vendor/acme/lib/Cart.php:<?php class VendorCart {}\n');
  // BRE \| and ERE |
  assert.equal((await sh(io, 'grep -rc "Route::get\\|Route::post" routes')).stdout, 'routes/web.php:2\n');
  assert.equal((await sh(io, 'grep -rhE "Route::(get|post)" routes | wc -l')).stdout, '2\n');
  // -w: CartController is not the word Cart.
  assert.equal((await sh(io, 'grep -rlw Cart routes app')).stdout, 'app/Models/Cart.php\n');
  assert.equal((await sh(io, 'grep -ri "cart" --include="*.blade.php" .')).stdout, './resources/views/home.blade.php:<h1>Cart</h1>\n');
  assert.equal((await sh(io, 'grep -o "Cart[A-Za-z]*" routes/web.php | sort -u')).stdout, 'CartController\n');
  // One file: no name prefix; a pipe works like stdin.
  assert.equal((await sh(io, 'grep -n Item app/Models/Item.php')).stdout, '3:class Item extends Model {}\n');
  assert.equal((await sh(io, 'cat notes.txt | grep -c b')).stdout, '3\n');
  assert.equal((await sh(io, 'grep -v b notes.txt')).stdout, 'a\nc\na\n');
  assert.equal((await sh(io, 'grep -A1 -n class app/Models/Cart.php')).stdout, '5:class Cart extends Model\n6-{\n');
  assert.equal((await sh(io, 'grep -rL Cart app/Models')).stdout, 'app/Models/Item.php\n');
  // Exit codes: 0 found, 1 not found, 2 trouble; -q prints nothing.
  assert.equal((await sh(io, 'grep -q Cart app/Models/Cart.php')).code, 0);
  assert.equal((await sh(io, 'grep zzz notes.txt')).code, 1);
  assert.equal((await sh(io, 'grep -E "(" notes.txt')).code, 2);
  assert.equal((await sh(io, 'grep -rq zzz app || echo none')).stdout, 'none\n');
});

test('find speaks find, and says what it did not enter', async () => {
  const io = site();
  let r = await sh(io, 'find . -name "*.php" -type f');
  assert.equal(r.stdout, './app/Models/Cart.php\n./app/Models/Item.php\n./resources/views/home.blade.php\n./routes/web.php\n');
  assert.match(r.stderr, /not entered.*vendor/);
  assert.equal((await sh(io, 'find app -type d')).stdout, 'app\napp/Models\n');
  assert.equal((await sh(io, 'find storage/logs -name "*.log" | xargs rm')).code, 0);
  assert.equal(io.nodes.has('/storage/logs/a.log'), false);
  assert.equal((await sh(io, 'find . -maxdepth 1 -type f')).stdout, './.env\n./notes.txt\n');
  assert.equal((await sh(io, 'find vendor -iname "cart.php"')).stdout, 'vendor/acme/lib/Cart.php\n');
  assert.equal((await sh(io, 'find . -path "*Models*" -name "I*"')).stdout, './app/Models/Item.php\n');
  assert.equal((await sh(io, 'find app ! -name "*.php"')).stdout, 'app\napp/Models\n');
  assert.match((await sh(io, 'find . -exec rm {} ;')).stderr, /-exec is not available/);
  assert.match((await sh(io, 'find nowhere')).stderr, /'nowhere': No such file or directory/);
  const tree = await sh(io, 'tree app');
  assert.equal(tree.stdout, 'app\n└── Models\n    ├── Cart.php\n    └── Item.php\n\n1 directories, 2 files\n');
  assert.match((await sh(io, 'du -sh app')).stdout, /^\d+(\.\d)?[KM]?\tapp\n$/);
});

test('sed: print ranges, substitute with groups, and -i across files in one version', async () => {
  const io = site();
  assert.equal((await sh(io, "sed -n '2,3p' notes.txt")).stdout, 'a\nb\n');
  assert.equal((await sh(io, "sed -n '/^c/p' notes.txt")).stdout, 'c\n');
  assert.equal((await sh(io, "sed '1d;s/b/B/' notes.txt | head -2")).stdout, 'a\nB\n');
  assert.equal((await sh(io, "echo 'price: 10 20' | sed -E 's/([0-9]+)/[\\1]/g'")).stdout, 'price: [10] [20]\n');
  assert.equal((await sh(io, "echo 'aaa' | sed 's/a/b/2'")).stdout, 'aba\n');
  assert.equal((await sh(io, "echo 'aaa' | sed 's/a/b/2g'")).stdout, 'abb\n');
  assert.equal((await sh(io, "echo 'x' | sed 's/x/<&>/'")).stdout, '<x>\n');
  assert.equal((await sh(io, "echo 'hello' | sed 's|l|L|g'")).stdout, 'heLLo\n');
  assert.equal((await sh(io, "printf 'a\\nb\\n' | sed '1a\\\nadded'")).stdout, 'a\nadded\nb\n');
  io.calls.length = 0;
  const r = await sh(io, "sed -i 's/Model/BaseModel/g' app/Models/Cart.php app/Models/Item.php");
  assert.equal(r.code, 0, r.stderr);
  assert.match(io.nodes.get('/app/Models/Item.php').content, /extends BaseModel/);
  assert.equal(io.calls.filter((c) => c[0] === 'writeMany').length, 1);
  // A file changed by someone else between the read and the write is not overwritten.
  const orig = io.writeMany;
  io.writeMany = async (list) => { io.nodes.get('/notes.txt').revision = 'someone-else'; return orig(list); };
  assert.equal((await sh(io, "sed -i 's/a/A/' notes.txt")).code, 4);
  assert.equal(io.nodes.get('/notes.txt').content.includes('A'), false);
});

test('cp, mv, rm, mkdir, touch and rmdir, with the shell\'s rules', async () => {
  const io = site();
  assert.equal((await sh(io, 'cp app/Models/Item.php app/Models/Thing.php')).code, 0);
  assert.equal(io.nodes.get('/app/Models/Thing.php').content, io.nodes.get('/app/Models/Item.php').content);
  assert.match((await sh(io, 'cp app backup')).stderr, /-r not specified; omitting directory 'app'/);
  assert.equal((await sh(io, 'cp -r app backup')).code, 0);
  assert.ok(io.nodes.has('/backup/Models/Cart.php'));
  await sh(io, 'mkdir archive && mv notes.txt archive');
  assert.ok(io.nodes.has('/archive/notes.txt'));
  // mv over an existing file replaces it, as the shell does.
  await sh(io, 'echo new > a.txt && echo old > b.txt && mv a.txt b.txt');
  assert.equal(io.nodes.get('/b.txt').content, 'new\n');
  assert.match((await sh(io, 'mkdir archive')).stderr, /File exists/);
  assert.equal((await sh(io, 'mkdir -p archive/deep/er')).code, 0);
  await sh(io, 'touch empty.txt');
  assert.equal(io.nodes.get('/empty.txt').content, '');
  assert.match((await sh(io, 'rm backup')).stderr, /Is a directory/);
  // A folder delete asks for confirm; the message says how.
  const r = await sh(io, 'rm -rf backup');
  assert.equal(r.code, 1);
  assert.match(r.stderr, /confirm: true/);
  assert.ok(io.nodes.has('/backup/Models/Cart.php'));
  assert.equal((await sh(io, 'rm -rf backup', { confirm: true })).code, 0);
  assert.equal(io.nodes.has('/backup'), false);
  assert.equal((await sh(io, 'rm -f nothing-here')).code, 0);
  assert.match((await sh(io, 'rm -rf /', { confirm: true })).stderr, /refusing/);
  assert.ok(io.nodes.has('/app/Models/Cart.php'));
  assert.match((await sh(io, 'rmdir archive')).stderr, /not empty/);
});

test('&&, || and ; decide what runs, as in sh', async () => {
  const io = site();
  assert.equal((await sh(io, 'false && echo no || echo yes')).stdout, 'yes\n');
  assert.equal((await sh(io, 'true || echo no; echo always')).stdout, 'always\n');
  assert.equal((await sh(io, 'test -f routes/web.php && echo file')).stdout, 'file\n');
  assert.equal((await sh(io, '[ -d app ] && [ ! -e nope ] && echo both')).stdout, 'both\n');
  assert.equal((await sh(io, 'cd app/Models && pwd && ls')).stdout, '/app/Models\nCart.php\nItem.php\n');
  assert.equal((await sh(io, 'cat Cart.php | grep -c class')).stdout, '1\n');
  assert.equal((await sh(io, 'cd ../.. && pwd')).stdout, '/\n');
  const r = await sh(io, 'echo a; exit 3; echo b');
  assert.deepEqual([r.stdout, r.code], ['a\n', 3]);
  const nf = await sh(io, 'frobnicate');
  assert.equal(nf.code, 127);
  assert.match(nf.stderr, /command not found/);
  assert.match((await sh(io, 'npm install')).stderr, /no Node on a site/);
});

test('text tools: sort, uniq, cut, tr, diff', async () => {
  const io = site();
  assert.equal((await sh(io, 'sort notes.txt | uniq -c | sort -rn | head -1')).stdout, '      3 b\n');
  assert.equal((await sh(io, "echo 'a:b:c' | cut -d: -f2-")).stdout, 'b:c\n');
  assert.equal((await sh(io, "echo hello | tr a-z A-Z")).stdout, 'HELLO\n');
  await sh(io, "printf 'one\\ntwo\\nthree\\n' > a.txt && printf 'one\\n2\\nthree\\n' > b.txt");
  const d = await sh(io, 'diff -u a.txt b.txt');
  assert.equal(d.code, 1);
  assert.equal(d.stdout, '--- a.txt\n+++ b.txt\n@@ -1,3 +1,3 @@\n one\n-two\n+2\n three\n');
  assert.equal((await sh(io, 'diff a.txt a.txt')).code, 0);
  assert.equal(unifiedDiff('', 'x\n', 'a', 'b'), '--- a\n+++ b\n@@ -0,0 +1,1 @@\n+x\n');
});

test('git reads the saved versions', async () => {
  const io = site();
  await sh(io, "sed -i 's/Item/Product/' app/Models/Item.php");
  const log = await sh(io, 'git log --oneline -- app/Models/Item.php');
  assert.equal(log.stdout.split('\n').filter(Boolean).length, 2);
  const diff = await sh(io, 'git diff app/Models/Item.php');
  assert.match(diff.stdout, /-class Item extends Model \{\}\n\+class Product extends Model \{\}/);
  assert.match((await sh(io, 'git show HEAD~1:app/Models/Item.php')).stdout, /class Item /);
  assert.match((await sh(io, 'git commit -m x')).stderr, /every save is already a version/);
  assert.match((await sh(io, 'git log')).stderr, /name a file/);
});

test('php, composer, mysql and curl reach the live site', async () => {
  const io = site();
  let r = await sh(io, 'php artisan route:list --json');
  assert.equal(r.stdout, 'artisan route:list --json done\n'); // colour codes stripped
  assert.deepEqual(io.calls.at(-1), ['command', 'artisan', ['route:list', '--json'], false]);
  assert.equal((await sh(io, 'php artisan test --fail')).code, 1);
  r = await sh(io, 'php artisan migrate:fresh');
  assert.match(r.stderr, /confirm: true/);
  assert.equal((await sh(io, 'php artisan migrate:fresh', { confirm: true })).code, 0);
  assert.equal((await sh(io, 'composer require laravel/sanctum')).code, 0);
  assert.equal((await sh(io, "php -r 'return App\\Models\\Cart::count();'")).stdout, 'ran: return App\\Models\\Cart::count();\n');
  assert.equal((await sh(io, "php artisan tinker --execute='User::count()'")).stdout, 'ran: User::count()\n');
  r = await sh(io, 'mysql -e "SELECT id, name FROM items"');
  assert.equal(r.stdout, 'id\tname\n1\tTea\n2\tNULL\n');
  assert.match((await sh(io, 'mysql -e "DELETE FROM items"')).stderr, /confirm: true/);
  assert.equal((await sh(io, 'mysql -e "DELETE FROM items"', { confirm: true })).stdout, 'Query OK, 3 row(s) affected\n');
  assert.equal((await sh(io, 'curl -s https://shop.codeinchrome.com/items?page=2')).stdout, 'GET /items?page=2');
  assert.equal((await sh(io, 'curl -s -o /dev/null -w "%{http_code}" localhost/missing')).stdout, '404');
  assert.equal((await sh(io, 'curl -s -X POST -H "Content-Type: application/json" -d \'{"a":1}\' /api/items')).stdout, 'POST /api/items {"a":1}');
  assert.equal((await sh(io, 'curl -sf /missing')).code, 22);
  r = await sh(io, 'curl https://evil.example.com/');
  assert.equal(r.code, 6);
  assert.match(r.stderr, /only this site/);
});

test('git clone brings a public repository in through the host', async () => {
  const io = site();
  let r = await sh(io, 'git clone --depth 1 -b v2 https://github.com/acme/demo.git && ls demo');
  assert.equal(r.code, 0, r.stderr);
  assert.deepEqual(io.calls.find((c) => c[0] === 'clone')[1], { repository: 'https://github.com/acme/demo.git', ref: 'v2', into: '/demo' });
  assert.match(r.stderr, /^Cloning into 'demo'\.\.\.\n1 files, 2\.0KB from acme\/demo@v2 in 0\.9 s/);
  assert.equal(r.stdout, 'composer.json\n');
  await sh(io, 'cd app && git clone git@github.com:acme/demo.git src/demo');
  assert.equal(io.calls.filter((c) => c[0] === 'clone').at(-1)[1].into, '/app/src/demo');
  r = await sh(io, 'cd / && git clone acme/demo demo');
  assert.equal(r.code, 128);
  assert.match(r.stderr, /^fatal: destination path/);
  assert.match((await sh(io, 'git clone')).stderr, /usage: git clone/);
});

test('the everyday commands take one request to the site, not several', async () => {
  const io = site();
  const count = async (line) => {
    io.calls.length = 0;
    const r = await sh(io, line);
    assert.equal(r.code, 0, `${line}: ${r.stderr}`);
    return io.calls.length;
  };
  assert.equal(await count('ls app'), 1);
  assert.equal(await count('grep -rn Cart app'), 1);
  assert.equal(await count('grep -rn Route routes/web.php'), 1);
  assert.equal(await count('cat routes/web.php app/Models/Cart.php | wc -l'), 1);
  assert.equal(await count('du -sh app resources notes.txt'), 3); // at once, not one after another
  assert.equal((await sh(io, 'du -sb notes.txt')).stdout, '12\tnotes.txt\n');
  assert.match((await sh(io, 'du -s nowhere')).stderr, /cannot access 'nowhere'/);
  // Listings are reused within a line, and forgotten after a write.
  assert.equal(await count('ls app && ls app'), 1);
  await sh(io, 'ls app && touch app/new.txt && ls app');
  assert.match((await sh(io, 'ls app')).stdout, /new\.txt/);
});

test('a refusal that needs confirming is data, stops the line, and names only itself', async () => {
  const io = site();
  let r = await sh(io, 'echo one >> log.txt; rm -r app storage/logs/a.log; echo two >> log.txt');
  assert.deepEqual(r.needsConfirm, ['rm -r /app']);
  assert.equal(io.nodes.has('/storage/logs/a.log'), false); // the file operand still went
  assert.equal(io.nodes.get('/log.txt').content, 'one\n'); // nothing after the refusal ran
  assert.match(r.stderr, /To go ahead: cic\.sh\("rm -r \/app", \{ confirm: true \}\)/);
  // Confirming that one command does exactly that, once.
  assert.equal((await sh(io, r.needsConfirm[0], { confirm: true })).code, 0);
  assert.equal(io.nodes.has('/app/Models/Cart.php'), false);
  assert.equal(io.nodes.get('/log.txt').content, 'one\n');
  // Quoted as sh would need it, and for artisan and SQL too.
  assert.deepEqual((await sh(io, "mkdir 'my dir' && rm -r 'my dir'")).needsConfirm, ["rm -r '/my dir'"]);
  // After a cd, the named command still means the same folder.
  const refused = (await sh(io, 'cd /resources && rm -r views')).needsConfirm;
  await sh(io, 'cd /');
  assert.deepEqual(refused, ['rm -r /resources/views']);
  assert.deepEqual((await sh(io, 'php artisan migrate:fresh --seed')).needsConfirm, ['php artisan migrate:fresh --seed']);
  assert.deepEqual((await sh(io, "mysql -e \"DELETE FROM items WHERE name = 'x'\"")).needsConfirm, ["mysql -e 'DELETE FROM items WHERE name = '\\''x'\\'''"]);
  // Text that merely SAYS "confirm: true" (a page, a log, an error) is not a refusal.
  r = await sh(io, "echo 'please run with { confirm: true }' 1>&2");
  assert.equal(r.needsConfirm, undefined);
});

test('xargs and reads are bounded', async () => {
  const io = site();
  const many = Array.from({ length: 1001 }, (_, i) => `f${i}`).join(' ');
  assert.match((await sh(io, `echo ${many} | xargs echo`)).stderr, /at most 1000/);
  const some = Array.from({ length: 101 }, (_, i) => `f${i}`).join(' ');
  assert.match((await sh(io, `echo ${some} | xargs -n 1 echo`)).stderr, /at most 100/);
  assert.equal((await sh(io, `echo ${some} | xargs echo | wc -w`)).stdout, '101\n');
});

test('a busy or conflicting host is not mistaken for a request to confirm', async () => {
  const io = site();
  io.removeTree = async () => ({ ok: false, status: 409, error: 'busy', hint: 'Another command is running' });
  const r = await sh(io, 'rm -r app');
  assert.equal(r.needsConfirm, undefined);
  assert.match(r.stderr, /Another command is running/);
});

