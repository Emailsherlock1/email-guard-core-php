<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

use Emailsherlock\EmailGuard\Http\CurlTransport;
use Emailsherlock\EmailGuard\Http\TransportException;
use Emailsherlock\EmailGuard\Http\TransportInterface;

/**
 * Decision telemetry emitter (email-guard-spec section 11). Collects one event
 * per guard decision and ships them, batched, to POST /v1/guard/events.
 *
 * The contract is the spec's: key-gated (no key, no telemetry), fail-silent
 * (a reporting failure never surfaces), domain only (never the address). The
 * caller decides WHEN to flush — in a web app, after the response is sent
 * (the Symfony bundle wires a kernel.terminate listener) so a form submit
 * never waits on the report.
 */
final class GuardReporter
{
    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl = 'https://api.emailsherlock.com',
        private readonly int $timeoutMs = 800,
        private readonly ?string $integration = null,
        private readonly ?TransportInterface $transport = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Queue one decision. No-op without a key, so callers can record
     * unconditionally. Stores only the domain, never the address.
     */
    public function record(Result $result, ?string $domain): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->pending[] = [
            'domain' => $domain,
            'verdict' => $result->verdict->value,
            'action' => $result->action->value,
            'reasons' => $result->reasons,
            'degraded' => $result->degraded,
            'source' => $result->apiCalled ? GuardDecisionSource::REMOTE : GuardDecisionSource::LOCAL,
            'integration' => $this->integration,
            'lib_version' => EmailGuard::VERSION,
            'spec_version' => EmailGuard::SPEC_VERSION,
        ];
    }

    /**
     * Ship the queued events and clear the queue. Fire-and-forget: any
     * transport failure is swallowed, the queue is cleared either way so a
     * dead endpoint can't make events pile up across a long-lived process.
     */
    public function flush(): void
    {
        if (!$this->isEnabled() || $this->pending === []) {
            return;
        }

        $events = $this->pending;
        $this->pending = [];

        $transport = $this->transport ?? new CurlTransport();
        try {
            $transport->post(
                rtrim($this->baseUrl, '/') . '/v1/guard/events',
                [
                    'X-API-Key' => (string) $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'email-guard-core-php/' . EmailGuard::VERSION,
                ],
                json_encode(['events' => $events], JSON_THROW_ON_ERROR),
                $this->timeoutMs,
            );
        } catch (TransportException|\JsonException) {
            // Fail-silent by contract: telemetry must never break or slow the
            // host. The events are already dropped from the queue.
        }
    }

    /** @return list<array<string, mixed>> events queued but not yet flushed (testing) */
    public function pending(): array
    {
        return $this->pending;
    }
}
