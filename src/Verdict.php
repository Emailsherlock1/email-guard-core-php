<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * Classification of an address. Mirrors the `result` enum of the Verify API
 * (email-guard-spec, section 6.1).
 */
enum Verdict: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Disposable = 'disposable';
    case Role = 'role';
    case CatchAll = 'catch_all';
    case Unknown = 'unknown';
}
