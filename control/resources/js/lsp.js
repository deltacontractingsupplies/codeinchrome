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
    this.poll = setInterval(() => {
      if (document.visibilityState === 'visible' && !this.flushing && this.outbox.length === 0) this.flush(0);
    }, 4000);
  }

  setListener(listener) {
    this.listener = listener;
    while (listener && this.inbox.length) listener(this.inbox.shift());
  }

  deliver(message) {
    if (this.listener) this.listener(message);
    else this.inbox.push(message);
  }

  send(message) {
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
    if (message.method === 'initialize') {
      message.params = { ...message.params, rootUri: ROOT, rootPath: '/var/www/html',
        workspaceFolders: [{ uri: ROOT, name: 'site' }] };
    }
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
          this.onStatus?.(res.hint ?? 'PHP language server unavailable', true);
          for (const m of batch) {
            if (m.id !== undefined && m.method) this.deliver({ jsonrpc: '2.0', id: m.id, error: { code: -32603, message: res.hint ?? 'unavailable' } });
          }
          return;
        }
        this.onStatus?.('', false);
        for (const m of res.messages) this.deliver(m);
      } catch {
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
  return { client, stop, session };
}
