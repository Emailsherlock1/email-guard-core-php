<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Http;

/**
 * Minimal HTTP contract the guard needs: one JSON POST with a hard time
 * budget. Implementations throw TransportException on any network-level
 * failure; the guard turns that into a degraded, fail-open result.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException on connection failure or timeout
     */
    public function post(string $url, array $headers, string $body, int $timeoutMs): TransportResponse;
}
