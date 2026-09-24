<?php

namespace App\Http\Controllers;

use App\Showcase\DemoSource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The source of the public demos, open to read and never to edit - like an
 * open-source repository, served from the live site itself. What may be
 * shown, and how, is App\Showcase\DemoSource.
 */
class DemoCodeController extends Controller
{
    public function __construct(private readonly DemoSource $source) {}

    public function index(Request $request, string $demo): View|Response
    {
        [$config, $site] = $this->source->demo($demo) ?? abort(404);
        try {
            $paths = $this->source->paths($demo, $config, $site);
        } catch (\Throwable $e) {
            // The demo's host is busy or restarting: say so, never a 500.
            report($e);

            return response()->view('demos.code', ['key' => $demo, 'demo' => $config, 'domain' => $site->domain,
                'tree' => null, 'path' => '', 'file' => null, 'count' => 0], 503);
        }

        $path = (string) $request->query('path', '');
        if ($path === '') {
            $path = $this->source->defaultPath($paths, (array) ($config['feature'] ?? []));
        }
        $file = null;
        if ($path !== '') {
            abort_unless(in_array($path, $paths, true), 404);
            $file = $this->source->file($demo, $site, $path);
        }

        return view('demos.code', [
            'key' => $demo,
            'demo' => $config,
            'domain' => $site->domain,
            'tree' => $this->source->tree($paths),
            'path' => $path,
            'file' => $file,
            'count' => count($paths),
        ]);
    }
}
