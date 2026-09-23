<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A site's backups, for its owner: the nightly ones, one now, and a restore.
 *
 * The agent does the work and the rules - a restore backs the site up first,
 * only this site's own snapshots can be named - and this layer decides whose
 * site it is and that a restore was confirmed on purpose.
 */
class BackupController extends Controller
{
    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
    }

    public function index(Request $request, Site $site): View
    {
        $this->authorizeSite($request, $site);
        $backups = [];
        $operation = null;
        $error = null;
        if ($site->status === 'live') {
            try {
                ['backups' => $backups, 'operation' => $operation] = AgentClient::for($site->host)->backups($site->site_id);
            } catch (AgentRefused $e) {
                $error = $e->detail['hint'] ?? 'The backup list is not available right now.';
            } catch (AgentUnreachable) {
                $error = 'The site\'s server is not responding, so its backups cannot be listed right now.';
            }
        }

        return view('sites.backups', compact('site', 'backups', 'operation', 'error'));
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');

        return $this->attempt(function () use ($site) {
            AgentClient::for($site->host)->backupNow($site->site_id);
            Audit::record('site.backup', site: $site);

            return 'Backing up now. This page updates by itself until it finishes.';
        });
    }

    public function restore(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
        $d = $request->validate([
            'snapshot' => ['required', 'string', 'regex:/^[0-9a-f]{8}$/'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the restore.']);

        return $this->attempt(function () use ($site, $d) {
            AgentClient::for($site->host)->restoreBackup($site->site_id, $d['snapshot']);
            Audit::record('site.restore', site: $site, detail: ['snapshot' => $d['snapshot']]);

            return "Restoring backup {$d['snapshot']}. The site is backed up as it is first, then goes offline for a few minutes while its files and database are replaced.";
        });
    }

    private function attempt(callable $work): RedirectResponse
    {
        try {
            return back()->with('status', $work());
        } catch (AgentRefused $e) {
            return back()->with('error', $e->detail['hint'] ?? 'The server refused.');
        } catch (AgentUnreachable) {
            return back()->with('error', 'The site\'s server is not responding. Nothing was started.');
        }
    }
}
