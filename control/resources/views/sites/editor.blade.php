<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- For the code editor's own <style> elements only; see csp-nonce.js. --}}
    <meta name="csp-nonce" content="{{ $cspNonce }}">
    <title>{{ $site->site_id }} — codeinchrome</title>
    <script src="/theme.js"></script>
    @vite(['resources/css/editor.css', 'resources/js/editor.js'])
</head>
<body>
{{-- Everything the script needs, as data rather than inline code, so the page
     can run under a strict Content-Security-Policy later. --}}
<div id="cic-app"
     data-site="{{ $site->site_id }}"
     data-domain="{{ $site->domain }}"
     data-url="{{ $site->url() }}"
     data-files="{{ route('files.index', $site) }}"
     data-db-tables="{{ route('db.tables', $site) }}"
     data-db-query="{{ route('db.query', $site) }}"
     data-db-export="{{ route('db.export', $site) }}"
     data-lsp="{{ route('lsp.exchange', $site) }}"
     data-mcp="{{ route('mcp.call', $site) }}"
     data-lsp-close="{{ url('/sites/'.$site->site_id.'/lsp') }}"
     data-db-import="{{ route('db.import', $site) }}"
     data-command="{{ route('console.run', $site) }}"
     data-logs="{{ route('console.logs', $site) }}"
     data-history="{{ route('history.index', $site) }}"
     data-bin="{{ route('history.bin', $site) }}"
     data-restore="{{ route('history.restore', $site) }}"
     data-mkdir="{{ route('files.mkdir', $site) }}"
     data-move="{{ route('files.move', $site) }}"
     data-copy="{{ route('files.copy', $site) }}"
     data-zip="{{ route('files.zip', $site) }}"
     data-unzip="{{ route('files.unzip', $site) }}"
     data-tree="{{ route('files.tree.destroy', $site) }}"
     data-search="{{ route('files.search', $site) }}"
     data-upload="{{ route('files.upload', $site) }}"
     data-download="{{ route('files.download', $site) }}"
     data-back="{{ route('dashboard') }}">

    <header class="titlebar">
        <a class="back" href="{{ route('dashboard') }}" title="Back to your sites">code<span>in</span>chrome</a>
        <span class="title" id="winTitle">{{ $site->site_id }}</span>
        <button type="button" class="open-site" data-theme-toggle title="Switch between system, light and dark">Theme: <span data-theme-label>System</span></button>
        <a class="open-site" href="{{ $site->url() }}" target="_blank" rel="noopener">Open site ↗</a>
    </header>

    <div class="workbench">
        <aside class="sidebar" aria-label="Explorer">
            <div class="side-modes" role="tablist">
                <button type="button" id="modeFiles" class="on" role="tab">Files</button>
                <button type="button" id="modeDb" role="tab">Database</button>
                <button type="button" id="modeHistory" role="tab">History</button>
            </div>
            <div class="side-head" id="filesHead">
                <span>EXPLORER</span>
                <span class="side-actions">
                    <button type="button" id="btnNew" title="New file" aria-label="New file"><i class="ci ci-new-file" aria-hidden="true"></i></button>
                    <button type="button" id="btnNewFolder" title="New folder" aria-label="New folder"><i class="ci ci-new-folder" aria-hidden="true"></i></button>
                    <button type="button" id="btnUpload" title="Upload files" aria-label="Upload files"><i class="ci ci-cloud-upload" aria-hidden="true"></i></button>
                    <button type="button" id="btnSearch" title="Search in files" aria-label="Search in files"><i class="ci ci-search" aria-hidden="true"></i></button>
                    <button type="button" id="btnRefresh" title="Refresh" aria-label="Refresh"><i class="ci ci-refresh" aria-hidden="true"></i></button>
                    <input type="file" id="uploadInput" multiple hidden>
                </span>
            </div>
            <div class="side-site">{{ strtoupper($site->site_id) }}</div>
            <form id="searchBar" class="searchbar" hidden>
                <input id="searchInput" type="search" placeholder="Search in files" autocomplete="off" spellcheck="false" aria-label="Search in files">
            </form>
            <div id="searchResults" class="tree" hidden></div>
            <div id="tree" class="tree" role="tree"></div>
            <div id="nodeMenu" class="nodemenu" role="menu" hidden></div>
            <div id="dbSide" class="tree" hidden>
                <div class="side-head"><span id="dbName">DATABASE</span>
                    <span class="side-actions"><button type="button" id="btnDbRefresh" title="Refresh" aria-label="Refresh"><i class="ci ci-refresh" aria-hidden="true"></i></button></span>
                </div>
                <div id="dbTables"></div>
                <div class="side-head"><span>BACKUP</span></div>
                <div class="dbtransfer">
                    <button type="button" id="btnDbExport" title="Download the whole database as a .sql.gz file">Export database</button>
                    <button type="button" id="btnDbImport" title="Load a .sql or .sql.gz file (up to 95 MB). The current database is saved first.">Import a .sql file</button>
                    <input type="file" id="dbImportFile" accept=".sql,.gz,application/sql,application/gzip" hidden>
                    <a id="dbUndoImport" href="#" hidden>Download the database from before the last import</a>
                </div>
            </div>
            <div id="historySide" class="tree" hidden>
                <div class="side-head"><span id="historyTitle">THIS FILE</span>
                    <span class="side-actions"><button type="button" id="btnHistoryRefresh" title="Refresh" aria-label="Refresh"><i class="ci ci-refresh" aria-hidden="true"></i></button></span>
                </div>
                <div id="historyList"></div>
                <div class="side-head"><span>BIN - DELETED FILES</span></div>
                <div id="binList"></div>
            </div>
        </aside>

        <main class="editor-area">
            <div id="tabs" class="tabs" role="group" aria-label="Open files"></div>
            <div id="conflict" class="conflict" hidden>
                <span id="conflictText"></span>
                <button type="button" id="btnTheirs">Load the saved version</button>
                <button type="button" id="btnMine">Keep mine and overwrite</button>
            </div>
            <div id="versionBar" class="conflict" hidden>
                <span id="versionText"></span>
                <button type="button" id="btnRestoreVersion">Restore this version</button>
                <button type="button" id="btnCloseVersion">Close</button>
            </div>
            <div class="crumbbar"><div id="crumb" class="crumb"></div>
                <button type="button" id="btnMdPreview" class="crumb-btn" hidden aria-pressed="false">Preview</button></div>
            <div id="preview" class="preview" hidden></div>
            <div class="editor" id="editorWrap">
                {{-- Monaco, the editor inside VS Code, mounts here (resources/js/monaco.js). --}}
                <div id="monaco" class="monaco-host"></div>
            </div>
            <section id="dbPanel" class="dbpanel" hidden>
                <div class="sqlbar">
                    <textarea id="sql" spellcheck="false" aria-label="SQL statement" placeholder="SELECT * FROM users LIMIT 100"></textarea>
                    <button type="button" id="btnRun" class="run" title="Run (⌘/Ctrl Enter)">Run</button>
                </div>
                <div id="dbMeta" class="dbmeta">Runs as this site's own database user. Statements that change data ask first.</div>
                <div class="grid-wrap"><table id="dbGrid" class="grid"></table></div>
            </section>
            <div id="empty" class="empty">
                <p>Open a file from the explorer.</p>
                <p class="hint">Save with <kbd>⌘S</kbd> / <kbd>Ctrl S</kbd>. An AI agent can drive this page through <code>window.cic</code> — run <code>cic.help()</code> in the console.</p>
            </div>
            <section id="panel" class="panel" hidden>
                <div class="panel-tabs" role="tablist">
                    <button type="button" id="ptTerminal" class="on" role="tab">Terminal</button>
                    <button type="button" id="ptLogs" role="tab">Logs</button>
                    <span class="panel-spacer"></span>
                    <button type="button" id="ptClose" title="Close panel (Ctrl `)">✕</button>
                </div>
                <div id="termView" class="panel-body">
                    <pre id="termOut" class="term-out" aria-live="polite"></pre>
                    <form id="termForm" class="term-line">
                        <select id="termTool" aria-label="Tool"><option>artisan</option><option>composer</option></select>
                        <input id="termArgs" autocomplete="off" spellcheck="false" placeholder="migrate:status" aria-label="Arguments">
                        <button type="submit">Run</button>
                    </form>
                </div>
                <div id="logsView" class="panel-body" hidden>
                    <div class="logs-bar">
                        <select id="logSource" aria-label="Log">
                            <option value="app">Laravel (storage/logs)</option>
                            <option value="access">Requests</option>
                            <option value="container">PHP &amp; Apache</option>
                        </select>
                        <button type="button" id="logRefresh">Refresh</button>
                        <span id="logMeta" class="logs-meta"></span>
                    </div>
                    <pre id="logOut" class="term-out"></pre>
                </div>
            </section>
        </main>
    </div>

    <footer class="statusbar">
        <span id="sbSite">{{ $site->domain }}</span>
        <span id="sbMsg" role="status" aria-live="polite"></span>
        <span class="right"><button type="button" id="sbPanel" class="sb-btn" title="Terminal and logs (Ctrl `)"><i class="ci ci-terminal" aria-hidden="true"></i> Terminal</button><span id="sbLsp" title="PHP IntelliSense (Phpactor), starting">PHP</span><span id="sbPos"></span><span id="sbRev"></span></span>
    </footer>

    {{-- In-page dialog. Never alert/confirm/prompt: a native dialog freezes
         the page for a browser-driving agent, which cannot dismiss it. --}}
    <div id="modal" class="modal" hidden>
        <form id="modalForm" class="modal-box">
            <p id="modalText"></p>
            <input id="modalInput" type="text" autocomplete="off" spellcheck="false" hidden>
            <p id="modalError" class="modal-error"></p>
            <div class="modal-actions">
                <button type="button" id="modalCancel">Cancel</button>
                <button type="submit" id="modalOk" class="primary">OK</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
