/*
 * Laravel awareness in the editor, for what a PHP language server cannot
 * know: Laravel's string conventions.
 *
 *   route('...')  / to_route / redirect()->route  completes the site's route names
 *   view('...')   / View::make / @include / @extends / @each / <x-...>
 *                                   completes view names, and ⌘-click (go to
 *                                   definition) opens the Blade file
 *
 * The names come from the site itself - routes from `artisan route:list
 * --json` in its container, views from resources/views - fetched once and
 * refreshed at most every 30 seconds while someone is typing.
 */
const ROUTE_CALL = /(?:\broute|to_route|->route|URL::route|redirect\(\)->route)\(\s*['"]([\w.\-:]*)$/;
const VIEW_CALL = /(?:\bview|View::make|->view|@include(?:If|When|First)?|@extends|@each|@component)\(\s*['"]([\w.\-:/]*)$/;
const VIEW_AT = /(?:\bview|View::make|->view|@include(?:If|When|First)?|@extends|@each|@component)\(\s*['"]([\w.\-:/]+)['"]/g;

export function installLaravelProviders({ monaco, listDir, runArtisan, fileUri }) {
  let routes = { at: 0, names: [] };
  let views = { at: 0, names: [] };

  async function routeNames() {
    if (Date.now() - routes.at < 30_000) return routes.names;
    routes.at = Date.now();
    const out = await runArtisan(['route:list', '--json']);
    try {
      const list = JSON.parse(out.slice(out.indexOf('[')));
      routes.names = [...new Set(list.map((r) => r.name).filter((n) => n && !n.startsWith('generated::') && !n.endsWith('.')))].sort();
    } catch {
      // A site whose routes do not load keeps whatever was known.
    }
    return routes.names;
  }

  async function viewNames() {
    if (Date.now() - views.at < 30_000) return views.names;
    views.at = Date.now();
    const found = [];
    const walk = async (dir, prefix, depth) => {
      if (depth > 6 || found.length > 2000) return;
      const entries = await listDir(dir);
      for (const e of entries) {
        if (e.dir) await walk(`${dir}/${e.name}`, `${prefix}${e.name}.`, depth + 1);
        else if (e.name.endsWith('.blade.php')) found.push(prefix + e.name.slice(0, -'.blade.php'.length));
      }
    };
    await walk('/resources/views', '', 0);
    views.names = found.sort();
    return views.names;
  }

  const before = (model, position) => model.getValueInRange({
    startLineNumber: position.lineNumber, startColumn: 1, endLineNumber: position.lineNumber, endColumn: position.column,
  });

  const complete = async (model, position) => {
    const text = before(model, position);
    const r = text.match(ROUTE_CALL);
    const v = !r && text.match(VIEW_CALL);
    if (!r && !v) return { suggestions: [] };
    const typed = (r ?? v)[1];
    const range = new monaco.Range(position.lineNumber, position.column - typed.length, position.lineNumber, position.column);
    const names = r ? await routeNames() : await viewNames();
    return {
      suggestions: names.map((name) => ({
        label: name,
        kind: r ? monaco.languages.CompletionItemKind.Reference : monaco.languages.CompletionItemKind.File,
        detail: r ? 'route' : `resources/views/${name.replaceAll('.', '/')}.blade.php`,
        insertText: name,
        range,
      })),
    };
  };

  const definition = async (model, position) => {
    const line = model.getLineContent(position.lineNumber);
    for (const m of line.matchAll(VIEW_AT)) {
      const start = m.index + m[0].indexOf(m[1]) + 1;
      if (position.column >= start && position.column <= start + m[1].length) {
        const path = `/resources/views/${m[1].replaceAll('.', '/')}.blade.php`;
        return [{ uri: fileUri(path), range: new monaco.Range(1, 1, 1, 1) }];
      }
    }
    return null;
  };

  const disposables = [];
  for (const language of ['php', 'blade']) {
    disposables.push(monaco.languages.registerCompletionItemProvider(language, { triggerCharacters: ["'", '"', '.'], provideCompletionItems: complete }));
    disposables.push(monaco.languages.registerDefinitionProvider(language, { provideDefinition: definition }));
  }
  return { routeNames, viewNames, dispose: () => disposables.forEach((d) => d.dispose()) };
}
