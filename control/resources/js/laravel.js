/*
 * Laravel awareness in the editor, for what a PHP language server cannot
 * know: Laravel's string conventions.
 *
 *   route('...')  / to_route / redirect()->route  completes the site's route names
 *   view('...')   / View::make / @include / @extends / @each / @component
 *                                   completes view names, and F12 / ⌘-click (go
 *                                   to definition) opens the Blade file
 *   config('...') / Config::get   completes config keys, file then key
 *   <x-...>                       completes Blade component names (anonymous
 *                                   components in resources/views/components
 *                                   and class components in app/View/Components)
 *
 * The names come from the site itself - routes from `artisan route:list
 * --json` in its container, views from resources/views - fetched once and
 * refreshed at most every 30 seconds while someone is typing.
 */
const ROUTE_CALL = /(?:\broute|to_route|->route|URL::route|redirect\(\)->route)\(\s*['"]([\w.\-:]*)$/;
const VIEW_CALL = /(?:\bview|View::make|->view|@include(?:If|When|First)?|@extends|@each|@component)\(\s*['"]([\w.\-:/]*)$/;
const CONFIG_CALL = /(?:\bconfig|Config::(?:get|string|integer|boolean|array))\(\s*['"]([\w.\-]*)$/;
const COMPONENT_TAG = /<x-([\w.\-:]*)$/;
const VIEW_AT = /(?:\bview|View::make|->view|@include(?:If|When|First)?|@extends|@each|@component)\(\s*['"]([\w.\-:/]+)['"]/g;

export function installLaravelProviders({ monaco, listDir, readFile, runArtisan, fileUri }) {
  let routes = { at: 0, names: [] };
  let views = { at: 0, names: [] };
  let configs = { at: 0, names: [] };
  let components = { at: 0, names: [] };

  // config/<file>.php: the file name is the first segment, each top-level
  // 'key' => of its returned array the second (read as text, never run).
  async function configNames() {
    if (Date.now() - configs.at < 30_000) return configs.names;
    configs.at = Date.now();
    const out = [];
    const files = (await listDir('/config')).filter((e) => !e.dir && e.name.endsWith('.php'));
    // In parallel: read one by one, a cold list arrived after Monaco had
    // given up on the suggestion it was for.
    const texts = await Promise.all(files.map((e) => readFile(`/config/${e.name}`)));
    files.forEach((e, i) => {
      const file = e.name.slice(0, -4);
      out.push(file);
      for (const m of texts[i].matchAll(/^ {4}'([\w\-]+)'\s*=>/gm)) out.push(`${file}.${m[1]}`);
    });
    configs.names = [...new Set(out)].sort();
    return configs.names;
  }

  // Anonymous components (resources/views/components/a/b.blade.php -> a.b)
  // and class components (app/View/Components/UserCard.php -> user-card).
  async function componentNames() {
    if (Date.now() - components.at < 30_000) return components.names;
    components.at = Date.now();
    const out = [];
    const kebab = (n) => n.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
    const walk = async (dir, prefix, depth, suffix, name) => {
      if (depth > 5 || out.length > 1000) return;
      for (const e of await listDir(dir)) {
        if (e.dir) await walk(`${dir}/${e.name}`, `${prefix}${name(e.name)}.`, depth + 1, suffix, name);
        else if (e.name.endsWith(suffix)) out.push(prefix + name(e.name.slice(0, -suffix.length)));
      }
    };
    await walk('/resources/views/components', '', 0, '.blade.php', (n) => n);
    await walk('/app/View/Components', '', 0, '.php', kebab);
    components.names = [...new Set(out.map((n) => n.replace(/\.index$/, '')))].sort();
    return components.names;
  }

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
    const kinds = [
      [ROUTE_CALL, routeNames, 'Reference', () => 'route'],
      [VIEW_CALL, viewNames, 'File', (n) => `resources/views/${n.replaceAll('.', '/')}.blade.php`],
      [CONFIG_CALL, configNames, 'Property', (n) => (n.includes('.') ? 'config key' : `config/${n}.php`)],
      [COMPONENT_TAG, componentNames, 'Class', () => 'Blade component'],
    ];
    for (const [rx, names, kind, detail] of kinds) {
      const m = text.match(rx);
      if (!m) continue;
      const typed = m[1];
      const range = new monaco.Range(position.lineNumber, position.column - typed.length, position.lineNumber, position.column);
      return {
        suggestions: (await names()).map((name) => ({
          label: name, kind: monaco.languages.CompletionItemKind[kind], detail: detail(name), insertText: name, range,
        })),
      };
    }
    return { suggestions: [] };
  };

  const definition = async (model, position) => {
    const line = model.getLineContent(position.lineNumber);
    for (const m of line.matchAll(VIEW_AT)) {
      // 1-based column of the name's first letter; the quotes around it count
      // too, as in VS Code: a click on the string lands on its opening quote.
      const start = m.index + m[0].indexOf(m[1]) + 1;
      if (position.column >= start - 1 && position.column <= start + m[1].length + 1) {
        const path = `/resources/views/${m[1].replaceAll('.', '/')}.blade.php`;
        return [{ uri: fileUri(path), range: new monaco.Range(1, 1, 1, 1) }];
      }
    }
    return null;
  };

  const disposables = [];
  for (const language of ['php', 'blade']) {
    disposables.push(monaco.languages.registerCompletionItemProvider(language, { triggerCharacters: ["'", '"', '.', '-'], provideCompletionItems: complete }));
    disposables.push(monaco.languages.registerDefinitionProvider(language, { provideDefinition: definition }));
  }
  // Loaded shortly after the editor opens, so the first suggestion is ready
  // before anyone types (a cold fetch can outlast Monaco's patience).
  // Not route names: those run artisan, which holds the site's one-command
  // lock - a person's own command in the first seconds would be "busy".
  setTimeout(() => {
    viewNames();
    configNames();
    componentNames();
  }, 2500);
  return { routeNames, viewNames, configNames, componentNames, dispose: () => disposables.forEach((d) => d.dispose()) };
}
