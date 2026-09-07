<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Tests;

use Emailsherlock\EmailGuard\Action;
use Emailsherlock\EmailGuard\EmailGuard;
use Emailsherlock\EmailGuard\Http\TransportException;
use Emailsherlock\EmailGuard\Local\DisposableSnapshot;
use Emailsherlock\EmailGuard\Reason;
use Emailsherlock\EmailGuard\Signal;
use Emailsherlock\EmailGuard\Tests\Support\MockTransport;
use Emailsherlock\EmailGuard\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behavior beyond the conformance vectors: request shape, bundled snapshot,
 * HTTP edge cases, config validation.
 */
final class EmailGuardTest extends TestCase
{
    public function testBundledSnapshotLoadsAndFlagsKnownProvider(): void
    {
        $guard = new EmailGuard();
        $result = $guard->check('x@mailinator.com');

        $this->assertSame(Verdict::Disposable, $result->verdict);
        $this->assertSame(Action::Deny, $result->action);
    }

    public function testRequestShape(): void
    {
        $transport = MockTransport::respondingWith(['result' => 'valid', 'reason' => 'mailbox_accepts']);
        $guard = new EmailGuard(['api_key' => 'k-123', 'timeout_ms' => 500], $transport, $this->fixtureSnapshot());

        $result = $guard->check('  Jane.Doe@Real-Corp.com  ');

        $this->assertSame('https://api.emailsherlock.com/v1/verify/single', $transport->lastUrl);
        $this->assertSame('k-123', $transport->lastHeaders['X-API-Key']);
        // Trimmed, original case preserved: the address goes to the API as typed.
        $this->assertSame('{"email":"Jane.Doe@Real-Corp.com"}', $transport->lastBody);
        $this->assertSame(500, $transport->lastTimeoutMs);
        $this->assertSame(Verdict::Valid, $result->verdict);
        $this->assertSame(['result' => 'valid', 'reason' => 'mailbox_accepts'], $result->apiResponse);
    }

    public function testRelaySignalIsExposedOnResultEvenWhenNotBlocked(): void
    {
        // Default config does not list `relay`, so the address is allowed —
        // but the signal + provider are still surfaced for the integrator.
        $transport = MockTransport::respondingWith([
            'result' => 'valid',
            'reason' => 'mailbox_accepts',
            'relay' => true,
            'relay_provider' => 'SimpleLogin',
        ]);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('chookity-pok@passmail.net');

        $this->assertTrue($result->relay);
        $this->assertSame('SimpleLogin', $result->relayProvider);
        $this->assertTrue($result->isAllowed());
        $this->assertSame(['mailbox_accepts'], $result->reasons);
    }

    public function testRelayProviderDefaultsToNullWhenAbsent(): void
    {
        $transport = MockTransport::respondingWith(['result' => 'valid', 'reason' => 'mailbox_accepts']);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertFalse($result->relay);
        $this->assertNull($result->relayProvider);
    }

    public function testSpamTrapSignalIsExposedOnResultEvenWhenNotBlocked(): void
    {
        // Same contract as relay: the default config lists no signal, so the
        // address is allowed, but the integrator still sees the finding.
        $transport = MockTransport::respondingWith([
            'result' => 'valid',
            'reason' => 'mailbox_accepts',
            'spam_trap' => true,
            'spam_trap_type' => 'known_trap_domain',
        ]);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@trap-corp.com');

        $this->assertTrue($result->spamTrap);
        $this->assertSame('known_trap_domain', $result->spamTrapType);
        $this->assertTrue($result->isAllowed());
        $this->assertSame(['mailbox_accepts'], $result->reasons);
    }

    public function testSpamTrapTypeSurvivesOnAReviewedAddress(): void
    {
        // The type is what an integrator reads to decide how much to trust the
        // finding, so it has to be there on the decision that acts on it, not
        // just on the inert one above.
        $transport = MockTransport::respondingWith([
            'result' => 'valid',
            'reason' => 'mailbox_accepts',
            'spam_trap' => true,
            'spam_trap_type' => 'reputation_heuristic',
        ]);
        $guard = new EmailGuard(
            ['api_key' => 'k', 'review_on' => [Signal::SPAM_TRAP]],
            $transport,
            $this->fixtureSnapshot(),
        );

        $result = $guard->check('jane@trap-corp.com');

        $this->assertTrue($result->needsReview());
        $this->assertSame('reputation_heuristic', $result->spamTrapType);
        $this->assertSame(['mailbox_accepts', 'spam_trap'], $result->reasons);
    }

    public function testSignalDetailIsDroppedWhenItsSignalIsFalse(): void
    {
        // A detail without its signal describes nothing the decision path can
        // explain, and Result documents both as "null otherwise".
        $transport = MockTransport::respondingWith([
            'result' => 'valid',
            'reason' => 'mailbox_accepts',
            'relay' => false,
            'relay_provider' => 'SimpleLogin',
            'spam_trap' => false,
            'spam_trap_type' => 'known_trap_domain',
        ]);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertFalse($result->relay);
        $this->assertNull($result->relayProvider);
        $this->assertFalse($result->spamTrap);
        $this->assertNull($result->spamTrapType);
    }

    /**
     * A signal fires on boolean true and nothing else. The API sends real
     * booleans, but a proxy that JSON-encodes everything as strings would
     * otherwise turn "false" into a deny.
     */
    #[DataProvider('nonBooleanTrueValues')]
    public function testOnlyBooleanTrueFiresASignal(mixed $value): void
    {
        $transport = MockTransport::respondingWith([
            'result' => 'valid',
            'reason' => 'mailbox_accepts',
            'spam_trap' => $value,
        ]);
        $guard = new EmailGuard(
            ['api_key' => 'k', 'block_on' => [Signal::SPAM_TRAP]],
            $transport,
            $this->fixtureSnapshot(),
        );

        $result = $guard->check('jane@trap-corp.com');

        $this->assertFalse($result->spamTrap);
        $this->assertTrue($result->isAllowed());
        $this->assertSame(['mailbox_accepts'], $result->reasons);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonBooleanTrueValues(): iterable
    {
        yield 'string true' => ['true'];
        yield 'string false' => ['false'];
        yield 'integer one' => [1];
        yield 'null' => [null];
    }

    public function testSignalNamesAreTheVocabularyInSpecTableOrder(): void
    {
        // Public because the spec asks for it: an unknown token in block_on is
        // inert by design, so this list is how an integrator tells a typo from
        // a signal this version predates. The order is the order active signals
        // append their reasons, which the vectors compare as a sequence.
        $this->assertSame(['relay', 'spam_trap'], Signal::names());
    }

    public function testNon2xxDegradesAndAttributesHttpBucket(): void
    {
        $transport = MockTransport::respondingWith(['error' => ['code' => 'server_error']], 503);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertTrue($result->degraded);
        $this->assertSame(Action::Allow, $result->action);
        // Umbrella first, then the attributed cause (spec 5.2).
        $this->assertSame([Reason::API_UNAVAILABLE, Reason::API_HTTP_5XX], $result->reasons);
    }

    public function testFourXxAttributesHttp4xxBucket(): void
    {
        $transport = MockTransport::respondingWith(['error' => ['code' => 'rate_limited']], 429);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertSame([Reason::API_UNAVAILABLE, Reason::API_HTTP_4XX], $result->reasons);
    }

    public function testNonJsonBodyDegradesAndAttributesBadResponse(): void
    {
        $transport = MockTransport::respondingRaw('<html>gateway error</html>');
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertTrue($result->degraded);
        $this->assertSame(Verdict::Unknown, $result->verdict);
        $this->assertSame([Reason::API_UNAVAILABLE, Reason::API_BAD_RESPONSE], $result->reasons);
    }

    public function testTimeoutAttributesApiTimeout(): void
    {
        $transport = MockTransport::throwing(TransportException::KIND_TIMEOUT);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertTrue($result->degraded);
        $this->assertSame([Reason::API_UNAVAILABLE, Reason::API_TIMEOUT], $result->reasons);
    }

    public function testConnectionFailureAttributesApiUnreachable(): void
    {
        $transport = MockTransport::throwing(TransportException::KIND_CONNECTION);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertSame([Reason::API_UNAVAILABLE, Reason::API_UNREACHABLE], $result->reasons);
    }

    public function testUnattributedFailureKeepsOnlyUmbrella(): void
    {
        // A transport that throws without a kind (the generic outage) must keep
        // the pre-1.3.0 behavior: api_unavailable alone, no granular reason.
        $transport = MockTransport::unavailable();
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertSame([Reason::API_UNAVAILABLE], $result->reasons);
    }

    public function testUnexpectedFutureResultValueMapsToUnknown(): void
    {
        $transport = MockTransport::respondingWith(['result' => 'quarantined', 'reason' => 'new_fancy_reason']);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertSame(Verdict::Unknown, $result->verdict);
        $this->assertSame(['new_fancy_reason'], $result->reasons);
        $this->assertFalse($result->degraded);
    }

    public function testUnknownConfigKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailGuard(['blockOn' => ['invalid']]);
    }

    public function testEmptyStringApiKeyDisablesRemoteCheck(): void
    {
        $transport = MockTransport::respondingWith(['result' => 'valid']);
        $guard = new EmailGuard(['api_key' => ''], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertFalse($result->apiCalled);
        $this->assertSame(0, $transport->calls);
        $this->assertSame(Verdict::Unknown, $result->verdict);
    }

    public function testBrokenSnapshotSkipsDisposableCheckInsteadOfThrowing(): void
    {
        $guard = new EmailGuard(
            [],
            null,
            new DisposableSnapshot(__DIR__ . '/does-not-exist.json'),
        );

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            $result = $guard->check('x@mailinator.com');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Verdict::Unknown, $result->verdict);
        $this->assertSame(Action::Allow, $result->action);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('disposable check skipped', $warnings[0]);
    }

    private function fixtureSnapshot(): DisposableSnapshot
    {
        return new DisposableSnapshot(__DIR__ . '/vectors/fixtures/disposable-snapshot.json');
    }
}
