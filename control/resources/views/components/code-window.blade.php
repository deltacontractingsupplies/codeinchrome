@props(['demoKey', 'domain', 'tree', 'path', 'file', 'count' => 0, 'title' => null])
{{--
    The editor, read-only: the same explorer, icons, tab, breadcrumbs and
    colours as the real one (resources/css/code-window.css), showing a demo's
    code as it is on the live site now. Files open in the demo's source page.
--}}
@php
    // An icon with a light-theme variant is drawn twice and the theme shows one;
    // most have none, and their one image shows in both themes.
    $icon = function (?array $pair, string $class = '') {
        if (! $pair || ! $pair['dark']) {
            return '';
        }
        if (! $pair['light'] || $pair['light'] === $pair['dark']) {
            return '<img class="cw-ic '.$class.'" src="'.e($pair['dark']).'" alt="">';
        }

        return '<img class="cw-ic cw-ic-dark '.$class.'" src="'.e($pair['dark']).'" alt="">'
            .'<img class="cw-ic cw-ic-light '.$class.'" src="'.e($pair['light']).'" alt="">';
    };
    $name = $path !== '' ? basename($path) : '';
    $crumbs = $path !== '' ? explode('/', ltrim($path, '/')) : [];
    $fileIcon = $name !== '' ? app(\App\Support\FileIcons::class)->for($name) : null;
@endphp
<div {{ $attributes->merge(['class' => 'cw']) }}>
    <div class="cw-title">
        <span class="cw-dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="cw-title-text">{{ $title ?? ($name !== '' ? "$name — $domain" : $domain) }}</span>
        <span>Read-only</span>
    </div>
    <div class="cw-body">
        <aside class="cw-side">
            <div class="cw-side-head">EXPLORER</div>
            <div class="cw-root">{{ strtoupper(explode('.', $domain)[0]) }}</div>
            <nav class="cw-tree" aria-label="Files">
                @if ($tree)
                    @include('components.code-window-tree', ['node' => $tree, 'prefix' => '', 'depth' => 0, 'icon' => $icon])
                @endif
            </nav>
        </aside>
        <section class="cw-main" aria-label="{{ ltrim($path, '/') ?: 'Code' }}">
            @if ($name !== '')
                <div class="cw-tabs">
                    <span class="cw-tab">{!! $icon($fileIcon) !!} {{ $name }}
                        @if ($file && ! $file['withheld']) <small>{{ $file['lines'] }} lines</small> @endif
                    </span>
                </div>
                <div class="cw-crumbs">
                    @foreach ($crumbs as $i => $crumb)
                        @if ($i === count($crumbs) - 1) <b>{{ $crumb }}</b> @else {{ $crumb }} &rsaquo; @endif
                    @endforeach
                </div>
            @endif
            @if (! $tree)
                <p class="cw-note warn">The code of this demo cannot be read just now. Please try again in a minute.</p>
            @elseif (! $file)
                <p class="cw-note">Choose a file.</p>
            @elseif ($file['withheld'])
                <p class="cw-note warn">{{ $file['withheld'] }}</p>
            @else
                @if ($file['redacted'])
                    <p class="cw-banner">A password or key written in this file is shown as &bull;&bull;&bull;&bull;&bull;&bull;.</p>
                @endif
                <div class="cw-code" tabindex="0" aria-label="{{ $name }}, read-only">
                    <div class="cw-gutter" aria-hidden="true">@for ($i = 1; $i <= $file['lines']; $i++){{ $i }}
@endfor</div>
                    <pre class="cw-src"><code class="demo-code" data-language="{{ $file['language'] }}">{{ $file['content'] }}</code></pre>
                </div>
            @endif
        </section>
    </div>
    <div class="cw-status">
        <span>{{ $domain }}</span>
        <span>{{ $count }} files</span>
        <span class="cw-grow"></span>
        @if ($file && ! $file['withheld'])
            <span>{{ \App\Showcase\DemoSource::languageLabel($file['language']) }}</span>
        @endif
        <span>Read-only</span>
    </div>
</div>
