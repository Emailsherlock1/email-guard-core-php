<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Tests;

use Emailsherlock\EmailGuard\Action;
use Emailsherlock\EmailGuard\EmailGuard;
use Emailsherlock\EmailGuard\GuardReporter;
use Emailsherlock\EmailGuard\Local\DisposableSnapshot;
use Emailsherlock\EmailGuard\Result;
use Emailsherlock\EmailGuard\Tests\Support\MockTransport;
use Emailsherlock\EmailGuard\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * EM-1005 — the decision-telemetry emitter (spec section 11): key-gated,
 * fail-silent, batched, domain only.
 */
final class GuardReporterTest extends TestCase
{
    public function testNoKeyMakesRecordAndFlushNoOps(): void
    {
        $transport = MockTransport::respondingWith([]);
        $reporter = new GuardReporter(apiKey: null, transport: $transport);

        $this->assertFalse($reporter->isEnabled());
        $reporter->record($this->makeResult(), 'mailinator.com');
        $reporter->flush();

        $this->assertSame([], $reporter->pending());
        $this->assertSame(0, $transport->calls);
    }

    public function testFlushPostsBatchedEventsToTheIngestEndpoint(): void
    {
        $transport = MockTransport::respondingWith(['accepted' => 2, 'rejected' => 0]);
        $reporter = new GuardReporter(
            apiKey: 'k-internal',
            baseUrl: 'https://api.emailsherlock.com',
            integration: 'symfony-bundle',
            transport: $transport,
        );

        $reporter->record($this->makeResult(Verdict::Disposable, Action::Deny, ['disposable_provider']), 'mailinator.com');
        $reporter->record($this->makeResult(Verdict::Invalid, Action::Deny, ['reserved_tld']), 'deleted.invalid');
        $reporter->flush();

        $this->assertSame(1, $transport->calls);
        $this->assertSame('https://api.emailsherlock.com/v1/guard/events', $transport->lastUrl);
        $this->assertSame('k-internal', $transport->lastHeaders['X-API-Key']);

        $body = json_decode((string) $transport->lastBody, true);
        $this->assertCount(2, $body['events']);
        $this->assertSame('mailinator.com', $body['events'][0]['domain']);
        $this->assertSame('disposable', $body['events'][0]['verdict']);
        $this->assertSame('deny', $body['events'][0]['action']);
        $this->assertSame(['disposable_provider'], $body['events'][0]['reasons']);
        $this->assertSame('local', $body['events'][0]['source']);
        $this->assertSame('symfony-bundle', $body['events'][0]['integration']);
        // Queue cleared after flush.
        $this->assertSame([], $reporter->pending());
    }

    public function testRemoteSourceWhenApiWasCalled(): void
    {
        $transport = MockTransport::respondingWith([]);
        $reporter = new GuardReporter(apiKey: 'k', transport: $transport);

        $reporter->record($this->makeResult(Verdict::Valid, Action::Allow, ['mailbox_accepts'], apiCalled: true), 'real.com');
        $reporter->flush();

        $body = json_decode((string) $transport->lastBody, true);
        $this->assertSame('remote', $body['events'][0]['source']);
    }

    public function testFlushIsFailSilentOnTransportError(): void
    {
        $transport = MockTransport::unavailable();
        $reporter = new GuardReporter(apiKey: 'k', transport: $transport);

        $reporter->record($this->makeResult(), 'mailinator.com');
        $reporter->flush(); // must not throw

        // Queue cleared even though the post failed, so a dead endpoint can't
        // make events pile up across a long-lived worker.
        $this->assertSame([], $reporter->pending());
    }

    public function testNeverIncludesAnAddressOnlyTheDomain(): void
    {
        $transport = MockTransport::respondingWith([]);
        $reporter = new GuardReporter(apiKey: 'k', transport: $transport);

        $reporter->record($this->makeResult(), 'mailinator.com');
        $reporter->flush();

        $this->assertStringNotContainsString('@', (string) $transport->lastBody);
    }

    public function testEmailGuardAutoRecordsThroughTheReporter(): void
    {
        $transport = MockTransport::respondingWith([]);
        $reporter = new GuardReporter(apiKey: 'k', transport: $transport);
        $guard = new EmailGuard(
            config: [],
            snapshot: new DisposableSnapshot(__DIR__ . '/vectors/fixtures/disposable-snapshot.json'),
            reporter: $reporter,
        );

        $guard->check('deleted+user274@deleted.invalid');

        $pending = $reporter->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('deleted.invalid', $pending[0]['domain']);
        $this->assertSame('invalid', $pending[0]['verdict']);
        $this->assertSame('reserved_tld', $pending[0]['reasons'][0]);
    }

    public function testEmailGuardRecordsNullDomainOnSyntaxFailure(): void
    {
        $transport = MockTransport::respondingWith([]);
        $reporter = new GuardReporter(apiKey: 'k', transport: $transport);
        $guard = new EmailGuard(
            config: [],
            snapshot: new DisposableSnapshot(__DIR__ . '/vectors/fixtures/disposable-snapshot.json'),
            reporter: $reporter,
        );

        $guard->check('not-an-address');

        $this->assertNull($reporter->pending()[0]['domain']);
        $this->assertSame('invalid', $reporter->pending()[0]['verdict']);
    }

    /** @param list<string> $reasons */
    private function makeResult(Verdict $verdict = Verdict::Disposable, Action $action = Action::Deny, array $reasons = ['disposable_provider'], bool $apiCalled = false): Result
    {
        return new Result($verdict, $action, $reasons, degraded: false, apiCalled: $apiCalled);
    }
}
