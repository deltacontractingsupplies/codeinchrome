<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDomain extends Model
{
    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The record name the customer publishes the token under. */
    public function challengeName(): string
    {
        return '_codeinchrome-challenge.' . $this->domain;
    }

    /**
     * Normalise and validate a domain a customer typed.
     *
     * @return array{0: ?string, 1: ?string} [domain, error]
     */
    public static function normalise(string $input): array
    {
        $d = strtolower(trim($input));
        $d = preg_replace('#^https?://#', '', $d);
        $d = rtrim(explode('/', $d)[0], '.');

        // Internationalised names are stored in their ASCII (punycode) form,
        // which is what DNS and certificates use.
        if (preg_match('/[^\x00-\x7f]/', $d)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return [null, 'That domain name is not valid.'];
            }
            $d = $ascii;
        }

        if (strlen($d) > 253 || ! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $d)) {
            return [null, 'Enter a domain like shop.example.com.'];
        }

        // The platform's own names are not customers' to claim: the zone
        // apex and every site address directly under it.
        $zone = config('fleet.zone');
        if ($d === $zone || preg_match('/^[^.]+\.' . preg_quote($zone, '/') . '$/', $d)) {
            return [null, "Addresses on $zone are assigned by codeinchrome, not added as custom domains."];
        }

        return [$d, null];
    }
}
