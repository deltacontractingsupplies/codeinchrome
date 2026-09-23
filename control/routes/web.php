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
Route::view('/pricing', 'pricing')->name('pricing');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/refunds', 'legal.refunds')->name('refunds');

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
    // One-time links from `php artisan user:login-link` (App\Auth\LoginLink).
    Route::get('/login/link/{token}', function (\Illuminate\Http\Request $request, string $token) {
        $user = \App\Auth\LoginLink::consume($token);
        abort_unless($user, 404);
        \Illuminate\Support\Facades\Auth::login($user);
        $request->session()->regenerate();
        \App\Audit\Audit::record('auth.login_link');

        return redirect()->route('dashboard');
    })->middleware('throttle:login')->name('login.link');
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

    // The panel's file operations. Session-authenticated like the rest of the
    // dashboard, so the browser needs no second credential and there is no
    // long-lived token to leak.
    Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])->name('sites.edit');

    Route::get('/sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings');
    Route::get('/sites/{site}/backups', [BackupController::class, 'index'])->name('sites.backups');
    Route::post('/sites/{site}/backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('sites.backups.store');
    Route::post('/sites/{site}/backups/restore', [BackupController::class, 'restore'])->middleware('throttle:6,1')->name('sites.backups.restore');
    Route::put('/sites/{site}/background', [SiteSettingsController::class, 'background'])->middleware('throttle:provision')->name('sites.background');
    Route::get('/sites/{site}/domains', [DomainController::class, 'index'])->name('domains.index');
    Route::post('/sites/{site}/domains', [DomainController::class, 'store'])->middleware(['throttle:domains', VerifiedWhenMailEnabled::class])->name('domains.store');
    // Verification queries public DNS; throttled so it cannot be used to make
    // us hammer resolvers.
    Route::post('/sites/{site}/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:domains')->name('domains.verify');
    Route::delete('/sites/{site}/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::get('/sites/{site}/files', [FileController::class, 'index'])->name('files.index');
    Route::put('/sites/{site}/files', [FileController::class, 'store'])->name('files.store');
    Route::delete('/sites/{site}/files', [FileController::class, 'destroy'])->name('files.destroy');
    // The rest of the file manager.
    Route::post('/sites/{site}/files/mkdir', [FileManagerController::class, 'mkdir'])->name('files.mkdir');
    Route::post('/sites/{site}/files/move', [FileManagerController::class, 'move'])->name('files.move');
    Route::post('/sites/{site}/files/copy', [FileManagerController::class, 'copy'])->name('files.copy');
    Route::post('/sites/{site}/files/zip', [FileManagerController::class, 'zip'])->middleware('throttle:command')->name('files.zip');
    Route::post('/sites/{site}/files/unzip', [FileManagerController::class, 'unzip'])->middleware('throttle:command')->name('files.unzip');
    Route::delete('/sites/{site}/tree', [FileManagerController::class, 'destroyTree'])->name('files.tree.destroy');
    Route::get('/sites/{site}/search', [FileManagerController::class, 'search'])->middleware('throttle:command')->name('files.search');
    Route::post('/sites/{site}/upload', [FileManagerController::class, 'upload'])->middleware('throttle:command')->name('files.upload');
    Route::get('/sites/{site}/download', [FileManagerController::class, 'download'])->name('files.download');
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
    Route::post('/sites/{site}/db/import', [DatabaseController::class, 'import'])->middleware('throttle:db')->name('db.import');
});

/*
 * Lemon Squeezy posts here. The CSRF exclusion for `webhooks/*` lives in
 * bootstrap/app.php - an external service has no session and no CSRF token,
 * and its authenticity is established by an HMAC signature instead. See
 * WebhookController, which refuses outright when no secret is configured.
 */
Route::post('/webhooks/lemonsqueezy', WebhookController::class)
    ->name('webhooks.lemonsqueezy');
