/*
 * Previews for files that are not text: images, and PDFs rendered with
 * pdf.js (Apache-2.0), which is loaded only when a PDF is opened.
 *
 * The bytes come from the owner-only download route and are shown from a
 * blob: URL of this page. An SVG is drawn through <img>, where it cannot run
 * script. pdf.js is told not to use eval, and its worker is served from this
 * origin like Monaco's.
 */
const IMAGE = /\.(png|jpe?g|gif|webp|avif|svg|ico|bmp)$/i;
const PDF = /\.pdf$/i;
const TYPES = { svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
  webp: 'image/webp', avif: 'image/avif', ico: 'image/x-icon', bmp: 'image/bmp', pdf: 'application/pdf' };

export const previewKind = (path) => (IMAGE.test(path) ? 'image' : PDF.test(path) ? 'pdf' : null);

/** Fetches the file and returns { ok, url, size } with a typed blob: URL. */
export async function loadPreview(downloadUrl, path) {
  const url = new URL(downloadUrl, location.origin);
  url.searchParams.set('path', path);
  const r = await fetch(url, { credentials: 'same-origin' });
  if (!r.ok) {
    const j = await r.json().catch(() => ({}));
    return { ok: false, hint: j.hint ?? `HTTP ${r.status}` };
  }
  const ext = path.split('.').pop().toLowerCase();
  const blob = new Blob([await r.arrayBuffer()], { type: TYPES[ext] ?? 'application/octet-stream' });
  return { ok: true, url: URL.createObjectURL(blob), size: blob.size, blob };
}

/** Draws a preview into `box` (emptied first). Returns a function that frees it. */
export async function renderPreview(box, kind, loaded, path) {
  box.replaceChildren();
  const meta = document.createElement('p');
  meta.className = 'preview-meta';
  const name = path.split('/').pop();

  if (kind === 'image') {
    const img = document.createElement('img');
    img.alt = name;
    img.src = loaded.url;
    img.className = 'preview-image';
    img.addEventListener('load', () => {
      meta.textContent = `${name} - ${img.naturalWidth} x ${img.naturalHeight} px, ${formatSize(loaded.size)}`;
    });
    img.addEventListener('error', () => {
      meta.textContent = `${name} could not be shown as an image.`;
    });
    box.append(meta, img);
    return () => URL.revokeObjectURL(loaded.url);
  }

  // PDF: every page to a canvas, one after another.
  meta.textContent = `${name} - opening…`;
  box.append(meta);
  const pdfjs = await import('pdfjs-dist');
  const { default: PdfWorker } = await import('pdfjs-dist/build/pdf.worker.min.mjs?worker');
  if (!pdfjs.GlobalWorkerOptions.workerPort) pdfjs.GlobalWorkerOptions.workerPort = new PdfWorker();
  // In pdf.js 6 the LOADING TASK owns destroy(), not the document.
  const task = pdfjs.getDocument({ data: new Uint8Array(await loaded.blob.arrayBuffer()), isEvalSupported: false });
  let doc;
  try {
    doc = await task.promise;
  } catch (e) {
    meta.textContent = `${name} could not be read as a PDF: ${e.message}`;
    task.destroy();
    return () => URL.revokeObjectURL(loaded.url);
  }
  const shown = Math.min(doc.numPages, 50);
  meta.textContent = `${name} - ${doc.numPages} page${doc.numPages === 1 ? '' : 's'}, ${formatSize(loaded.size)}${shown < doc.numPages ? ` (first ${shown} shown)` : ''}`;
  const width = Math.min(box.clientWidth - 48, 900);
  for (let n = 1; n <= shown; n++) {
    const page = await doc.getPage(n);
    const base = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: (width / base.width) * (window.devicePixelRatio || 1) });
    const canvas = document.createElement('canvas');
    canvas.className = 'preview-page';
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    canvas.setAttribute('aria-label', `Page ${n} of ${doc.numPages}`);
    box.append(canvas);
    await page.render({ canvasContext: canvas.getContext('2d'), viewport, canvas }).promise;
  }
  return () => {
    task.destroy();
    URL.revokeObjectURL(loaded.url);
  };
}

function formatSize(n) {
  return n < 1024 ? `${n} B` : n < 1 << 20 ? `${(n / 1024).toFixed(1)} KB` : `${(n / (1 << 20)).toFixed(1)} MB`;
}
