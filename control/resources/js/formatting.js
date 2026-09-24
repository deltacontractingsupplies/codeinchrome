/*
 * Editing help from VS Code's own open-source toolbox:
 *
 *   Emmet (emmet-monaco-es, MIT)   ul>li*3 then Tab, in HTML, Blade and CSS
 *   Prettier (MIT), with @prettier/plugin-php (MIT)
 *                                   Format Document (⇧⌥F) for PHP and Markdown.
 *                                   Monaco formats JS, TS, CSS, HTML and JSON itself.
 *
 * Prettier is loaded the first time something is formatted, never before: it
 * is the largest thing here and most sessions never ask for it.
 */
import { emmetHTML, emmetCSS, expandAbbreviation } from 'emmet-monaco-es';

/* Tags Emmet may expand from a bare word; anything else needs an operator. */
const HTML_TAGS = new Set(('a abbr address article aside audio b blockquote body br button canvas caption code col '
  + 'dd details dialog div dl dt em fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 head header hr html i '
  + 'iframe img input label legend li link main mark menu meta nav ol optgroup option output p picture pre progress '
  + 'q section select small source span strong style sub summary sup table tbody td template textarea tfoot th thead '
  + 'time title tr u ul video').split(' '));

/*
 * Emmet on Tab, as VS Code's emmet.triggerExpansionOnTab: "ul>li*3" then Tab
 * becomes the list, with the cursor in its first item. Only an abbreviation
 * expands - a known tag, or one with Emmet's operators - so Tab still indents
 * everywhere else, and never inside a suggestion or a snippet.
 */
function installEmmetTab(monaco, editor) {
  const langs = new Set(['html', 'blade']);
  editor.addCommand(monaco.KeyCode.Tab, () => {
    const model = editor.getModel();
    const pos = editor.getPosition();
    const sel = editor.getSelection();
    const before = model && pos ? model.getLineContent(pos.lineNumber).slice(0, pos.column - 1) : '';
    const m = before.match(/(?:^|[\s>])([a-zA-Z!][\w.#>+*^$@\-:{}[\]()"'=]*)$/);
    const abbr = m?.[1];
    const emmetLike = abbr && (/[>+*.#[{^]/.test(abbr) || HTML_TAGS.has(abbr.toLowerCase()) || abbr === '!');
    if (!model || !sel?.isEmpty() || !langs.has(model.getLanguageId()) || !emmetLike || /<[^>]*$/.test(before)) {
      editor.trigger('keyboard', 'tab', {});
      return;
    }
    let snippet;
    try {
      snippet = expandAbbreviation(abbr, { type: 'markup', syntax: 'html', options: { 'output.indent': '    ' } });
    } catch {
      snippet = null;
    }
    if (!snippet) {
      editor.trigger('keyboard', 'tab', {});
      return;
    }
    editor.getContribution('snippetController2').insert(snippet, { overwriteBefore: abbr.length });
  }, 'editorTextFocus && !suggestWidgetVisible && !inSnippetMode && !editorHasSelection && !editorReadonly');
}

export function installEditingHelp(monaco, editor, { emmet = true, prettier: withPrettier = true } = {}) {
  const disposers = emmet ? [emmetHTML(monaco, ['html', 'blade']), emmetCSS(monaco, ['css', 'scss', 'less'])] : [];
  if (editor && emmet) installEmmetTab(monaco, editor);

  let prettier = null;
  const load = async () => {
    prettier ??= Promise.all([
      import('prettier/standalone'),
      import('@prettier/plugin-php/standalone'),
      import('prettier/plugins/markdown'),
    ]).then(([core, php, markdown]) => ({ format: core.format, plugins: [php.default ?? php, markdown.default ?? markdown] }));
    return prettier;
  };

  const provider = (parser, extra = {}) => ({
    async provideDocumentFormattingEdits(model, options) {
      const { format, plugins } = await load();
      try {
        const text = await format(model.getValue(), {
          parser, plugins, tabWidth: options.tabSize, useTabs: !options.insertSpaces, ...extra,
        });
        return text === model.getValue() ? [] : [{ range: model.getFullModelRange(), text }];
      } catch (error) {
        // A file that does not parse is left exactly as it is.
        console.warn('format: not formatted -', error.message);
        return [];
      }
    },
  });
  if (withPrettier) {
    disposers.push(
      monaco.languages.registerDocumentFormattingEditProvider('php', provider('php', { phpVersion: '8.3', singleQuote: true })),
      monaco.languages.registerDocumentFormattingEditProvider('markdown', provider('markdown', { proseWrap: 'preserve' })),
    );
  }
  return { dispose: () => disposers.forEach((d) => d?.dispose?.() ?? (typeof d === 'function' && d())) };
}
