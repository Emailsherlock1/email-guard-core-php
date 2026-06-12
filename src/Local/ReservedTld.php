<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Local;

/**
 * Reserved names that can never receive public mail
 * (email-guard-spec, section 4.2). The lists are part of the spec;
 * changing them here without a spec release breaks conformance.
 */
final class ReservedTld
{
    private const RESERVED_TLDS = [
        'test' => true,
        'example' => true,
        'invalid' => true,
        'localhost' => true,
        'local' => true,
        'onion' => true,
        'alt' => true,
        'internal' => true,
    ];

    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /**
     * @param string $domain lowercased domain part
     */
    public static function isReserved(string $domain): bool
    {
        $dotPosition = strrpos($domain, '.');
        $tld = $dotPosition === false ? $domain : substr($domain, $dotPosition + 1);
        if (isset(self::RESERVED_TLDS[$tld])) {
            return true;
        }
        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
                return true;
            }
        }
        return false;
    }
}
