<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Tests\Support;

use Emailsherlock\EmailGuard\Http\TransportException;
use Emailsherlock\EmailGuard\Http\TransportInterface;
use Emailsherlock\EmailGuard\Http\TransportResponse;

/**
 * Transport mock for the conformance harness: either unavailable (throws)
 * or answers 200 with a fixed JSON body. Records every call.
 */
final class MockTransport implements TransportInterface
{
    public int $calls = 0;
    public ?string $lastUrl = null;
    /** @var array<string, string>|null */
    public ?array $lastHeaders = null;
    public ?string $lastBody = null;
    public ?int $lastTimeoutMs = null;

    private function __construct(
        private readonly ?array $responseBody,
        private readonly int $statusCode,
        private readonly bool $unavailable,
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(null, 0, true);
    }

    /** @param array<string, mixed> $body */
    public static function respondingWith(array $body, int $statusCode = 200): self
    {
        return new self($body, $statusCode, false);
    }

    public static function respondingRaw(string $body, int $statusCode = 200): self
    {
        return new self(null, $statusCode, false, $body);
    }

    public function post(string $url, array $headers, string $body, int $timeoutMs): TransportResponse
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;
        $this->lastTimeoutMs = $timeoutMs;

        if ($this->unavailable) {
            throw new TransportException('mock: API unavailable');
        }

        $payload = $this->rawBody ?? json_encode($this->responseBody, JSON_THROW_ON_ERROR);

        return new TransportResponse($this->statusCode, $payload);
    }
}
