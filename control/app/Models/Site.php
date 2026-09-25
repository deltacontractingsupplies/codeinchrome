<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Site extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['provisioned_at' => 'datetime', 'usage_at' => 'datetime', 'last_backup_at' => 'datetime', 'scanned_clean_at' => 'datetime', 'links_clean_at' => 'datetime', 'noindex_until' => 'datetime',
            'last_worked_at' => 'datetime', 'last_visit_at' => 'datetime', 'idle_warned_at' => 'datetime',
            'final_backup_started_at' => 'datetime', 'limits_pending' => 'boolean',
            'queue' => 'boolean', 'scheduler' => 'boolean', 'reverb' => 'boolean', 'php_settings' => 'array'];
    }

    /**
     * Bound by name, not id. The panel and every URL a customer sees use the
     * site name they chose; exposing sequential ids would also hand out a
     * count of how many sites the platform has ever created.
     */
    public function getRouteKeyName(): string
    {
        return 'site_id';
    }

    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /** Every new site, whichever path made it, is told to the owner (App\Fleet\OwnerNotifier). */
    protected static function booted(): void
    {
        static::created(fn (Site $site) => \App\Fleet\OwnerNotifier::newSite($site));
    }

        public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Files plus database, in bytes, as last measured; null if never measured. */
    public function totalBytes(): ?int
    {
        if ($this->usage_at === null) {
            return null;
        }

        return (int) $this->disk_used_bytes + (int) $this->database_bytes;
    }

    public function url(): string
    {
        return 'https://' . $this->domain;
    }

    /**
     * A site id becomes a directory name, a container name and a DNS label on
     * the host, so it is validated here against exactly what the agent
     * accepts. Duplicating the rule is deliberate: the agent must never rely
     * on this check having happened, and this must never send a request the
     * agent is going to reject.
     */
    public static function validId(string $id): ?string
    {
        // Reserved is checked FIRST so the reason is the useful one. Several
        // reserved names - h1, h2, ns1 - are also too short for the format
        // rule, and telling someone who typed "h1" that it needs three
        // characters invites them to try "h1x" when the real answer is that
        // the name is ours.
        if (in_array($id, self::RESERVED, true)) {
            return "\"$id\" is reserved.";
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $id)) {
            return 'Use 3 to 40 characters: lowercase letters, numbers and hyphens, not starting or ending with a hyphen.';
        }
        if (str_contains($id, '--')) {
            return 'Two hyphens in a row are not allowed.';
        }
        if ($brand = self::impersonates($id)) {
            return "Names that use \"$brand\" are not available: they are what phishing sites use to look like that company.";
        }

        return null;
    }

    /**
     * The brands phishing kits dress up as (the security audit, 2026-09-25:
     * paypal-login and apple-id-verify were both allowed). Long names are
     * matched anywhere in the site id; short or common words only as a whole
     * word between hyphens, so "pineapple-shop" and "foodbank" stay free.
     */
    public const IMPERSONATED_ANYWHERE = ['paypal', 'icloud', 'microsoft', 'office365', 'outlook', 'hotmail', 'gmail',
        'facebook', 'instagram', 'whatsapp', 'netflix', 'amazon', 'coinbase', 'binance', 'metamask', 'trustwallet',
        'wellsfargo', 'barclays', 'citibank', 'santander', 'revolut', 'mastercard', 'fedex', 'docusign', 'dropbox',
        'onedrive', 'sharepoint', 'linkedin', 'telegram', 'codeinchrome', 'cloudflare', 'hetzner'];

    // Not "ups", "visa", "wallet" or "steam": each is also an ordinary
    // business word (a cafe, a visa consultancy, a leather shop, a laundry).
    public const IMPERSONATED_WORDS = ['apple', 'appleid', 'google', 'bank', 'chase', 'hsbc', 'dhl', 'usps',
        'amex', 'roblox', 'irs', 'hmrc'];

    public static function impersonates(string $id): ?string
    {
        foreach (self::IMPERSONATED_ANYWHERE as $brand) {
            if (str_contains($id, $brand)) {
                return $brand;
            }
        }
        foreach (explode('-', $id) as $word) {
            if (in_array($word, self::IMPERSONATED_WORDS, true)) {
                return $word;
            }
        }

        return null;
    }

    /**
     * Names that must never become a customer subdomain, because they already
     * mean something on this zone or are the obvious targets for someone
     * trying to impersonate us.
     */
    public const RESERVED = [
        'www', 'app', 'api', 'admin', 'mail', 'smtp', 'imap', 'ns1', 'ns2',
        'cdn', 'static', 'assets', 'status', 'billing', 'pay', 'checkout',
        'account', 'accounts', 'login', 'signup', 'register', 'support',
        'help', 'docs', 'blog', 'dev', 'staging', 'test', 'demo', 'panel',
        'dashboard', 'console', 'control', 'agent', 'host', 'h1', 'h2', 'h3', 'h4',
    ];
}
