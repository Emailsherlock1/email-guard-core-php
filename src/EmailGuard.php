<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

use Emailsherlock\EmailGuard\Http\CurlTransport;
use Emailsherlock\EmailGuard\Http\TransportException;
use Emailsherlock\EmailGuard\Http\TransportInterface;
use Emailsherlock\EmailGuard\Local\DisposableSnapshot;
use Emailsherlock\EmailGuard\Local\ReservedTld;
use Emailsherlock\EmailGuard\Local\SyntaxChecker;

/**
 * Reference implementation of email-guard-spec for PHP.
 *
 * Local checks run first and cost nothing. Only addresses that pass them
 * escalate to the Verify API, and only when an api_key is configured.
 * The guard fails open by default: an API outage never blocks a signup.
 *
 *     $guard = new EmailGuard(['api_key' => '...']);
 *     $result = $guard->check($email);
 *     if ($result->isDenied()) { ... }
 */
final class EmailGuard
{
    public const VERSION = '0.1.2';
    public const SPEC_VERSION = '1.0.0';

    private const DEFAULTS = [
        'api_key' => null,
        'block_on' => ['invalid', 'disposable'],
        'review_on' => [],
        'fail_open' => true,
        'timeout_ms' => 800,
        'base_url' => 'https://api.emailsherlock.com',
    ];

    /** @var array{api_key: ?string, block_on: list<string>, review_on: list<string>, fail_open: bool, timeout_ms: int, base_url: string} */
    private readonly array $config;

    private readonly TransportInterface $transport;
    private readonly DisposableSnapshot $snapshot;

    /**
     * @param array<string, mixed> $config keys as in the spec, section 6.3:
     *   api_key, block_on, review_on, fail_open, timeout_ms, plus base_url
     */
    public function __construct(
        array $config = [],
        ?TransportInterface $transport = null,
        ?DisposableSnapshot $snapshot = null,
    ) {
        $unknown = array_diff_key($config, self::DEFAULTS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown config keys: ' . implode(', ', array_keys($unknown)));
        }
        if (($config['api_key'] ?? null) === '') {
            // An empty env var reads as "", not null. Treating it as a
            // configured key would send a doomed API call per check.
            $config['api_key'] = null;
        }
        $this->config = array_merge(self::DEFAULTS, $config);
        $this->transport = $transport ?? new CurlTransport();
        $this->snapshot = $snapshot ?? new DisposableSnapshot();
    }

    public function check(string $email): Result
    {
        $address = trim($email, " \t\r\n");

        $verdict = null;
        $reasons = [];

        if (!SyntaxChecker::isValid($address)) {
            $verdict = Verdict::Invalid;
            $reasons = [Reason::BAD_SYNTAX];
        } else {
            $domain = strtolower(substr($address, strpos($address, '@') + 1));
            if (ReservedTld::isReserved($domain)) {
                $verdict = Verdict::Invalid;
                $reasons = [Reason::RESERVED_TLD];
            } elseif ($this->snapshotSaysDisposable($domain)) {
                $verdict = Verdict::Disposable;
                $reasons = [Reason::DISPOSABLE_PROVIDER];
            }
        }

        $degraded = false;
        $apiCalled = false;
        $apiResponse = null;

        if ($verdict === null && $this->config['api_key'] !== null) {
            $apiCalled = true;
            try {
                $apiResponse = $this->callApi($address);
                $verdict = Verdict::tryFrom((string) ($apiResponse['result'] ?? '')) ?? Verdict::Unknown;
                $reason = $apiResponse['reason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $reasons[] = $reason;
                }
            } catch (TransportException) {
                $verdict = Verdict::Unknown;
                $reasons = [Reason::API_UNAVAILABLE];
                $degraded = true;
            }
        }

        $verdict ??= Verdict::Unknown;

        return new Result(
            verdict: $verdict,
            action: $this->resolveAction($verdict, $degraded),
            reasons: $reasons,
            degraded: $degraded,
            apiCalled: $apiCalled,
            apiResponse: $apiResponse,
        );
    }

    /**
     * A broken bundled snapshot must not take the form down: the guard's
     * whole promise is that it never blocks or breaks a signup because of
     * its own infrastructure. Skip the check, warn loudly (a broken
     * snapshot is a packaging defect worth paging on), let syntax,
     * reserved-TLD and the API keep working.
     */
    private function snapshotSaysDisposable(string $domain): bool
    {
        try {
            return $this->snapshot->isDisposable($domain);
        } catch (Exception\SnapshotException $exception) {
            trigger_error('email-guard: disposable check skipped: ' . $exception->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    private function callApi(string $address): array
    {
        $response = $this->transport->post(
            rtrim($this->config['base_url'], '/') . '/v1/verify/single',
            [
                'X-API-Key' => (string) $this->config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'email-guard-core-php/' . self::VERSION,
            ],
            json_encode(['email' => $address], JSON_THROW_ON_ERROR),
            (int) $this->config['timeout_ms'],
        );

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new TransportException('Verify API answered HTTP ' . $response->statusCode);
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new TransportException('Verify API answered with a non-JSON body');
        }

        return $data;
    }

    private function resolveAction(Verdict $verdict, bool $degraded): Action
    {
        if ($degraded) {
            // Degradation bypasses block_on/review_on entirely (spec 6.4):
            // blocking "unknown" is a statement about answered unknowns, not
            // about outages. fail_open alone decides here.
            return $this->config['fail_open'] ? Action::Allow : Action::Deny;
        }
        if (in_array($verdict->value, $this->config['block_on'], true)) {
            return Action::Deny;
        }
        if (in_array($verdict->value, $this->config['review_on'], true)) {
            return Action::Review;
        }

        return Action::Allow;
    }
}
