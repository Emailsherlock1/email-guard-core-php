<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Http;

final class TransportException extends \RuntimeException
{
    /** Cause could not be attributed; the guard reports only `api_unavailable`. */
    public const KIND_UNKNOWN = 'unknown';

    /** Total or connect timeout budget exceeded (curl error 28). */
    public const KIND_TIMEOUT = 'timeout';

    /** DNS, TLS, or connection failure before any HTTP status. */
    public const KIND_CONNECTION = 'connection';

    /** The API answered a non-2xx status; $statusCode carries the code. */
    public const KIND_HTTP = 'http';

    /** A 2xx answer whose body was not the expected JSON. */
    public const KIND_BAD_RESPONSE = 'bad_response';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_UNKNOWN,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
