<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Tests;

use Emailsherlock\EmailGuard\Action;
use Emailsherlock\EmailGuard\EmailGuard;
use Emailsherlock\EmailGuard\Local\DisposableSnapshot;
use Emailsherlock\EmailGuard\Tests\Support\MockTransport;
use Emailsherlock\EmailGuard\Verdict;
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

    public function testNon2xxDegrades(): void
    {
        $transport = MockTransport::respondingWith(['error' => ['code' => 'server_error']], 503);
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertTrue($result->degraded);
        $this->assertSame(Action::Allow, $result->action);
    }

    public function testNonJsonBodyDegrades(): void
    {
        $transport = MockTransport::respondingRaw('<html>gateway error</html>');
        $guard = new EmailGuard(['api_key' => 'k'], $transport, $this->fixtureSnapshot());

        $result = $guard->check('jane@real-corp.com');

        $this->assertTrue($result->degraded);
        $this->assertSame(Verdict::Unknown, $result->verdict);
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
