<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The editor's database browser, proxied to the site's host.
 *
 * Like FileController, this layer's only job is WHOSE site it is. What a query
 * may do is decided by MySQL: it runs as the site's own user, so the browser
 * can do nothing the site's own code could not already do.
 */
class DatabaseController extends Controller
{
    public function tables(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => AgentClient::for($site->host)->dbTables($site->site_id));
    }

    public function query(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'sql' => ['required', 'string', 'max:102400'],
            'write' => ['sometimes', 'boolean'],
        ]);

        return $this->attempt(function () use ($site, $data) {
            $result = AgentClient::for($site->host)->dbQuery($site->site_id, $data['sql'], (bool) ($data['write'] ?? false));
            // Writes only: reads are not an event anyone needs to reconstruct.
            if (($result['mode'] ?? '') === 'write') {
                Audit::record('db.write', site: $site, detail: [
                    'sql' => mb_substr($data['sql'], 0, 500), 'rows' => $result['rowsAffected'] ?? null,
                ]);
            }

            return ['result' => $result];
        });
    }

    /**
     * The whole database as .sql.gz. Always an attachment. ?saved=before-import
     * gives the copy the last import saved, which is how an import is undone.
     */
    public function export(Request $request, Site $site): Response
    {
        $this->authorizeSite($request, $site);
        $beforeImport = $request->query('saved') === 'before-import';
        $this->longRequest();

        try {
            $dump = AgentClient::for($site->host)->dbExport($site->site_id, $beforeImport);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => $e->detail['error'] ?? 'refused', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
        } catch (AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The host is not responding.'], 503);
        }
        Audit::record('db.exported', site: $site, detail: ['saved' => $beforeImport ? 'before-import' : null]);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $dump['name']) ?: 'database.sql.gz';

        return new StreamedResponse(function () use ($dump) {
            while (! $dump['body']->eof()) {
                echo $dump['body']->read(65536);
                flush();
            }
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => "attachment; filename=\"$name\"",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Loads a .sql or .sql.gz file. Replaces data, so the request must say
     * confirm=1, and the agent saves the current database before it starts.
     */
    public function import(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            // KB. Cloudflare refuses request bodies over 100 MB, so this is the
            // real ceiling for the panel (the agent itself takes up to 512 MB).
            'file' => ['required', 'file', 'max:'.(95 * 1024)],
            'confirm' => ['accepted'],
        ]);
        $this->longRequest();
        // Past this point the import must finish, whether or not the browser
        // (or Cloudflare's 100-second limit) is still waiting for the answer.
        ignore_user_abort(true);

        return $this->attempt(function () use ($site, $d) {
            $r = AgentClient::for($site->host)->dbImport($site->site_id, fopen($d['file']->getRealPath(), 'rb'));
            Audit::record('db.imported', site: $site, detail: [
                'file' => mb_substr($d['file']->getClientOriginalName(), 0, 200), 'bytes' => $d['file']->getSize(),
            ]);

            return ['imported' => true, 'undo' => route('db.export', $site).'?saved=before-import'];
        });
    }

    /**
     * A dump can take minutes. Only for a web request: set_time_limit applies
     * to the whole PROCESS, and in a console process (a test run, a worker)
     * it would start a clock on everything that runs after it.
     */
    private function longRequest(): void
    {
        if (! app()->runningInConsole()) {
            set_time_limit(0);
        }
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }

    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $work());
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';

            return response()->json([
                'ok' => false,
                'error' => $error,
                'hint' => $e->detail['hint'] ?? $e->getMessage(),
            ], $error === 'needs_write' ? 409 : 422);
        } catch (AgentUnreachable) {
            return response()->json([
                'ok' => false,
                'error' => 'host_unreachable',
                'hint' => 'The host is not responding. Its state is unknown.',
            ], 503);
        }
    }
}
