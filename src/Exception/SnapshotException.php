<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Exception;

/**
 * The bundled disposable snapshot is missing or malformed. This is a
 * packaging or integration error, not a runtime condition, so it throws
 * instead of degrading.
 */
final class SnapshotException extends \RuntimeException
{
}
