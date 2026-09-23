/*
 * Monaco creates <style> elements as it runs, and the editor page's
 * Content-Security-Policy allows no inline style except with this request's
 * nonce. So every <style> made from here on carries the nonce - set before
 * the element is inserted, which is when the browser checks it. Imported
 * FIRST by monaco.js: ES modules run in import order.
 *
 * Nothing else is loosened: script-src stays 'self', and a style attribute
 * or a <style> injected any other way (by markup, not by this page's own
 * code) is still refused.
 */
const nonce = document.querySelector('meta[name="csp-nonce"]')?.content;
if (nonce) {
  const create = Document.prototype.createElement;
  Document.prototype.createElement = function (tag, options) {
    const el = create.call(this, tag, options);
    if (typeof tag === 'string' && tag.toLowerCase() === 'style') el.nonce = nonce;
    return el;
  };
}
