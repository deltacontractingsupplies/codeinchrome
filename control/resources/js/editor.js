/*
 * The codeinchrome editor.
 *
 * One surface for two users. A person clicks it; an AI agent calls the same
 * operations through window.cic. Both go through the same functions below, so
 * nothing the agent can do is invisible to the person watching, and nothing the
 * person sees is a separate code path the agent cannot reach.
 *
 * Rules this file keeps:
 *   - Every server answer is shown as the server gave it. `ok` is the verdict,
 *     `hint` is the reason, and neither is paraphrased into something rosier.
 *   - Saves carry the revision the editor last saw. If the file changed since,
 *     the server writes NOTHING and answers 409, and the editor says so instead
 *     of overwriting someone's work.
 *   - No alert/confirm/prompt/beforeunload. A native dialog freezes the page
 *     for a browser-driving agent. Unsaved buffers are kept as tab-scoped
 *     drafts instead, so a reload does not lose them either.
 */

const root = document.getElementById('cic-app');
const SITE = {
  id: root.dataset.site,
  domain: root.dataset.domain,
  url: root.dataset.url,
  filesUrl: root.dataset.files,
};
const CSRF = document.querySelector('meta[name=csrf-token]')?.content ?? '';
const DRAFTS_KEY = `cic.drafts.${SITE.id}`;

const $ = (id) => document.getElementById(id);
const ta = $('ta');
const hl = $('hl');
const gutter = $('gutter');

/** path -> { content, saved, revision, dirty, conflict } */
const tabs = new Map();
let active = null;
/** Revisions this page has seen, including for files never opened in a tab. */
const seenRevision = new Map();
/** dir path -> listing, and the set of expanded dirs */
const listings = new Map();
const expanded = new Set(['/']);

/* ───────────────────────── server ───────────────────────── */

async function api(method, query = {}, body) {
  const url = new URL(SITE.filesUrl, location.origin);
  for (const [k, v] of Object.entries(query)) url.searchParams.set(k, v);

  let response;
  try {
    response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': CSRF,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    return { ok: false, status: 0, error: 'network', hint: `Could not reach the control plane: ${error.message}` };
  }

  let json = null;
  try {
    json = await response.json();
  } catch {
    // A non-JSON answer is itself information: usually an expired session
    // (redirect to login) or a proxy error page.
  }

  if (response.status === 401 || response.status === 419) {
    return { ok: false, status: response.status, error: 'signed_out', hint: 'Your session has ended. Sign in again; unsaved changes are kept as drafts in this tab.' };
  }
  if (!json) {
    return { ok: false, status: response.status, error: 'bad_response', hint: `The server answered ${response.status} with no JSON.` };
  }

  // Laravel validation errors have no `ok`; normalise them to the same shape.
  if (json.errors && json.ok === undefined) {
    const first = Object.values(json.errors).flat()[0];
    return { ok: false, status: response.status, error: 'invalid', hint: first || json.message };
  }

  return { status: response.status, ...json };
}

const norm = (p) => '/' + String(p ?? '').replace(/^\/+/, '');
const parentOf = (p) => norm(p).replace(/\/[^/]*$/, '') || '/';
const baseName = (p) => norm(p).split('/').pop();

/* ───────────────────────── drafts ───────────────────────── */

function saveDrafts() {
  const drafts = {};
  for (const [path, t] of tabs) {
    if (t.dirty) drafts[path] = { content: t.content, revision: t.revision };
  }
  try {
    sessionStorage.setItem(DRAFTS_KEY, JSON.stringify(drafts));
  } catch {
    // Storage full or disabled: drafts are a convenience, not a guarantee.
  }
}

function loadDrafts() {
  try {
    return JSON.parse(sessionStorage.getItem(DRAFTS_KEY) || '{}');
  } catch {
    return {};
  }
}

/* ───────────────────────── status ───────────────────────── */

function status(message, isError = false) {
  $('sbMsg').textContent = message;
  document.querySelector('.statusbar').classList.toggle('error', isError);
}

/* ───────────────────────── tree ───────────────────────── */

async function loadDir(path) {
  const res = await api('GET', { path });
  if (res.ok) {
    listings.set(path, res.listing);
  } else {
    listings.set(path, { path, entries: [], truncated: false, error: res.hint });
  }
  return res;
}

async function renderTree() {
  if (!listings.has('/')) await loadDir('/');
  const tree = $('tree');
  tree.replaceChildren();

  const walk = (dir, depth) => {
    const listing = listings.get(dir);
    if (!listing) return;
    if (listing.error) {
      tree.append(note(`Cannot list: ${listing.error}`, depth));
      return;
    }
    for (const entry of listing.entries) {
      tree.append(nodeFor(entry, depth));
      if (entry.dir && expanded.has(entry.path) && listings.has(entry.path)) {
        walk(entry.path, depth + 1);
      }
    }
    if (listing.truncated) tree.append(note('More entries not shown', depth));
  };
  walk('/', 0);
}

function note(text, depth) {
  const el = document.createElement('div');
  el.className = 'note';
  el.style.paddingLeft = `${36 + depth * 12}px`;
  el.textContent = text;
  return el;
}

function nodeFor(entry, depth) {
  const el = document.createElement('div');
  el.className = 'node' + (entry.dir ? ' dir' : '') + (entry.path === active ? ' active' : '');
  el.style.paddingLeft = `${8 + depth * 12}px`;
  el.setAttribute('role', 'treeitem');
  el.tabIndex = 0;
  el.dataset.path = entry.path;

  const twisty = document.createElement('span');
  twisty.className = 'tw';
  twisty.textContent = entry.dir ? (expanded.has(entry.path) ? '▾' : '▸') : '';

  const name = document.createElement('span');
  name.className = 'nm';
  name.textContent = entry.name; // textContent: file names are data, never markup

  el.append(twisty, name);

  if (!entry.dir) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'del';
    del.title = `Delete ${entry.name}`;
    del.textContent = '🗑';
    del.addEventListener('click', (e) => {
      e.stopPropagation();
      confirmDelete(entry.path);
    });
    el.append(del);
  }

  const activate = async () => {
    if (entry.dir) {
      if (expanded.has(entry.path)) {
        expanded.delete(entry.path);
      } else {
        expanded.add(entry.path);
        if (!listings.has(entry.path)) await loadDir(entry.path);
      }
      renderTree();
    } else {
      openFile(entry.path);
    }
  };
  el.addEventListener('click', activate);
  el.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      activate();
    }
  });

  return el;
}

async function refreshDir(path) {
  if (listings.has(path) || path === '/') await loadDir(path);
  renderTree();
}

/* ───────────────────────── highlighting ───────────────────────── */

const KW = /^(abstract|array|as|async|await|break|case|catch|class|const|continue|declare|default|do|echo|else|elseif|enum|export|extends|final|finally|fn|for|foreach|function|global|if|implements|import|include|instanceof|interface|let|match|namespace|new|print|private|protected|public|readonly|require|return|static|switch|throw|trait|try|use|var|while|yield|true|false|null|int|string|bool|void|self|parent|this)$/;
const CTL = /^(if|else|elseif|foreach|for|while|return|switch|case|break|continue|match|try|catch|finally|throw|yield|do|await)$/;
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function highlight(src, path) {
  if (!/\.(php|js|mjs|ts|css|json|vue|jsx|tsx)$/.test(path) && !path.endsWith('.blade.php')) {
    return esc(src);
  }
  const RX = /(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`)|(\$[A-Za-z_]\w*)|(\b\d+\.?\d*\b)|(\{\{|\}\}|@[a-z]+)|\b([A-Za-z_]\w*)\b/g;
  let out = '';
  let last = 0;
  let m;
  while ((m = RX.exec(src)) !== null) {
    out += esc(src.slice(last, m.index));
    const t = m[0];
    if (m[1]) out += `<span class="cmt">${esc(t)}</span>`;
    else if (m[2]) out += `<span class="str">${esc(t)}</span>`;
    else if (m[3]) out += `<span class="vr">${esc(t)}</span>`;
    else if (m[4]) out += `<span class="num">${esc(t)}</span>`;
    else if (m[5]) out += `<span class="ctl">${esc(t)}</span>`;
    else {
      const w = m[6];
      if (CTL.test(w)) out += `<span class="ctl">${w}</span>`;
      else if (KW.test(w)) out += `<span class="kw">${w}</span>`;
      else if (/^[A-Z]/.test(w)) out += `<span class="typ">${w}</span>`;
      else if (src[RX.lastIndex] === '(') out += `<span class="fn">${w}</span>`;
      else out += esc(w);
    }
    last = RX.lastIndex;
  }
  return out + esc(src.slice(last));
}

/* ───────────────────────── editor ───────────────────────── */

function paint() {
  const value = ta.value;
  hl.innerHTML = highlight(value, active || '') + '\n';
  const lines = value.split('\n').length;
  const curLine = value.slice(0, ta.selectionStart).split('\n').length;

  const frag = document.createDocumentFragment();
  for (let i = 1; i <= lines; i++) {
    const d = document.createElement('div');
    d.textContent = i;
    if (i === curLine) d.className = 'cur';
    frag.append(d);
  }
  gutter.replaceChildren(frag);
  gutter.scrollTop = hl.scrollTop = ta.scrollTop;
  hl.scrollLeft = ta.scrollLeft;

  const col = ta.selectionStart - value.lastIndexOf('\n', ta.selectionStart - 1);
  $('sbPos').textContent = active ? `Ln ${curLine}, Col ${col}` : '';
}

function renderTabs() {
  const bar = $('tabs');
  bar.replaceChildren();
  for (const [path, t] of tabs) {
    const el = document.createElement('div');
    el.className = 'tab' + (path === active ? ' active' : '') + (t.dirty ? ' dirty' : '');
    el.setAttribute('role', 'tab');
    el.title = path;

    const name = document.createElement('span');
    name.textContent = baseName(path);

    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'x';
    x.setAttribute('aria-label', `Close ${baseName(path)}`);
    x.addEventListener('click', (e) => {
      e.stopPropagation();
      closeTab(path);
    });

    el.append(name, x);
    el.addEventListener('click', () => show(path));
    bar.append(el);
  }
}

function show(path) {
  active = path;
  const t = tabs.get(path);
  const has = Boolean(t);

  $('empty').hidden = has;
  ta.disabled = !has;
  ta.value = has ? t.content : '';
  $('crumb').textContent = has ? path.slice(1).split('/').join('  ›  ') : '';
  $('winTitle').textContent = has ? `${baseName(path)} — ${SITE.id}` : SITE.id;
  document.title = has ? `${baseName(path)} — ${SITE.id}` : `${SITE.id} — codeinchrome`;
  $('sbRev').textContent = has && t.revision ? `rev ${t.revision.slice(0, 7)}` : '';

  const conflict = has && t.conflict;
  $('conflict').hidden = !conflict;
  if (conflict) $('conflictText').textContent = t.conflict;

  renderTabs();
  paint();
  document.querySelectorAll('.node.active').forEach((n) => n.classList.remove('active'));
  document.querySelector(`.node[data-path="${CSS.escape(path || '')}"]`)?.classList.add('active');
}

async function openFile(path) {
  path = norm(path);
  if (tabs.has(path)) {
    show(path);
    return { ok: true, path, alreadyOpen: true };
  }

  status(`Opening ${path}…`);
  const res = await api('GET', { read: 1, path });
  if (!res.ok) {
    status(`${path}: ${res.hint}`, true);
    return res;
  }

  seenRevision.set(path, res.revision);
  const draft = loadDrafts()[path];
  const tab = { content: res.content, saved: res.content, revision: res.revision, dirty: false, conflict: null };

  if (draft) {
    // Restore unsaved work - but if the file moved on while it sat in a
    // draft, saving it blind would erase that change. Say so up front.
    tab.content = draft.content;
    tab.dirty = draft.content !== res.content;
    if (draft.revision && draft.revision !== res.revision) {
      tab.revision = draft.revision;
      tab.conflict = 'You have unsaved changes from before, but this file was changed since. Saving would overwrite that change.';
    }
  }

  tabs.set(path, tab);
  await revealInTree(path);
  show(path);
  status(draft && tab.dirty ? `${path}: restored unsaved changes` : '');
  return { ok: true, path, revision: res.revision, restoredDraft: Boolean(draft && tab.dirty) };
}

function closeTab(path) {
  const t = tabs.get(path);
  if (t?.dirty) {
    // Never discard work on a single click, and never with confirm().
    ask(`${baseName(path)} has unsaved changes. Close it and discard them?`, { okLabel: 'Discard' }).then((yes) => {
      if (yes) {
        t.dirty = false;
        closeTab(path);
      }
    });
    return;
  }
  const keys = [...tabs.keys()];
  const index = keys.indexOf(path);
  tabs.delete(path);
  saveDrafts();
  if (active === path) {
    const next = keys[index + 1] ?? keys[index - 1] ?? null;
    show(next && tabs.has(next) ? next : null);
  } else {
    renderTabs();
  }
}

/**
 * Save a tab. `expect` is the revision the editor last saw; if the file changed
 * since, the server writes nothing and answers 409.
 */
async function save(path = active, { overwrite = false } = {}) {
  const t = tabs.get(path);
  if (!t) return { ok: false, error: 'not_open', hint: `${path} is not open` };

  let expect = t.revision || 'absent';
  if (overwrite) {
    // A deliberate overwrite still goes through the revision check - against
    // the CURRENT revision - so it cannot race a third writer.
    const current = await api('GET', { read: 1, path });
    expect = current.ok ? current.revision : 'absent';
  }

  status(`Saving ${path}…`);
  const res = await api('PUT', {}, { path, content: t.content, expect });

  if (res.ok) {
    t.saved = t.content;
    t.revision = res.revision;
    t.dirty = false;
    t.conflict = null;
    seenRevision.set(path, res.revision);
    saveDrafts();
    status(`Saved ${path}`);
    if (path === active) show(path);
    else renderTabs();
    refreshDir(parentOf(path));
    return res;
  }

  if (res.status === 409) {
    t.conflict = 'This file was changed since you opened it. Nothing was saved.';
    if (path === active) show(path);
    status(`${path}: not saved — it changed since you opened it`, true);
    return res;
  }

  status(`${path}: ${res.hint}`, true);
  return res;
}

async function loadTheirs(path = active) {
  const t = tabs.get(path);
  if (!t) return;
  const res = await api('GET', { read: 1, path });
  if (!res.ok) {
    // Most likely it was deleted. Keep the buffer; saving will recreate it.
    t.revision = '';
    t.conflict = null;
    status(`${path}: ${res.hint}. Your copy is kept; saving will create it again.`, true);
  } else {
    Object.assign(t, { content: res.content, saved: res.content, revision: res.revision, dirty: false, conflict: null });
    seenRevision.set(path, res.revision);
    status(`${path}: loaded the saved version`);
  }
  saveDrafts();
  show(path);
}

/* ───────────────────────── create / delete ───────────────────────── */

async function createFile(path, content = '') {
  path = norm(path);
  // "absent": creating must never replace a file that already exists.
  const res = await api('PUT', {}, { path, content, expect: 'absent' });
  if (res.ok) {
    seenRevision.set(path, res.revision);
    await refreshAncestors(path);
    await openFile(path);
  }
  return res;
}

/** Expand the folders above a file, loading only the ones not seen yet. */
async function revealInTree(path) {
  let dir = '/';
  for (const part of norm(path).split('/').filter(Boolean).slice(0, -1)) {
    dir = dir === '/' ? `/${part}` : `${dir}/${part}`;
    expanded.add(dir);
    if (!listings.has(dir)) await loadDir(dir);
  }
  renderTree();
}

async function refreshAncestors(path) {
  // Expand and reload every directory down to the new file, so it is visible.
  const parts = norm(path).split('/').filter(Boolean);
  let dir = '/';
  await loadDir('/');
  for (const part of parts.slice(0, -1)) {
    dir = dir === '/' ? `/${part}` : `${dir}/${part}`;
    expanded.add(dir);
    await loadDir(dir);
  }
  renderTree();
}

async function removeFile(path) {
  path = norm(path);
  const res = await api('DELETE', { path });
  if (res.ok) {
    seenRevision.delete(path);
    const t = tabs.get(path);
    if (t) {
      if (t.dirty) {
        // Keep unsaved work; it now describes a file that no longer exists.
        t.revision = '';
        t.conflict = 'This file was deleted. Your unsaved copy is kept; saving will create it again.';
        if (path === active) show(path);
      } else {
        tabs.delete(path);
        if (active === path) show([...tabs.keys()][0] ?? null);
        else renderTabs();
      }
    }
    await refreshDir(parentOf(path));
    status(`Deleted ${path}`);
  } else {
    status(`${path}: ${res.hint}`, true);
  }
  return res;
}

async function confirmDelete(path) {
  if (await ask(`Delete ${path}? This cannot be undone.`, { okLabel: 'Delete' })) {
    removeFile(path);
  }
}

/* ───────────────────────── in-page dialog ───────────────────────── */

function ask(text, { input = null, okLabel = 'OK', validate = null } = {}) {
  return new Promise((resolve) => {
    const modal = $('modal');
    const field = $('modalInput');
    $('modalText').textContent = text;
    $('modalError').textContent = '';
    $('modalOk').textContent = okLabel;
    field.hidden = input === null;
    field.value = input ?? '';
    modal.hidden = false;
    (input === null ? $('modalOk') : field).focus();

    const done = (value) => {
      modal.hidden = true;
      $('modalForm').onsubmit = null;
      $('modalCancel').onclick = null;
      modal.onkeydown = null;
      ta.focus();
      resolve(value);
    };
    $('modalForm').onsubmit = (e) => {
      e.preventDefault();
      if (input === null) return done(true);
      const problem = validate?.(field.value);
      if (problem) {
        $('modalError').textContent = problem;
        return;
      }
      done(field.value);
    };
    $('modalCancel').onclick = () => done(input === null ? false : null);
    modal.onkeydown = (e) => {
      if (e.key === 'Escape') done(input === null ? false : null);
    };
  });
}

/* ───────────────────────── wiring ───────────────────────── */

ta.addEventListener('input', () => {
  const t = tabs.get(active);
  if (!t) return;
  t.content = ta.value;
  t.dirty = t.content !== t.saved;
  saveDrafts();
  renderTabs();
  paint();
});
ta.addEventListener('scroll', () => {
  hl.scrollTop = gutter.scrollTop = ta.scrollTop;
  hl.scrollLeft = ta.scrollLeft;
});
['keyup', 'click', 'select'].forEach((e) => ta.addEventListener(e, paint));
ta.addEventListener('keydown', (e) => {
  if (e.key === 'Tab' && !e.metaKey && !e.ctrlKey) {
    e.preventDefault();
    ta.setRangeText('    ', ta.selectionStart, ta.selectionEnd, 'end');
    ta.dispatchEvent(new Event('input'));
  }
});
document.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
    e.preventDefault();
    if (active) save(active);
  }
});

$('btnRefresh').addEventListener('click', async () => {
  for (const dir of ['/', ...expanded]) await loadDir(dir);
  renderTree();
  status('Refreshed');
});
$('btnNew').addEventListener('click', async () => {
  const path = await ask('New file path, relative to the site root:', {
    input: 'app/',
    okLabel: 'Create',
    validate: (v) => (!v.trim() || v.trim().endsWith('/') ? 'Give it a file name.' : null),
  });
  if (!path) return;
  const res = await createFile(path.trim());
  if (!res.ok) {
    status(res.status === 409 ? `${norm(path)} already exists` : `${norm(path)}: ${res.hint}`, true);
  }
});
$('btnTheirs').addEventListener('click', () => loadTheirs(active));
$('btnMine').addEventListener('click', () => save(active, { overwrite: true }));

/* ───────────────────────── agent API ───────────────────────── */

const HELP = `window.cic — drive this editor from code. Every call returns the server's answer:
  ok (the verdict), plus error and hint when ok is false. Nothing is paraphrased.

  cic.site                     { id, domain, url }
  cic.ls(path = '/')           list a directory                 -> { ok, listing }
  cic.read(path)               read a file and its revision     -> { ok, content, revision }
  cic.write(path, content, { expect })
                               save a file. expect defaults to the last revision this page
                               saw for that path; if the file changed since, NOTHING is
                               written and you get status 409 / error "conflict". Pass
                               expect: 'absent' to create only if missing, or '' to write
                               unconditionally (the result then says so in "note").
  cic.rm(path)                 delete a file (not recursive)    -> { ok, deleted }
  cic.open(path)               open a file in the editor for the person watching
  cic.state()                  what is open, which tabs are unsaved or in conflict

  Limits: text files only (binary files are refused rather than corrupted), 2 MB per file,
  paths are confined to this site. Anything outside it is refused with one vague message.`;

window.cic = Object.freeze({
  version: '1.0',
  site: Object.freeze({ id: SITE.id, domain: SITE.domain, url: SITE.url }),
  help: () => HELP,

  async ls(path = '/') {
    const res = await api('GET', { path: norm(path) });
    if (res.ok) {
      listings.set(res.listing.path, res.listing);
      renderTree();
    }
    return res;
  },

  async read(path) {
    const res = await api('GET', { read: 1, path: norm(path) });
    if (res.ok) seenRevision.set(norm(path), res.revision);
    return res;
  },

  async write(path, content, options = {}) {
    path = norm(path);
    if (typeof content !== 'string') {
      return { ok: false, error: 'invalid', hint: 'content must be a string' };
    }
    const known = seenRevision.get(path);
    const expect = options.expect !== undefined ? options.expect : (known ?? '');
    const res = await api('PUT', {}, { path, content, expect });

    if (res.ok) {
      seenRevision.set(path, res.revision);
      if (expect === '') {
        res.note = 'Written unconditionally: no revision was checked, so a concurrent change would have been overwritten. Read the file first, or pass expect.';
      }
      // Keep the person's view truthful about what just happened on disk.
      const t = tabs.get(path);
      if (t && !t.dirty) {
        Object.assign(t, { content, saved: content, revision: res.revision, conflict: null });
      } else if (t && t.dirty) {
        t.conflict = 'This file was just changed by the agent. Your unsaved edits would overwrite that change.';
      }
      if (t && path === active) show(path);
      await refreshAncestors(path);
      status(`Agent wrote ${path}`);
    }
    return res;
  },

  rm: (path) => removeFile(path),
  open: (path) => openFile(path),

  state() {
    return {
      active,
      open: [...tabs].map(([path, t]) => ({
        path,
        dirty: t.dirty,
        revision: t.revision,
        conflict: t.conflict,
      })),
    };
  },
});

/* ───────────────────────── start ───────────────────────── */

(async () => {
  status('Loading…');
  const res = await loadDir('/');
  if (!res.ok) {
    status(res.hint, true);
    return;
  }
  renderTree();

  // Reopen anything left unsaved in this tab before a reload.
  const drafts = Object.keys(loadDrafts());
  for (const path of drafts) await openFile(path);
  if (drafts.length === 0) {
    const first = ['/routes/web.php', '/resources/views/welcome.blade.php'];
    for (const p of first) {
      const r = await openFile(p);
      if (r.ok) break;
    }
  }
  status(drafts.length ? `Restored ${drafts.length} unsaved file(s)` : 'Ready');
})();
