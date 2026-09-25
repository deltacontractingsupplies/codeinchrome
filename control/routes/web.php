<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\LspController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\VerifiedWhenMailEnabled;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
// Every free, live, built site, by its address only (App\Showcase\Explore).
Route::get('/explore', fn (\App\Showcase\Explore $explore) => view('explore', ['sites' => $explore->listed()]))->name('explore');
// Anyone can report a hosted site (AbuseReportController).
Route::get('/report', [\App\Http\Controllers\AbuseReportController::class, 'show'])->name('report');
Route::post('/report', [\App\Http\Controllers\AbuseReportController::class, 'store'])->middleware('throttle:abuse-report')->name('report.store');
Route::view('/pricing', 'pricing')->name('pricing');
// RFC 9116: how to report a vulnerability. The contact is the support
// address; Expires is kept a year ahead, as the RFC asks it never lapse.
Route::get('/.well-known/security.txt', fn () => response(implode("\n", [
    'Contact: mailto:'.config('legal.support_email'),
    'Expires: '.now()->addYear()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
    'Preferred-Languages: en',
    'Canonical: '.url('/.well-known/security.txt'),
    // SECURITY.md: how to report, what is in scope, safe harbour.
    'Policy: '.config('legal.source.url').'/security/policy',
])."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']))->name('security.txt');
Route::view('/terms', 'legal.terms')->name('terms');
// The agent skill, as plain text: for an agent that never loaded it (the
// editor's cic.skill() pages the same file). Public, like the repository.
Route::get('/agent/skill.md', function () {
    $path = config('agent.skill_path');
    try {
        $text = is_string($path) && is_file($path) ? file_get_contents($path) : false;
    } catch (\ErrorException $e) {
        // Outside open_basedir, say: logged for us, a 404 for the reader - never a 500.
        report($e);
        $text = false;
    }
    abort_if($text === false, 404);

    return response($text, 200, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'public, max-age=300',
    ]);
})->name('agent.skill');
// The demos' source, open to read (DemoCodeController: config-listed sites only).
Route::get('/demos/{demo}/code', [\App\Http\Controllers\DemoCodeController::class, 'index'])
    ->where('demo', '[a-z0-9-]+')->middleware('throttle:demo-code')->name('demos.code');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/refunds', 'legal.refunds')->name('refunds');

// One-time links from `php artisan user:login-link` (App\Auth\LoginLink).
// Outside the guest group on purpose: a browser already signed in as someone
// else switches to the link's account, and says so. (Found by an agent that
// opened a link in a browser holding another session: it was bounced to the
// OTHER account's dashboard with no word, and could not tell.) Links are
// minted only on the server's command line, so no one can plant one.
Route::get('/login/link/{token}', function (\Illuminate\Http\Request $request, string $token) {
    $user = \App\Auth\LoginLink::consume($token);
    abort_unless($user, 404);
    if (\Illuminate\Support\Facades\Auth::check()) {
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
    \Illuminate\Support\Facades\Auth::login($user);
    $request->session()->regenerate();
    \App\Audit\Audit::record('auth.login_link');

    return redirect()->route('dashboard')->with('status', "Signed in as {$user->email}.");
})->middleware('throttle:login')->name('login.link');

Route::middleware('guest')->group(function () {
    // Sign in with Google or Apple. Apple's callback is a POST from its own
    // site (see SocialLoginController); both methods are accepted there.
    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->middleware('throttle:login')->name('social.redirect');
    Route::match(['get', 'post'], '/auth/{provider}/callback', [SocialLoginController::class, 'callback'])->middleware('throttle:login')->name('social.callback');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    // Rate limited because these are the two endpoints an attacker gets to
    // call as often as we let them.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');

    // Password reset. Every action 404s unless mail really leaves the
    // building (fleet.mail_enabled); throttled because each one sends mail.
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:password-mail')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-mail')->name('password.update');
    // Brute-force limiting is per account, inside the controller; this is
    // only a coarse ceiling against floods.
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->middleware('throttle:two-factor');
});

// auth.session: sessions carry a hash of the password, so changing it signs
// out every other session (AccountController::password).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verify-code', [VerificationController::class, 'code'])->middleware('throttle:email-code')->name('verification.code');
    Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [VerificationController::class, 'send'])->middleware('throttle:password-mail')->name('verification.send');
    Route::get('/status', StatusController::class)->name('status');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::get('/account/activity', [AccountController::class, 'activity'])->name('account.activity');
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:account')->name('account.password');
    Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:account')->name('account.destroy');
    Route::get('/account/two-factor', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/account/two-factor', [TwoFactorController::class, 'confirm'])->middleware('throttle:account')->name('two-factor.confirm');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['throttle:billing', VerifiedWhenMailEnabled::class])->name('billing.checkout');
    Route::get('/billing/return', [BillingController::class, 'return'])->name('billing.return');
    Route::get('/sites', [SiteController::class, 'index'])->name('dashboard');
    // Provisioning creates a container and a DNS record, so it is throttled
    // separately and much harder than a page view.
    Route::post('/sites', [SiteController::class, 'store'])->middleware(['throttle:provision', VerifiedWhenMailEnabled::class])->name('sites.store');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
    Route::post('/sites/{site}/wake', [SiteController::class, 'wake'])->middleware('throttle:provision')->name('sites.wake');

    // The panel's file operations. Session-authenticated like the rest of the
    // dashboard, so the browser needs no second credential and there is no
    // long-lived token to leak.
    Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');

    Route::get('/sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings');
    Route::get('/sites/{site}/backups', [BackupController::class, 'index'])->name('sites.backups');
    Route::post('/sites/{site}/backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('sites.backups.store');
    Route::post('/sites/{site}/backups/restore', [BackupController::class, 'restore'])->middleware('throttle:6,1')->name('sites.backups.restore');
    Route::put('/sites/{site}/background', [SiteSettingsController::class, 'background'])->middleware('throttle:provision')->name('sites.background');
    Route::put('/sites/{site}/php', [SiteSettingsController::class, 'php'])->middleware('throttle:provision')->name('sites.php');
    Route::get('/sites/{site}/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('/sites/{site}/domains', [DomainController::class, 'store'])->middleware(['throttle:domains', VerifiedWhenMailEnabled::class])->name('domains.store');
    // Verification queries public DNS; throttled so it cannot be used to make
    // us hammer resolvers.
    Route::post('/sites/{site}/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:domains')->name('domains.verify');
    Route::delete('/sites/{site}/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    // Bounded per user: one cic.sh line (xargs, a glob, grep -C) can fan out
    // into many requests, each forwarded to a host other sites share.
    Route::get('/sites/{site}/files', [FileController::class, 'index'])->middleware('throttle:files')->name('files.index');
    Route::put('/sites/{site}/files', [FileController::class, 'store'])->middleware(['throttle:file-writes', \App\Http\Middleware\StorageLimit::class])->name('files.store');
    Route::put('/sites/{site}/files/batch', [FileController::class, 'storeMany'])->middleware(['throttle:file-writes', \App\Http\Middleware\StorageLimit::class])->name('files.batch');
    Route::post('/sites/{site}/files/edit', [FileController::class, 'edit'])->middleware('throttle:file-writes')->name('files.edit');
    Route::get('/sites/{site}/exposure', [FileController::class, 'exposure'])->middleware('throttle:exposure')->name('sites.exposure');
    Route::post('/sites/{site}/eval', [FileController::class, 'eval'])->middleware('throttle:eval')->name('sites.eval');
    Route::post('/sites/{site}/login-cookie', [FileController::class, 'loginCookie'])->middleware('throttle:eval')->name('sites.login-cookie');
    Route::post('/sites/{site}/request', [FileController::class, 'request'])->middleware('throttle:site-request')->name('sites.request');
    // A page as one of the site's users sees it, in a real tab (LookController).
    Route::post('/sites/{site}/look', [\App\Http\Controllers\LookController::class, 'create'])->middleware('throttle:site-request')->name('sites.look');
    Route::get('/sites/{site}/look/{token}', [\App\Http\Controllers\LookController::class, 'show'])->whereUuid('token')->middleware('throttle:site-request')->name('sites.look.show');
    Route::delete('/sites/{site}/files', [FileController::class, 'destroy'])->middleware('throttle:file-writes')->name('files.destroy');
    // The rest of the file manager.
    Route::post('/sites/{site}/files/mkdir', [FileManagerController::class, 'mkdir'])->middleware('throttle:file-writes')->name('files.mkdir');
    Route::post('/sites/{site}/files/move', [FileManagerController::class, 'move'])->middleware('throttle:file-writes')->name('files.move');
    // A folder copy can be 200 MB: bounded like a command.
    Route::post('/sites/{site}/files/copy', [FileManagerController::class, 'copy'])->middleware(['throttle:command', \App\Http\Middleware\StorageLimit::class])->name('files.copy');
    Route::post('/sites/{site}/files/zip', [FileManagerController::class, 'zip'])->middleware('throttle:command')->name('files.zip');
    Route::post('/sites/{site}/files/unzip', [FileManagerController::class, 'unzip'])->middleware(['throttle:command', \App\Http\Middleware\StorageLimit::class])->name('files.unzip');
    Route::delete('/sites/{site}/tree', [FileManagerController::class, 'destroyTree'])->middleware('throttle:file-writes')->name('files.tree.destroy');
    Route::get('/sites/{site}/paths', [FileManagerController::class, 'paths'])->middleware('throttle:command')->name('files.paths');
    Route::get('/sites/{site}/search', [FileManagerController::class, 'search'])->middleware('throttle:search')->name('files.search');
    // grep -r and find for cic.sh: read-only walks, capped at 20 s on the host.
    Route::get('/sites/{site}/grep', [FileManagerController::class, 'grep'])->middleware('throttle:search')->name('files.grep');
    Route::get('/sites/{site}/find', [FileManagerController::class, 'find'])->middleware('throttle:search')->name('files.find');
    Route::get('/sites/{site}/operation', [FileManagerController::class, 'operation'])->middleware('throttle:files')->name('files.operation');
    Route::post('/sites/{site}/files/clone', [FileManagerController::class, 'cloneRepository'])->middleware(['throttle:clone', \App\Http\Middleware\StorageLimit::class])->name('files.clone');
    Route::post('/sites/{site}/upload', [FileManagerController::class, 'upload'])->middleware(['throttle:command', \App\Http\Middleware\StorageLimit::class])->name('files.upload');
    Route::get('/sites/{site}/download', [FileManagerController::class, 'download'])->middleware('throttle:files')->name('files.download');
    // Every version of every file, the bin of deleted ones, and restore.
    Route::get('/sites/{site}/history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('/sites/{site}/bin', [HistoryController::class, 'bin'])->name('history.bin');
    Route::post('/sites/{site}/history/restore', [HistoryController::class, 'restore'])->middleware('throttle:command')->name('history.restore');

    // Commands can run for minutes and cost CPU on a shared host.
    Route::post('/sites/{site}/command', [ConsoleController::class, 'run'])->middleware('throttle:command')->name('console.run');
    Route::get('/sites/{site}/logs', [ConsoleController::class, 'logs'])->middleware('throttle:logs')->name('console.logs');

    // The database browser. Queries are throttled harder than file reads:
    // each one opens a MySQL connection on a host shared with other tenants.
    Route::get('/sites/{site}/db', [DatabaseController::class, 'tables'])->name('db.tables');
    Route::post('/sites/{site}/db/query', [DatabaseController::class, 'query'])->middleware('throttle:db')->name('db.query');
    Route::post('/sites/{site}/lsp', [LspController::class, 'exchange'])->middleware('throttle:lsp')->name('lsp.exchange');
    Route::post('/sites/{site}/mcp', [McpController::class, 'call'])->middleware('throttle:mcp')->name('mcp.call');
    Route::delete('/sites/{site}/lsp/{session}', [LspController::class, 'close'])->name('lsp.close');
    Route::get('/sites/{site}/db/export', [DatabaseController::class, 'export'])->middleware('throttle:db')->name('db.export');
    Route::post('/sites/{site}/db/import', [DatabaseController::class, 'import'])->middleware(['throttle:db', \App\Http\Middleware\StorageLimit::class])->name('db.import');
});

/*
 * Lemon Squeezy posts here. The CSRF exclusion for `webhooks/*` lives in
 * bootstrap/app.php - an external service has no session and no CSRF token,
 * and its authenticity is established by an HMAC signature instead. See
 * WebhookController, which refuses outright when no secret is configured.
 */
Route::post('/webhooks/lemonsqueezy', WebhookController::class)
    ->name('webhooks.lemonsqueezy');
