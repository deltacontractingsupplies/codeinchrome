{{-- One level of a code window's explorer; folders on the open file's path start open.
     Depth is a class (cw-d0..15): public pages allow no inline style. --}}
@foreach ($node['dirs'] as $name => $child)
    @php($dirPath = $prefix.'/'.$name)
    <details @if (str_starts_with($path, $dirPath.'/')) open @endif>
        <summary class="cw-row cw-d{{ min($depth, 15) }}">
            <span class="cw-chev" aria-hidden="true">&rsaquo;</span>
            {!! $icon($child['icon']['closed'] ?? null, 'cw-ic-closed') !!}{!! $icon($child['icon']['open'] ?? null, 'cw-ic-open') !!}
            <span>{{ $name }}</span>
        </summary>
        @include('components.code-window-tree', ['node' => $child, 'prefix' => $dirPath, 'depth' => $depth + 1])
    </details>
@endforeach
@foreach ($node['files'] as $file => $fileIcon)
    <a href="{{ route('demos.code', ['demo' => $demoKey, 'path' => $file]) }}" class="cw-row cw-file cw-d{{ min($depth, 15) }}"
       @if ($file === $path) aria-current="page" @endif>{!! $icon($fileIcon) !!}<span>{{ basename($file) }}</span></a>
@endforeach
