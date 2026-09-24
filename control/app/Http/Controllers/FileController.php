<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel's file operations, proxied to the site's host.
 *
 * This controller's entire job is authorisation. Containment - that a path
 * cannot escape the site - is the agent's job and is enforced there, on the
 * resolved path. Doing it here as well would be a second implementation of
 * the same rule that could drift from the first; the agent must never depend
 * on this layer having checked anything.
 *
 * What this layer owns, and the agent cannot know, is WHOSE site it is.
 */
class FileController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $path = (string) $request->query('path', '/');

        return $this->attempt(function () use ($site, $path, $request) {
            $agent = AgentClient::for($site->host);

            if ($request->boolean('read')) {
                return ['path' => $path] + $agent->readFileWithRevision($site->site_id, $path);
            }

            return ['listing' => $agent->listFiles($site->site_id, $path)];
        });
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            // `nullable`, not `string`. Laravel's ConvertEmptyStringsToNull
            // middleware turns "" into null before validation ever runs, so a
            // `string` rule rejects an empty body - and emptying a file is a
            // perfectly ordinary edit. Coerced back below.
            'content' => ['present', 'nullable', 'string', 'max:2097152'],
            'expect' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt(fn () => AgentClient::for($site->host)
            ->writeFile($site->site_id, $data['path'], $data['content'] ?? '', $data['expect'] ?? ''));
    }

    /** Many files in one call: all checked first, all written, one version. */
    public function storeMany(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:200'],
            'files.*.path' => ['required', 'string', 'max:1024'],
            'files.*.content' => ['present', 'nullable', 'string', 'max:2097152'],
            'files.*.expect' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:200'],
        ]);
        $files = array_map(fn ($f) => [
            'path' => $f['path'], 'content' => $f['content'] ?? '', 'expect' => $f['expect'] ?? '',
        ], $data['files']);

        return $this->attempt(fn () => ['written' => AgentClient::for($site->host)
            ->writeMany($site->site_id, $files, (string) ($data['message'] ?? ''))['written'] ?? []]);
    }

    /** Find-and-replace edits to one file, without sending the whole file. */
    public function edit(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'edits' => ['required', 'array', 'min:1', 'max:100'],
            'edits.*.find' => ['required', 'string', 'max:2097152'],
            'edits.*.replace' => ['present', 'nullable', 'string', 'max:2097152'],
            'edits.*.all' => ['sometimes', 'boolean'],
            'expect' => ['nullable', 'string', 'max:64'],
        ]);
        $edits = array_map(fn ($e) => [
            'find' => $e['find'], 'replace' => $e['replace'] ?? '', 'all' => (bool) ($e['all'] ?? false),
        ], $data['edits']);

        return $this->attempt(fn () => AgentClient::for($site->host)
            ->editFile($site->site_id, $data['path'], $edits, (string) ($data['expect'] ?? '')));
    }

    /**
     * A request to the site itself, the way a visitor would make it, from its
     * own host. Only ever this site: the agent fixes the destination.
     */
    public function request(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'method' => ['nullable', 'string', 'in:GET,HEAD,POST,PUT,PATCH,DELETE,OPTIONS,get,head,post,put,patch,delete,options'],
            'path' => ['required', 'string', 'max:4096', 'starts_with:/'],
            'headers' => ['nullable', 'array', 'max:20'],
            'headers.*' => ['string', 'max:8192'],
            'body' => ['nullable', 'string', 'max:2097152'],
        ]);

        return $this->attempt(fn () => ['response' => AgentClient::for($site->host)->siteRequest($site->site_id, [
            'method' => strtoupper($data['method'] ?? 'GET'),
            'path' => $data['path'],
            'headers' => (object) ($data['headers'] ?? []),
            'body' => $data['body'] ?? '',
        ])]);
    }

    /**
     * PHP run in the site's own application (tinker without the shell). The
     * owner's own code already runs there; this is that, on demand. The code
     * itself is not written to the audit log - it may hold data - only that
     * it ran, and its size.
     */
    public function eval(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate(['code' => ['required', 'string', 'max:262144']]);
        Audit::record('site.eval', site: $site, detail: ['bytes' => strlen($data['code'])]);

        return $this->attempt(fn () => ['result' => AgentClient::for($site->host)->evalPhp($site->site_id, $data['code'])]);
    }

    /** Sign in as one of the SITE's users, for testing pages behind its login. */
    public function loginCookie(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate(['user' => ['required', 'integer', 'min:1'], 'guard' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,30}$/']]);
        Audit::record('site.test_login', site: $site, detail: ['user' => (int) $data['user']]);

        return $this->attempt(fn () => AgentClient::for($site->host)->loginCookie($site->site_id, (int) $data['user'], $data['guard'] ?? 'web'));
    }

    /**
     * Ask the live site, from outside, for every path a leak would take, and
     * say which gave nothing away (App\Fleet\ExposureCheck). The site's own
     * secrets are compared on the server and never included in the answer.
     */
    public function exposure(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => (new \App\Fleet\ExposureCheck($site))->run());
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['ok' => false, 'error' => 'no_path', 'hint' => 'pass ?path='], 422);
        }

        return $this->attempt(function () use ($site, $path) {
            $deleted = AgentClient::for($site->host)->deleteFile($site->site_id, $path);
            // Deletes are recorded; saves are not (every keystroke-save would
            // bury everything else).
            Audit::record('file.deleted', site: $site, detail: ['path' => $path]);

            return ['deleted' => $deleted];
        });
    }

    /**
     * 404, not 403. A 403 confirms the site exists and belongs to someone
     * else, which lets anyone enumerate which names are taken and by whom.
     */
    protected function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        // A paused site's owner may still take their work away; PausedSite
        // lets only the reading routes through to here.
        abort_unless(in_array($site->status, ['live', 'suspended'], true), 409, 'This site is not live yet.');
    }

    /**
     * Keeps the agent's two failure kinds apart all the way to the browser.
     * "The host could not be reached" and "the host refused this" need
     * different responses from the panel: one is worth retrying, the other
     * never is until the request changes.
     */
    protected function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $work());
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';

            return response()->json([
                'ok' => false,
                'error' => $error,
                'hint' => $e->detail['hint'] ?? $e->getMessage(),
                // Which file of a batch it was about.
                ...(isset($e->detail['path']) ? ['path' => $e->detail['path']] : []),
                // 409 for a stale save or an unconfirmed destructive request,
                // so a client can tell "decide first" from "this request is
                // invalid" by status alone.
            ], in_array($error, ['conflict', 'needs_confirm', 'busy'], true) ? 409 : 422);
        } catch (AgentUnreachable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'host_unreachable',
                'hint' => 'The host is not responding. Nothing was changed, but its state is unknown.',
            ], 503);
        }
    }
}
