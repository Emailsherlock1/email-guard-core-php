<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * Reason codes produced by the local checks (email-guard-spec, section 6.2).
 * Reasons from the Verify API pass through verbatim and are not enumerated
 * here; the spec requires unrecognized strings to survive untouched.
 */
final class Reason
{
    public const BAD_SYNTAX = 'bad_syntax';
    public const RESERVED_TLD = 'reserved_tld';
    public const DISPOSABLE_PROVIDER = 'disposable_provider';
    public const API_UNAVAILABLE = 'api_unavailable';

    private function __construct()
    {
    }
}
