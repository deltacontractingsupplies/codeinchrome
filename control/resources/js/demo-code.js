/*
 * Syntax colours for the read-only code window (highlight.js, BSD-3-Clause),
 * in the editor's own colours (resources/css/code-window.css).
 * The code arrives as escaped text; highlight.js re-escapes what it wraps, so
 * nothing in a file can ever run on this page.
 */
import hljs from 'highlight.js/lib/core';
import php from 'highlight.js/lib/languages/php';
import phpTemplate from 'highlight.js/lib/languages/php-template';
import xml from 'highlight.js/lib/languages/xml';
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import css from 'highlight.js/lib/languages/css';
import json from 'highlight.js/lib/languages/json';
import markdown from 'highlight.js/lib/languages/markdown';
import yaml from 'highlight.js/lib/languages/yaml';
import plaintext from 'highlight.js/lib/languages/plaintext';

for (const [name, lang] of Object.entries({ php, 'php-template': phpTemplate, xml, javascript, typescript, css, json, markdown, yaml, plaintext })) {
  hljs.registerLanguage(name, lang);
}

for (const el of document.querySelectorAll('code.demo-code')) {
  const language = el.dataset.language;
  if (hljs.getLanguage(language)) {
    el.innerHTML = hljs.highlight(el.textContent, { language, ignoreIllegals: true }).value;
    el.classList.add('hljs');
  }
}
