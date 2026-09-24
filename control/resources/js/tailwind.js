/*
 * Tailwind CSS class completion inside class="..." in Blade and HTML - the
 * part of Tailwind CSS IntelliSense a page needs, generated here from
 * Tailwind's documented scales rather than by running its language server.
 *
 * Offered only on a site that uses Tailwind (its views mention it: the CDN
 * script, @tailwind, @import "tailwindcss"), so a plain-CSS site is not
 * flooded with class names it does not have. Variants complete too:
 * "md:hover:bg-" offers every background colour with that prefix.
 */
const SPACING = ['0', 'px', '0.5', '1', '1.5', '2', '2.5', '3', '3.5', '4', '5', '6', '7', '8', '9', '10', '11', '12', '14', '16', '20', '24', '28', '32', '36', '40', '44', '48', '52', '56', '60', '64', '72', '80', '96'];
const COLORS = ['slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose'];
const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const VARIANTS = ['sm', 'md', 'lg', 'xl', '2xl', 'hover', 'focus', 'focus-visible', 'focus-within', 'active', 'disabled', 'first', 'last', 'odd', 'even', 'group-hover', 'peer-checked', 'dark', 'print', 'motion-safe', 'motion-reduce'];

let cache = null;

function classNames() {
  if (cache) return cache;
  const out = new Set();
  const add = (...names) => names.forEach((n) => out.add(n));
  const each = (prefixes, values) => prefixes.forEach((p) => values.forEach((v) => out.add(v === '' ? p : `${p}-${v}`)));

  add('block', 'inline-block', 'inline', 'flex', 'inline-flex', 'grid', 'inline-grid', 'hidden', 'contents', 'table', 'table-row', 'table-cell', 'flow-root',
    'static', 'fixed', 'absolute', 'relative', 'sticky', 'isolate', 'container', 'sr-only', 'not-sr-only', 'visible', 'invisible',
    'flex-row', 'flex-row-reverse', 'flex-col', 'flex-col-reverse', 'flex-wrap', 'flex-nowrap', 'flex-1', 'flex-auto', 'flex-initial', 'flex-none',
    'grow', 'grow-0', 'shrink', 'shrink-0', 'uppercase', 'lowercase', 'capitalize', 'normal-case', 'italic', 'not-italic', 'underline', 'overline',
    'line-through', 'no-underline', 'truncate', 'antialiased', 'subpixel-antialiased', 'transition', 'transition-all', 'transition-colors',
    'transition-opacity', 'transition-shadow', 'transition-transform', 'transition-none', 'cursor-pointer', 'cursor-default', 'cursor-not-allowed',
    'cursor-wait', 'cursor-text', 'cursor-move', 'select-none', 'select-text', 'select-all', 'pointer-events-none', 'pointer-events-auto',
    'resize', 'resize-none', 'resize-y', 'resize-x', 'appearance-none', 'outline-none', 'list-none', 'list-disc', 'list-decimal', 'list-inside',
    'list-outside', 'border', 'border-solid', 'border-dashed', 'border-dotted', 'border-none', 'rounded', 'shadow', 'ring', 'ring-inset', 'blur',
    'grayscale', 'invert', 'sepia', 'object-contain', 'object-cover', 'object-fill', 'object-none', 'object-center', 'mx-auto', 'my-auto', 'm-auto',
    'aspect-square', 'aspect-video', 'aspect-auto', 'break-words', 'break-all', 'text-wrap', 'text-nowrap', 'text-balance', 'text-pretty', 'prose');

  each(['p', 'px', 'py', 'pt', 'pr', 'pb', 'pl', 'ps', 'pe', 'm', 'mx', 'my', 'mt', 'mr', 'mb', 'ml', 'ms', 'me', 'gap', 'gap-x', 'gap-y',
    'space-x', 'space-y', 'inset', 'inset-x', 'inset-y', 'top', 'right', 'bottom', 'left', 'w', 'h', 'size', 'min-h', 'translate-x', 'translate-y', 'scroll-m', 'scroll-p'], SPACING);
  each(['-m', '-mx', '-my', '-mt', '-mr', '-mb', '-ml', '-top', '-right', '-bottom', '-left', '-translate-x', '-translate-y'], SPACING.filter((v) => v !== '0'));
  each(['m', 'mx', 'my', 'mt', 'mr', 'mb', 'ml', 'w', 'h', 'inset', 'top', 'right', 'bottom', 'left', 'size'], ['auto']);
  each(['w', 'h', 'size'], ['full', 'screen', 'min', 'max', 'fit', '1/2', '1/3', '2/3', '1/4', '3/4', '1/5', '2/5', '3/5', '4/5', '1/6', '5/6', 'svh', 'dvh']);
  each(['min-w', 'max-h', 'min-h'], ['0', 'full', 'screen', 'min', 'max', 'fit']);
  each(['max-w'], ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', 'full', 'min', 'max', 'fit', 'prose', 'screen-sm', 'screen-md', 'screen-lg', 'screen-xl', 'screen-2xl']);
  each(['text'], ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', '8xl', '9xl', 'left', 'center', 'right', 'justify', 'start', 'end']);
  each(['font'], ['thin', 'extralight', 'light', 'normal', 'medium', 'semibold', 'bold', 'extrabold', 'black', 'sans', 'serif', 'mono']);
  each(['leading'], ['none', 'tight', 'snug', 'normal', 'relaxed', 'loose', '3', '4', '5', '6', '7', '8', '9', '10']);
  each(['tracking'], ['tighter', 'tight', 'normal', 'wide', 'wider', 'widest']);
  each(['whitespace'], ['normal', 'nowrap', 'pre', 'pre-line', 'pre-wrap', 'break-spaces']);
  each(['items'], ['start', 'end', 'center', 'baseline', 'stretch']);
  each(['justify'], ['start', 'end', 'center', 'between', 'around', 'evenly', 'stretch', 'normal']);
  each(['content', 'place-content', 'place-items', 'self', 'justify-self', 'justify-items'], ['start', 'end', 'center', 'stretch', 'between', 'around', 'auto', 'baseline']);
  each(['grid-cols', 'grid-rows'], ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'none', 'subgrid']);
  each(['col-span', 'row-span'], ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'full']);
  each(['col-start', 'col-end', 'row-start', 'row-end', 'order'], ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'first', 'last', 'none', 'auto']);
  each(['overflow', 'overflow-x', 'overflow-y'], ['auto', 'hidden', 'clip', 'visible', 'scroll']);
  each(['z'], ['0', '10', '20', '30', '40', '50', 'auto']);
  each(['opacity'], ['0', '5', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55', '60', '65', '70', '75', '80', '85', '90', '95', '100']);
  each(['rounded', 'rounded-t', 'rounded-r', 'rounded-b', 'rounded-l', 'rounded-tl', 'rounded-tr', 'rounded-bl', 'rounded-br'], ['', 'none', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', 'full']);
  each(['border', 'border-t', 'border-r', 'border-b', 'border-l', 'border-x', 'border-y', 'divide-x', 'divide-y', 'ring', 'outline', 'ring-offset'], ['', '0', '2', '4', '8']);
  each(['shadow'], ['sm', 'md', 'lg', 'xl', '2xl', 'inner', 'none']);
  each(['duration', 'delay'], ['0', '75', '100', '150', '200', '300', '500', '700', '1000']);
  each(['ease'], ['linear', 'in', 'out', 'in-out']);
  each(['scale'], ['0', '50', '75', '90', '95', '100', '105', '110', '125', '150']);
  each(['rotate', '-rotate'], ['0', '1', '2', '3', '6', '12', '45', '90', '180']);
  each(['animate'], ['none', 'spin', 'ping', 'pulse', 'bounce']);
  each(['bg'], ['cover', 'contain', 'center', 'no-repeat', 'fixed', 'gradient-to-r', 'gradient-to-l', 'gradient-to-t', 'gradient-to-b', 'gradient-to-br', 'gradient-to-bl', 'gradient-to-tr', 'gradient-to-tl']);

  const colorUtils = ['text', 'bg', 'border', 'border-t', 'border-b', 'ring', 'ring-offset', 'divide', 'outline', 'from', 'via', 'to', 'fill', 'stroke', 'decoration', 'placeholder', 'accent', 'caret', 'shadow'];
  each(colorUtils, ['white', 'black', 'transparent', 'current', 'inherit']);
  for (const u of colorUtils) for (const c of COLORS) for (const s of SHADES) out.add(`${u}-${c}-${s}`);

  cache = [...out];
  return cache;
}

/** Whether the site uses Tailwind, asked once: its views mention it. */
function makeDetector(search) {
  let answer = null;
  return async () => {
    if (answer === null) {
      answer = search('tailwind').then((r) => Boolean(r?.ok && r.hits?.some((h) => /views|\.css|package\.json|vite\.config/.test(h.path))))
        .catch(() => false);
    }
    return answer;
  };
}

export function installTailwind({ monaco, search }) {
  const usesTailwind = makeDetector(search);
  const inClassAttr = /(?:\bclass|:class|className)\s*=\s*["'][^"']*$/;
  const provider = {
    triggerCharacters: ['"', "'", ' ', ':', '-'],
    async provideCompletionItems(model, position) {
      const before = model.getValueInRange({ startLineNumber: position.lineNumber, startColumn: 1, endLineNumber: position.lineNumber, endColumn: position.column });
      if (!inClassAttr.test(before) || !(await usesTailwind())) return { suggestions: [] };
      const word = before.match(/[\w:./\-[\]#%]*$/)[0];
      const colon = word.lastIndexOf(':');
      const variant = colon >= 0 ? word.slice(0, colon + 1) : '';
      const range = new monaco.Range(position.lineNumber, position.column - word.length, position.lineNumber, position.column);
      const names = classNames();
      const suggestions = names.map((n) => ({
        label: variant + n,
        kind: monaco.languages.CompletionItemKind.Constant,
        detail: 'Tailwind CSS',
        insertText: variant + n,
        range,
        sortText: n.length.toString().padStart(3, '0') + n,
      }));
      // A bare word also offers the variants themselves: "ho" -> "hover:".
      if (!variant) {
        for (const v of VARIANTS) {
          suggestions.push({ label: `${v}:`, kind: monaco.languages.CompletionItemKind.Keyword, detail: 'Tailwind variant', insertText: `${v}:`, range,
            command: { id: 'editor.action.triggerSuggest', title: '' }, sortText: `000${v}` });
        }
      }
      return { suggestions, incomplete: false };
    },
  };
  return ['html', 'blade'].map((lang) => monaco.languages.registerCompletionItemProvider(lang, provider));
}

export { classNames as tailwindClassNames };
