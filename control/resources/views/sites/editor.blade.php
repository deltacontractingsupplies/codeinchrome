<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $site->site_id }} — codeinchrome</title>
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
     data-back="{{ route('dashboard') }}">

    <header class="titlebar">
        <a class="back" href="{{ route('dashboard') }}" title="Back to your sites">code<span>in</span>chrome</a>
        <span class="title" id="winTitle">{{ $site->site_id }}</span>
        <a class="open-site" href="{{ $site->url() }}" target="_blank" rel="noopener">Open site ↗</a>
    </header>

    <div class="workbench">
        <aside class="sidebar" aria-label="Explorer">
            <div class="side-head">
                <span>EXPLORER</span>
                <span class="side-actions">
                    <button type="button" id="btnNew" title="New file">＋</button>
                    <button type="button" id="btnRefresh" title="Refresh">⟳</button>
                </span>
            </div>
            <div class="side-site">{{ strtoupper($site->site_id) }}</div>
            <div id="tree" class="tree" role="tree"></div>
        </aside>

        <main class="editor-area">
            <div id="tabs" class="tabs" role="tablist"></div>
            <div id="conflict" class="conflict" hidden>
                <span id="conflictText"></span>
                <button type="button" id="btnTheirs">Load the saved version</button>
                <button type="button" id="btnMine">Keep mine and overwrite</button>
            </div>
            <div id="crumb" class="crumb"></div>
            <div class="editor" id="editorWrap">
                <div id="gutter" class="gutter" aria-hidden="true"></div>
                <div class="code">
                    <pre id="hl" class="hl" aria-hidden="true"></pre>
                    <textarea id="ta" spellcheck="false" autocapitalize="off" autocomplete="off"
                              aria-label="File contents" disabled></textarea>
                </div>
            </div>
            <div id="empty" class="empty">
                <p>Open a file from the explorer.</p>
                <p class="hint">Save with <kbd>⌘S</kbd> / <kbd>Ctrl S</kbd>. An AI agent can drive this page through <code>window.cic</code> — run <code>cic.help()</code> in the console.</p>
            </div>
        </main>
    </div>

    <footer class="statusbar">
        <span id="sbSite">{{ $site->domain }}</span>
        <span id="sbMsg" role="status" aria-live="polite"></span>
        <span class="right"><span id="sbPos"></span><span id="sbRev"></span></span>
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
