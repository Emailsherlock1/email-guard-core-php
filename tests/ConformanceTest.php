<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Tests;

use Emailsherlock\EmailGuard\EmailGuard;
use Emailsherlock\EmailGuard\Local\DisposableSnapshot;
use Emailsherlock\EmailGuard\Tests\Support\MockTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every conformance vector from email-guard-spec (vendored under
 * tests/vectors/, see tests/update-vectors.sh). All vectors must pass;
 * api_called is part of the assertion so local short-circuits provably
 * never burn an API call.
 */
final class ConformanceTest extends TestCase
{
    private const VECTOR_DIR = __DIR__ . '/vectors';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        foreach (glob(self::VECTOR_DIR . '/*.json') as $file) {
            if (basename($file) === 'schema.json') {
                continue;
            }
            $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            assert($doc['format'] === 'email-guard-vectors/1');
            foreach ($doc['vectors'] as $vector) {
                yield $vector['id'] => [$vector];
            }
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('vectors')]
    public function testVector(array $vector): void
    {
        $config = $vector['input']['config'] ?? [];
        $api = $vector['input']['api'] ?? null;

        $transport = $api === 'unavailable'
            ? MockTransport::unavailable()
            : MockTransport::respondingWith(is_array($api) ? $api : []);

        $guard = new EmailGuard(
            config: $config,
            transport: $transport,
            snapshot: new DisposableSnapshot(self::VECTOR_DIR . '/fixtures/disposable-snapshot.json'),
        );

        $result = $guard->check($vector['input']['email']);
        $expected = $vector['expected'];

        $this->assertSame($expected['verdict'], $result->verdict->value, 'verdict');
        $this->assertSame($expected['action'], $result->action->value, 'action');
        $this->assertSame($expected['reasons'], $result->reasons, 'reasons');
        $this->assertSame($expected['degraded'], $result->degraded, 'degraded');
        $this->assertSame($expected['api_called'], $result->apiCalled, 'api_called');
        $this->assertSame($expected['api_called'] ? 1 : 0, $transport->calls, 'transport call count');
    }
}
