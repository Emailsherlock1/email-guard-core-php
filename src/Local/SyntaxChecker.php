<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Local;

/**
 * The pragmatic syntax profile from email-guard-spec, section 4.1.
 *
 * Structural rules (exactly one @, both parts non-empty) always apply.
 * Profile rules are skipped for a part that contains non-ASCII characters:
 * the guard only blocks what it can prove is junk, and internationalized
 * addresses (RFC 6531) are the API's job, not a regex's.
 */
final class SyntaxChecker
{
    private const LOCAL_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/';
    private const LABEL_PATTERN = '/^[A-Za-z0-9-]+$/';

    public static function isValid(string $address): bool
    {
        if (substr_count($address, '@') !== 1) {
            return false;
        }
        [$local, $domain] = explode('@', $address, 2);
        if ($local === '' || $domain === '') {
            return false;
        }
        if (self::isAscii($local) && !self::localPartOk($local)) {
            return false;
        }
        if (self::isAscii($domain) && !self::domainPartOk($domain)) {
            return false;
        }
        return true;
    }

    private static function isAscii(string $part): bool
    {
        return preg_match('/[^\x00-\x7F]/', $part) !== 1;
    }

    private static function localPartOk(string $local): bool
    {
        if (strlen($local) > 64) {
            return false;
        }
        if (preg_match(self::LOCAL_PATTERN, $local) !== 1) {
            return false;
        }
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return false;
        }
        return true;
    }

    private static function domainPartOk(string $domain): bool
    {
        $length = strlen($domain);
        if ($length < 4 || $length > 253) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            $labelLength = strlen($label);
            if ($labelLength < 1 || $labelLength > 63) {
                return false;
            }
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return false;
            }
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }
        $tld = $labels[count($labels) - 1];
        if (strlen($tld) < 2 || preg_match('/^[0-9]+$/', $tld) === 1) {
            return false;
        }
        return true;
    }
}
