<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * What the form should do with the address (email-guard-spec, section 6.4).
 */
enum Action: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Review = 'review';
}
