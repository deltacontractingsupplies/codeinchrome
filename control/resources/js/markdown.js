/*
 * Markdown preview. marked (MIT) turns the text into HTML and DOMPurify
 * (Apache-2.0) removes anything that could run - scripts, event handlers,
 * javascript: links - before it touches the page. The page's CSP forbids
 * inline script anyway; this makes the preview safe without leaning on it.
 * Remote images are dropped: the preview never makes the browser fetch from
 * another site a README points at.
 */
import { marked } from 'marked';
import DOMPurify from 'dompurify';

DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer');
  }
  if (node.tagName === 'IMG' && !/^(data:image\/|\/)/.test(node.getAttribute('src') ?? '')) {
    node.remove();
  }
});

export function renderMarkdown(box, text) {
  const html = marked.parse(text, { gfm: true, breaks: false, async: false });
  const clean = DOMPurify.sanitize(html, { USE_PROFILES: { html: true }, FORBID_TAGS: ['style', 'form', 'input', 'button'], FORBID_ATTR: ['style'] });
  const article = document.createElement('article');
  article.className = 'markdown';
  article.innerHTML = clean;
  box.replaceChildren(article);
}
