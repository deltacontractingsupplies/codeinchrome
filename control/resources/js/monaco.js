/*
 * Monaco - the editor inside VS Code (MIT) - configured for codeinchrome.
 *
 * Its language workers are bundled by Vite and served from this origin, so
 * the page's script-src 'self' covers them. JS/TS, CSS, HTML and JSON get
 * Monaco's full language services (completion, hover, diagnostics); PHP,
 * Blade and the rest get syntax colouring until their language servers are
 * wired in.
 */
import './csp-nonce.js';
import * as monaco from 'monaco-editor';
import EditorWorker from 'monaco-editor/editor/editor.worker?worker';
import JsonWorker from 'monaco-editor/language/json/json.worker?worker';
import CssWorker from 'monaco-editor/language/css/css.worker?worker';
import HtmlWorker from 'monaco-editor/language/html/html.worker?worker';
import TsWorker from 'monaco-editor/language/typescript/ts.worker?worker';

self.MonacoEnvironment = {
  getWorker(_id, label) {
    if (label === 'json') return new JsonWorker();
    if (label === 'css' || label === 'scss' || label === 'less') return new CssWorker();
    if (label === 'html' || label === 'handlebars' || label === 'razor') return new HtmlWorker();
    if (label === 'typescript' || label === 'javascript') return new TsWorker();
    return new EditorWorker();
  },
};

// Language definitions are registered defensively: one mistake in a
// tokenizer must cost that language its colours, never the whole editor.
// (Monarch reads "@word" inside a regex as a reference, which is why every
// literal @ below is written [@].)
function define(id, fn) {
  try {
    fn();
  } catch (e) {
    console.error(`codeinchrome: the ${id} highlighter failed to load; files open without it.`, e);
  }
}

// PHP as it appears inside Blade's {{ }} and @php: code with no <?php opener.
// Monaco's own PHP highlighter only switches to PHP after <?php, so embedding
// it here would colour nothing.
monaco.languages.register({ id: 'php-expr' });
define('php-expr', () => monaco.languages.setMonarchTokensProvider('php-expr', {
  keywords: ['as', 'fn', 'function', 'new', 'instanceof', 'and', 'or', 'not', 'true', 'false', 'null', 'isset', 'empty', 'match', 'return', 'if', 'else', 'foreach', 'for', 'while', 'use', 'static', 'self', 'parent'],
  tokenizer: {
    root: [
      [/\$[a-zA-Z_]\w*/, 'variable'],
      [/[a-zA-Z_][\w\\]*(?=\s*\()/, 'predefined'],
      [/[a-zA-Z_]\w*/, { cases: { '@keywords': 'keyword', '@default': 'identifier' } }],
      [/"([^"\\]|\\.)*"/, 'string'],
      [/'([^'\\]|\\.)*'/, 'string'],
      [/\d+(\.\d+)?/, 'number'],
      [/->|=>|::|\?->|\?\?|[-+*\/%=<>!&|.?:]+/, 'operator'],
      [/[()[\]{},;]/, 'delimiter'],
      [/\s+/, ''],
    ],
  },
}));

// Blade: HTML, plus {{ }}, {!! !!}, {{-- --}} and @directives, with PHP inside.
monaco.languages.register({ id: 'blade', extensions: ['.blade.php'], aliases: ['Blade'] });
define('blade', () => monaco.languages.setMonarchTokensProvider('blade', {
  defaultToken: '',
  tokenPostfix: '.html',
  ignoreCase: true,
  tokenizer: {
    root: [
      [/\{\{--/, 'comment', '@bladeComment'],
      [/\{!!/, { token: 'delimiter.bracket', next: '@bladeRaw', nextEmbedded: 'php-expr' }],
      [/\{\{/, { token: 'delimiter.bracket', next: '@bladeEcho', nextEmbedded: 'php-expr' }],
      [/[@](php)\b/, { token: 'keyword', next: '@bladePhpBlock', nextEmbedded: 'php-expr' }],
      [/[@][a-zA-Z_]\w*/, 'keyword'],
      [/<!--/, 'comment', '@htmlComment'],
      [/(<)(\/?[\w\-.:]+)/, ['delimiter', { token: 'tag', next: '@tag' }]],
      [/[^<{@]+/, ''],
      [/[<{][@]?|[@]/, ''],
    ],
    bladeComment: [[/--\}\}/, 'comment', '@pop'], [/./, 'comment']],
    // While PHP is embedded, only the closing rule may be here: everything
    // before it belongs to the embedded language.
    bladeEcho: [[/\}\}/, { token: 'delimiter.bracket', next: '@pop', nextEmbedded: '@pop' }]],
    bladeRaw: [[/!!\}/, { token: 'delimiter.bracket', next: '@pop', nextEmbedded: '@pop' }]],
    bladePhpBlock: [[/[@]endphp\b/, { token: 'keyword', next: '@pop', nextEmbedded: '@pop' }]],
    // HTML also ends a comment at "--!>".
    htmlComment: [[/--!?>/, 'comment', '@pop'], [/[^-]+/, 'comment'], [/./, 'comment']],
    tag: [
      [/\/?>/, 'delimiter', '@pop'],
      [/"([^"]*)"/, 'attribute.value'],
      [/'([^']*)'/, 'attribute.value'],
      [/[\w\-:.@]+/, 'attribute.name'],
      [/=/, 'delimiter'],
      [/[ \t\r\n]+/, ''],
    ],
  },
}));
monaco.languages.setLanguageConfiguration('blade', {
  comments: { blockComment: ['{{--', '--}}'] },
  brackets: [['<', '>'], ['{', '}'], ['(', ')'], ['[', ']']],
  autoClosingPairs: [{ open: '{', close: '}' }, { open: '(', close: ')' }, { open: '[', close: ']' }, { open: '"', close: '"' }, { open: "'", close: "'" }],
});

/** The Monaco language for a file path. */
export function languageFor(path) {
  const name = path.split('/').pop().toLowerCase();
  if (name.endsWith('.blade.php')) return 'blade';
  if (name === '.env' || name.startsWith('.env.')) return 'ini';
  if (name === 'dockerfile') return 'dockerfile';
  if (name === 'artisan') return 'php';
  const ext = name.includes('.') ? name.split('.').pop() : '';
  return {
    php: 'php', js: 'javascript', mjs: 'javascript', cjs: 'javascript', jsx: 'javascript',
    ts: 'typescript', tsx: 'typescript', vue: 'html', css: 'css', scss: 'scss', less: 'less',
    html: 'html', htm: 'html', json: 'json', lock: 'json', md: 'markdown', yml: 'yaml', yaml: 'yaml',
    xml: 'xml', svg: 'xml', sql: 'sql', sh: 'shell', ini: 'ini', conf: 'ini', txt: 'plaintext',
  }[ext] ?? 'plaintext';
}

export { monaco };
