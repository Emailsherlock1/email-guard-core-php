<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * Where a decision was made, for telemetry (email-guard-spec section 11.2):
 * local = a bundled check decided it, remote = the Verify API did.
 */
final class GuardDecisionSource
{
    public const LOCAL = 'local';
    public const REMOTE = 'remote';

    private function __construct()
    {
    }
}
