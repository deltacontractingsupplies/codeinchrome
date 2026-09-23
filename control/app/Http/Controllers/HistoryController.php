<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A site's version history and its bin, proxied to the host. Like the file
 * routes, this layer only decides WHOSE site it is; what a path or a version
 * may be is checked by the agent, on the host, every time.
 */
class HistoryController extends FileController
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:1024'],
            'rev' => ['nullable', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->attempt(function () use ($site, $data) {
            $agent = AgentClient::for($site->host);
            if (! empty($data['rev'])) {
                return ['path' => $data['path'] ?? '', 'rev' => $data['rev'],
                    'content' => $agent->fileAt($site->site_id, $data['rev'], (string) ($data['path'] ?? ''))];
            }

            return ['versions' => $agent->history($site->site_id, (string) ($data['path'] ?? ''), (int) ($data['limit'] ?? 100))];
        });
    }

    public function bin(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => ['bin' => AgentClient::for($site->host)->bin($site->site_id)]);
    }

    public function restore(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'rev' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'path' => ['required', 'string', 'max:1024'],
        ]);

        return $this->attempt(function () use ($site, $data) {
            $r = AgentClient::for($site->host)->restore($site->site_id, $data['rev'], $data['path']);
            Audit::record('file.restored', site: $site, detail: ['path' => $data['path'], 'from' => substr($data['rev'], 0, 12)]);

            return $r;
        });
    }
}
