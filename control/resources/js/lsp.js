/*
 * PHP IntelliSense: Monaco's LSP client talking to Phpactor, which runs in
 * the site's own container (agent/internal/sites/lsp.go).
 *
 * The page cannot keep a socket to it - the control plane is PHP-FPM - so
 * this transport exchanges batches of JSON-RPC messages over HTTP:
 *   - outgoing messages are gathered for a moment and sent together; the
 *     POST waits for the responses to any requests among them;
 *   - anything the server says on its own (diagnostics) is collected by a
 *     light poll while the page is visible.
 *
 * Only PHP files reach Phpactor. The client registers its features for every
 * language, so requests about other files are answered here with nothing,
 * and their document notifications are dropped - Monaco's own services keep
 * JavaScript, CSS, HTML and JSON.
 *
 * Paths: the editor's models are cic:/routes/web.php; inside the container
 * the same file is file:///var/www/html/routes/web.php.
 */
import { monaco } from './monaco.js';

const ROOT = 'file:///var/www/html';
const toServer = (s) => s.replaceAll('cic:/', `${ROOT}/`);
const toClient = (s) => s.replaceAll(`${ROOT}/`, 'cic:/');
const isPhp = (uri) => /\.php$/i.test(uri) && !/\.blade\.php$/i.test(uri);

// Monaco's document synchronizer announces documents with their URI
// lowercased, while its feature requests use the true case. Linux paths are
// case-sensitive, so every document URI is put back to its model's true case
// before anything else looks at it - otherwise a hover on ShopController.php
// was taken for a file that was never opened, and answered with nothing.
function trueCase(uri) {
  if (typeof uri !== 'string') return uri;
  const lower = uri.toLowerCase();
  for (const m of monaco.editor.getModels()) {
    const s = m.uri.toString(true);
    if (s.toLowerCase() === lower) return s;
  }
  return uri;
}

class HttpTransport {
  constructor({ url, csrf, session, onStatus }) {
    this.url = url;
    this.csrf = csrf;
    this.session = session;
    this.onStatus = onStatus;
    this.listener = null;
    this.inbox = [];
    this.outbox = [];
    this.flushing = null;
    this.timer = null;
    this.phpUris = new Set();
    this.state = { value: { state: 'open' } };
    this.own = new Map();
    this.ownId = 0;
    this.stats = { initialized: false, initId: undefined, sent: 0, received: 0, lastError: null, capabilities: [] };
    this.poll = setInterval(() => {
      if (document.visibilityState === 'visible' && !this.flushing && this.outbox.length === 0) this.flush(0);
    }, 4000);
  }

  setListener(listener) {
    this.listener = listener;
    while (listener && this.inbox.length) listener(this.inbox.shift());
  }

  // A request of our own (window.cic.php), answered to us, not to Monaco.
  request(method, params) {
    const id = `cic-${++this.ownId}`;
    return new Promise((resolve) => {
      this.own.set(id, resolve);
      this.outbox.push({ jsonrpc: '2.0', id, method, params });
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.flush(8000), 15);
      setTimeout(() => {
        if (this.own.delete(id)) resolve({ error: { message: 'timed out - the language server may still be indexing; try again' } });
      }, 20000);
    });
  }

  deliver(message) {
    if (message.id !== undefined && this.own.has(message.id)) {
      this.own.get(message.id)(message);
      this.own.delete(message.id);
      return;
    }
    this.stats.received++;
    if (message.id !== undefined && message.id === this.stats.initId && message.result) {
      this.stats.initialized = true;
      this.stats.capabilities = Object.keys(message.result.capabilities ?? {});
    }
    if (message.error) this.stats.lastError = message.error.message;
    if (this.listener) this.listener(message);
    else this.inbox.push(message);
  }

  send(message) {
    if (message.params?.textDocument?.uri) {
      message.params = { ...message.params, textDocument: { ...message.params.textDocument, uri: trueCase(message.params.textDocument.uri) } };
    }
    const uri = message.params?.textDocument?.uri;
    if (message.method === 'textDocument/didOpen') {
      if (isPhp(uri)) this.phpUris.add(uri);
      else return Promise.resolve();
    }
    if (uri && !this.phpUris.has(uri)) {
      // Not a PHP file: answer a request with nothing, drop a notification.
      if (message.id !== undefined && message.method) {
        queueMicrotask(() => this.deliver({ jsonrpc: '2.0', id: message.id, result: null }));
      }
      return Promise.resolve();
    }
    if (message.method === 'textDocument/didClose') this.phpUris.delete(uri);
    if (message.method === 'textDocument/didChange') {
      // Phpactor declares FULL document sync (textDocumentSync: 1): each
      // change must carry the whole text. Monaco's client sends incremental
      // edits regardless, and Phpactor took the one inserted character for
      // the entire file - its copy of the document became "l", and it had
      // nothing to complete. A change with no range replaces the whole
      // document, which every server accepts.
      const model = monaco.editor.getModels().find((m) => m.uri.toString(true) === uri);
      if (model) message.params = { ...message.params, contentChanges: [{ text: model.getValue() }] };
    }
    if (message.method === 'initialize') {
      this.stats.initId = message.id;
      message.params = { ...message.params, rootUri: ROOT, rootPath: '/var/www/html',
        workspaceFolders: [{ uri: ROOT, name: 'site' }] };
    }
    this.stats.sent++;
    this.outbox.push(message);
    // Requests go almost at once; a run of keystrokes rides together.
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.flush(8000), message.id !== undefined ? 15 : 200);
    return Promise.resolve();
  }

  async flush(waitMs) {
    if (this.flushing) {
      await this.flushing;
      if (this.outbox.length === 0 && waitMs > 0) return;
    }
    const batch = this.outbox.splice(0);
    this.flushing = (async () => {
      try {
        const r = await fetch(this.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: toServer(JSON.stringify({ session: this.session, waitMs, messages: batch })),
        });
        const res = JSON.parse(toClient(await r.text()));
        if (!res.ok) {
          this.stats.lastError = res.hint ?? res.error;
          this.onStatus?.(res.hint ?? 'PHP language server unavailable', true);
          for (const m of batch) {
            if (m.id !== undefined && m.method) this.deliver({ jsonrpc: '2.0', id: m.id, error: { code: -32603, message: res.hint ?? 'unavailable' } });
          }
          return;
        }
        this.onStatus?.('', false);
        for (const m of res.messages) this.deliver(m);
      } catch (e) {
        this.stats.lastError = String(e);
        for (const m of batch) {
          if (m.id !== undefined && m.method) this.deliver({ jsonrpc: '2.0', id: m.id, error: { code: -32603, message: 'network' } });
        }
      } finally {
        this.flushing = null;
      }
    })();
    return this.flushing;
  }

  toString() {
    return `cic-lsp@${this.session}`;
  }

  dispose() {
    clearInterval(this.poll);
    clearTimeout(this.timer);
  }
}

/** Starts PHP IntelliSense for this page. Returns a function that stops it. */
export function startPhpLanguageServer({ url, closeUrl, csrf, onStatus }) {
  const session = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
  const transport = new HttpTransport({ url, csrf, session, onStatus });
  const client = new monaco.lsp.MonacoLspClient(transport);
  const stop = () => {
    transport.dispose();
    // Best effort; the agent reaps an idle server after ten minutes anyway.
    fetch(`${closeUrl}/${session}`, { method: 'DELETE', credentials: 'same-origin', keepalive: true,
      headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
  };
  addEventListener('pagehide', stop, { once: true });
  const status = () => ({ session, ...transport.stats, openPhpFiles: [...transport.phpUris].map((u) => u.replace('cic:', '')) });
  // For the agent: the same answers the person gets. The file must be open
  // in the editor (the caller opens it); line and column are 1-based.
  const ask = async (method, path, line, column) => {
    const uri = trueCase(`cic:${path.startsWith('/') ? path : `/${path}`}`);
    const r = await transport.request(method, { textDocument: { uri }, position: { line: line - 1, character: column - 1 } });
    return r.error ? { ok: false, error: 'lsp', hint: r.error.message } : { ok: true, result: JSON.parse(toClient(JSON.stringify(r.result ?? null))) };
  };
  const plain = (c) => (typeof c === 'string' ? c : Array.isArray(c) ? c.map(plain).join('\n') : c?.value ?? '');
  const php = {
    complete: async (path, line, column) => {
      const r = await ask('textDocument/completion', path, line, column);
      if (!r.ok) return r;
      const items = Array.isArray(r.result) ? r.result : r.result?.items ?? [];
      return { ok: true, items: items.slice(0, 100).map((i) => ({ label: i.label, kind: i.kind, detail: i.detail ?? null, insert: i.insertText ?? i.label })) };
    },
    hover: async (path, line, column) => {
      const r = await ask('textDocument/hover', path, line, column);
      return r.ok ? { ok: true, text: r.result ? plain(r.result.contents) : '' } : r;
    },
    definition: async (path, line, column) => {
      const r = await ask('textDocument/definition', path, line, column);
      if (!r.ok) return r;
      const locs = [].concat(r.result ?? []);
      return { ok: true, locations: locs.map((l) => ({ path: (l.uri ?? l.targetUri ?? '').replace(/^cic:/, ''), line: (l.range ?? l.targetRange)?.start.line + 1 })) };
    },
  };
  return { client, stop, session, status, ...php };
}
