<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The editor's file manager beyond open/save/delete: folders, move, copy,
 * upload, download, folder delete, search, zip and unzip. As with every file
 * route, this layer decides WHOSE site it is; the agent, on the host, decides
 * what a path may be - including never following a symlink out of the site.
 */
class FileManagerController extends FileController
{
    private const PATH = ['required', 'string', 'max:1024'];

    public function mkdir(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['path' => self::PATH]);

        return $this->attempt(fn () => AgentClient::for($site->host)->mkdir($site->site_id, $d['path']));
    }

    public function move(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['from' => self::PATH, 'to' => self::PATH]);

        return $this->attempt(function () use ($site, $d) {
            $r = AgentClient::for($site->host)->move($site->site_id, $d['from'], $d['to']);
            Audit::record('file.moved', site: $site, detail: $d);

            return $r;
        });
    }

    public function copy(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['from' => self::PATH, 'to' => self::PATH]);

        return $this->attempt(fn () => AgentClient::for($site->host)->copy($site->site_id, $d['from'], $d['to']));
    }

    public function zip(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['from' => self::PATH, 'to' => self::PATH]);

        return $this->attempt(fn () => AgentClient::for($site->host)->zip($site->site_id, $d['from'], $d['to']));
    }

    public function unzip(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['archive' => self::PATH, 'into' => self::PATH]);

        return $this->attempt(fn () => AgentClient::for($site->host)->unzip($site->site_id, $d['archive'], $d['into']));
    }

    /** A folder and everything in it. Refused unless confirm - as the agent also insists. */
    public function destroyTree(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['path' => self::PATH, 'confirm' => ['nullable', 'boolean'], 'empty' => ['nullable', 'boolean']]);

        return $this->attempt(function () use ($site, $d) {
            $client = AgentClient::for($site->host);
            $r = ($d['empty'] ?? false)
                ? $client->deleteEmptyDir($site->site_id, $d['path'])
                : $client->deleteTree($site->site_id, $d['path'], (bool) ($d['confirm'] ?? false));
            Audit::record('folder.deleted', site: $site, detail: ['path' => $d['path']]);

            return $r;
        });
    }

    public function search(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        return $this->attempt(fn () => ['hits' => AgentClient::for($site->host)->search($site->site_id, $d['q'])]);
    }

    /** grep -r for cic.sh: a literal or RE2 pattern, in a folder, by file name. */
    public function grep(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            'pattern' => ['required', 'string', 'max:200'],
            'regex' => ['sometimes', 'boolean'], 'icase' => ['sometimes', 'boolean'], 'word' => ['sometimes', 'boolean'],
            'under' => ['sometimes', 'string', 'max:1024'],
            'include' => ['sometimes', 'array', 'max:10'], 'include.*' => ['string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->attempt(fn () => AgentClient::for($site->host)->grep($site->site_id, [
            'pattern' => $d['pattern'], 'regex' => $request->boolean('regex'), 'icase' => $request->boolean('icase'),
            'word' => $request->boolean('word'), 'under' => $d['under'] ?? null, 'include' => $d['include'] ?? [],
            'limit' => $d['limit'] ?? null,
        ]));
    }

    /** find for cic.sh (and ls -R, tree, du): what is where, how big, how new. */
    public function find(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            'under' => ['sometimes', 'string', 'max:1024'],
            'name' => ['sometimes', 'string', 'max:100'], 'icase' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'in:f,d'],
            'newer' => ['sometimes', 'integer', 'min:0'],
            'maxdepth' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'all' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
        ]);

        return $this->attempt(fn () => AgentClient::for($site->host)->find($site->site_id, [
            'under' => $d['under'] ?? null, 'name' => $d['name'] ?? null, 'icase' => $request->boolean('icase'),
            'type' => $d['type'] ?? null, 'newer' => $d['newer'] ?? null, 'maxdepth' => $d['maxdepth'] ?? null,
            'all' => $request->boolean('all'), 'limit' => $d['limit'] ?? null,
        ]));
    }

    /** git clone of a public GitHub repository into a new folder (cic.sh). */
    public function cloneRepository(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            'repository' => ['required', 'string', 'max:200', 'regex:#^(https://github\.com/)?[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}(\.git)?/?$#'],
            'ref' => ['nullable', 'string', 'max:100', 'regex:#^[A-Za-z0-9._/-]+$#', 'not_regex:#\.\.#'],
            'into' => ['nullable', 'string', 'max:1024'],
            'replace' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('replace')) {
            // The whole site becomes the repository (after a backup; .env and
            // storage/ are kept). Refused by the host without confirm.
            return $this->attempt(function () use ($site, $d, $request) {
                $r = AgentClient::for($site->host)->cloneReplace($site->site_id, $d['repository'], $d['ref'] ?? null, $request->boolean('confirm'));
                \App\Audit\Audit::record('site.replaced_with_clone', site: $site, detail: ['repository' => $d['repository'], 'ref' => $d['ref'] ?? 'HEAD']);

                return $r;
            });
        }

        return $this->attempt(fn () => AgentClient::for($site->host)->cloneRepository($site->site_id, $d['repository'], $d['ref'] ?? null, $d['into'] ?? null));
    }

    /** The current or last backup, restore or clone-replace, for the editor to poll. */
    public function operation(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => AgentClient::for($site->host)->operation($site->site_id));
    }

    /** Every file path, for Quick Open (⌘P). */
    public function paths(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => AgentClient::for($site->host)->paths($site->site_id));
    }

    public function upload(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            'path' => self::PATH,
            'file' => ['required', 'file', 'max:32768'], // KB: the site's 32 MB limit
        ]);

        // The stream owns the file handle and closes it when it is released.
        return $this->attempt(fn () => AgentClient::for($site->host)
            ->upload($site->site_id, $d['path'], fopen($d['file']->getRealPath(), 'rb')));
    }

    /**
     * Always an attachment, never rendered: an uploaded HTML or SVG file must
     * not run as a page on the dashboard's origin. nosniff and a sandbox CSP
     * back that up if a browser were ever to try.
     */
    public function download(Request $request, Site $site): Response
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate(['path' => self::PATH]);

        try {
            $file = AgentClient::for($site->host)->download($site->site_id, $d['path']);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => $e->detail['error'] ?? 'refused', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) ?: 'download';

        return new StreamedResponse(function () use ($file) {
            while (! $file['body']->eof()) {
                echo $file['body']->read(65536);
                flush();
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"$name\"",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'no-store',
        ]);
    }
}
