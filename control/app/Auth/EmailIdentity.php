<?php

namespace App\Auth;

/**
 * The mailbox an address really reaches, so one inbox is one account.
 *
 * Gmail ignores dots and anything after "+" in the local part, and
 * googlemail.com is gmail.com: "a.b+x@googlemail.com" and "ab@gmail.com" are
 * one inbox. Found by the security audit (2026-09-25): stored as typed, one
 * Gmail account could open unlimited free accounts. Elsewhere only case is
 * folded - a "+tag" means something different on other providers.
 */
final class EmailIdentity
{
    public static function canonical(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', explode('+', $local, 2)[0]);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
