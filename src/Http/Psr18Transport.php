<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for any PSR-18 client (Guzzle, Symfony HttpClient, ...).
 *
 * PSR-18 has no per-request timeout, so the guard's timeout budget must be
 * configured on the wrapped client itself; the timeoutMs argument is ignored
 * here. Keep the client's timeout at or below the guard's timeout_ms, or the
 * fail-open guarantee stretches accordingly.
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function post(string $url, array $headers, string $body, int $timeoutMs): TransportResponse
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException('Verify API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        return new TransportResponse($response->getStatusCode(), (string) $response->getBody());
    }
}
