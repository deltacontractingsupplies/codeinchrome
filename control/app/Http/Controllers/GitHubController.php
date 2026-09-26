<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A site linked to its owner's GitHub repository: every version of its code
 * is pushed there (agent github.go - a deploy key made for the site alone,
 * fast-forward only, never .env). Shown on the site's settings page, and
 * answered as JSON to the editor (cic.github), so an agent can link it for
 * the person.
 */
class GitHubController extends Controller
{
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        try {
            $link = AgentClient::for($site->host)->github($site->site_id);
        } catch (AgentRefused|AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The site\'s host is not responding.'], 503);
        }

        return response()->json(['ok' => true, 'linked' => $link !== null, 'github' => $this->withAddKeyUrl($link)]);
    }

    public function link(Request $request, Site $site): RedirectResponse|JsonResponse
    {
        $this->authorizeSite($request, $site);
        $d = $request->validate([
            // As in the repository's address: github.com/owner/name.
            'repo' => ['required', 'string', 'max:140', 'regex:~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$~'],
            'branch' => ['nullable', 'string', 'max:100', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~'],
        ]);
        $repo = preg_replace('~\.git$~', '', $d['repo']);

        return $this->attempt($request, $site, function () use ($site, $repo, $d) {
            $link = AgentClient::for($site->host)->linkGitHub($site->site_id, $repo, $d['branch'] ?? 'main');
            Audit::record('site.github_linked', site: $site, detail: ['repo' => $repo, 'branch' => $link['branch']]);

            return [$link['state'] === 'linked'
                ? "Linked: every version is pushed to $repo."
                : 'Almost there: add the key below to the repository on GitHub, then check again.', $link];
        });
    }

    public function push(Request $request, Site $site): RedirectResponse|JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt($request, $site, function () use ($site) {
            $link = AgentClient::for($site->host)->pushGitHub($site->site_id);

            return [match ($link['state']) {
                'linked' => 'Pushed to GitHub.',
                'diverged' => 'Pushed to the branch codeinchrome/sync: GitHub has commits the site does not.',
                default => 'Not pushed yet: '.($link['hint'] ?? 'see below.'),
            }, $link];
        });
    }

    public function unlink(Request $request, Site $site): RedirectResponse|JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt($request, $site, function () use ($site) {
            AgentClient::for($site->host)->unlinkGitHub($site->site_id);
            Audit::record('site.github_unlinked', site: $site);

            return ['Unlinked. The site\'s key is destroyed; delete it from the repository\'s deploy keys on GitHub too.', null];
        });
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        // 404, not 403: a stranger learns nothing about whether the site exists.
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }

    /** Where the owner adds the site's key on GitHub, for the page and for an agent. */
    private function withAddKeyUrl(?array $link): ?array
    {
        return $link ? $link + ['addKeyUrl' => 'https://github.com/'.$link['repo'].'/settings/keys/new'] : null;
    }

    /** @param  callable(): array{0: string, 1: ?array}  $work  the message for a person, and the link */
    private function attempt(Request $request, Site $site, callable $work): RedirectResponse|JsonResponse
    {
        $back = route('sites.settings', $site).'#github';
        try {
            [$message, $link] = $work();

            return $request->expectsJson()
                ? response()->json(['ok' => true, 'message' => $message, 'linked' => $link !== null, 'github' => $this->withAddKeyUrl($link)])
                : redirect()->to($back)->with('status', $message);
        } catch (AgentRefused $e) {
            $hint = $e->detail['hint'] ?? $e->getMessage();

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => $e->detail['error'] ?? 'refused', 'hint' => $hint], 422)
                : redirect()->to($back)->with('error', $hint)->withInput();
        } catch (AgentUnreachable) {
            $hint = 'The site\'s host is not responding. Try again in a minute.';

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => $hint], 503)
                : redirect()->to($back)->with('error', $hint);
        }
    }
}
