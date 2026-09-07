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
    public const VERSION = '0.5.0';
    public const SPEC_VERSION = '1.4.0';

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
        private readonly ?GuardReporter $reporter = null,
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

        // Domain for telemetry (section 11): null when there is no @
        // (syntax failed). Reused by the reserved-TLD / disposable checks.
        $atPosition = strrpos($address, '@');
        $domain = $atPosition !== false ? strtolower(substr($address, $atPosition + 1)) : null;
        if ($domain === '') {
            $domain = null;
        }

        $verdict = null;
        $reasons = [];

        if (!SyntaxChecker::isValid($address)) {
            $verdict = Verdict::Invalid;
            $reasons = [Reason::BAD_SYNTAX];
        } elseif ($domain !== null) {
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
        // Signals (spec 6.5): boolean response properties that overlay the
        // verdict. Only the remote check can set them; a local verdict or a
        // degraded check leaves every signal false. Keyed by signal name, in
        // the spec-table order Signal::FIELDS carries.
        /** @var array<string, bool> */
        $signals = array_fill_keys(Signal::names(), false);
        /** @var array<string, string|null> */
        $signalDetails = array_fill_keys(Signal::names(), null);

        if ($verdict === null && $this->config['api_key'] !== null) {
            $apiCalled = true;
            try {
                $apiResponse = $this->callApi($address);
                $verdict = Verdict::tryFrom((string) ($apiResponse['result'] ?? '')) ?? Verdict::Unknown;
                $reason = $apiResponse['reason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $reasons[] = $reason;
                }
                [$signals, $signalDetails] = self::readSignals($apiResponse);
            } catch (TransportException $exception) {
                $verdict = Verdict::Unknown;
                // api_unavailable is the stable umbrella for any degraded
                // check (spec 5.2); the attributed cause rides after it so
                // telemetry can separate a slow API from an errored one.
                $reasons = [Reason::API_UNAVAILABLE];
                $granular = self::degradedReason($exception);
                if ($granular !== null) {
                    $reasons[] = $granular;
                }
                $degraded = true;
            }
        }

        $verdict ??= Verdict::Unknown;

        // An active signal (true AND listed in block_on/review_on) appends its
        // reason so telemetry can attribute the decision, even when a verdict
        // independently produced the same action (spec 6.5). A signal's reason
        // IS its name, and the append order is Signal::FIELDS order, which the
        // vectors compare as a sequence.
        foreach ($signals as $name => $isSet) {
            if ($isSet && $this->signalListed($name)) {
                $reasons[] = $name;
            }
        }

        $result = new Result(
            verdict: $verdict,
            action: $this->resolveAction($verdict, $degraded, $signals),
            reasons: $reasons,
            degraded: $degraded,
            apiCalled: $apiCalled,
            apiResponse: $apiResponse,
            relay: $signals[Signal::RELAY],
            relayProvider: $signalDetails[Signal::RELAY],
            spamTrap: $signals[Signal::SPAM_TRAP],
            spamTrapType: $signalDetails[Signal::SPAM_TRAP],
        );

        // Telemetry (section 11): record the decision for later batched
        // reporting. No-op without a configured reporter/key; the reporter
        // owns the fail-silent, domain-only contract.
        $this->reporter?->record($result, $domain);

        return $result;
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
            throw new TransportException(
                'Verify API answered HTTP ' . $response->statusCode,
                TransportException::KIND_HTTP,
                $response->statusCode,
            );
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new TransportException(
                'Verify API answered with a non-JSON body',
                TransportException::KIND_BAD_RESPONSE,
            );
        }

        return $data;
    }

    /**
     * Maps a transport failure to its granular degraded reason (spec 5.2), or
     * null when the cause cannot be attributed (then only `api_unavailable`
     * stands). A non-2xx outside the 4xx/5xx buckets (a stray 1xx/3xx) is left
     * unattributed rather than minting an out-of-band reserved name.
     */
    private static function degradedReason(TransportException $exception): ?string
    {
        return match ($exception->kind) {
            TransportException::KIND_TIMEOUT => Reason::API_TIMEOUT,
            TransportException::KIND_CONNECTION => Reason::API_UNREACHABLE,
            TransportException::KIND_BAD_RESPONSE => Reason::API_BAD_RESPONSE,
            TransportException::KIND_HTTP => match (true) {
                $exception->statusCode === null => null,
                $exception->statusCode >= 500 => Reason::API_HTTP_5XX,
                $exception->statusCode >= 400 => Reason::API_HTTP_4XX,
                default => null,
            },
            default => null,
        };
    }

    /**
     * Reads every signal (spec 6.5) out of one API response.
     *
     * A signal is true only on a literal boolean true, so a missing field, a
     * null, or a truthy string never fires it: an API version that predates a
     * signal must read as "not set", never as "set".
     *
     * The detail field rides along informationally and is kept only when its
     * signal is true, which is what {@see Result} promises about
     * relay_provider / spam_trap_type. A detail without its signal would be a
     * value nothing in the decision path can explain.
     *
     * @param array<string, mixed> $response
     *
     * @return array{array<string, bool>, array<string, string|null>} signals, details
     */
    private static function readSignals(array $response): array
    {
        $signals = [];
        $details = [];
        foreach (Signal::FIELDS as $name => [$sourceField, $detailField]) {
            $signals[$name] = ($response[$sourceField] ?? null) === true;
            $detail = $response[$detailField] ?? null;
            $details[$name] = $signals[$name] && is_string($detail) && $detail !== ''
                ? $detail
                : null;
        }

        return [$signals, $details];
    }

    /**
     * @param array<string, bool> $signals every known signal, true when the
     *   response set it
     */
    private function resolveAction(Verdict $verdict, bool $degraded, array $signals): Action
    {
        if ($degraded) {
            // Degradation bypasses block_on/review_on entirely (spec 6.4):
            // blocking "unknown" is a statement about answered unknowns, not
            // about outages. fail_open alone decides here. Signals never fire on
            // a degraded check (all false), so they are moot here too.
            return $this->config['fail_open'] ? Action::Allow : Action::Deny;
        }
        // A verdict OR any signal the response set can deny; deny beats review,
        // and two active signals still produce one action (spec 6.4).
        if (self::policyMatches($verdict, $signals, $this->config['block_on'])) {
            return Action::Deny;
        }
        if (self::policyMatches($verdict, $signals, $this->config['review_on'])) {
            return Action::Review;
        }

        return Action::Allow;
    }

    /**
     * True when one policy list (block_on or review_on) names the verdict or a
     * signal the response set. An unknown token matches nothing and is not an
     * error (spec 6.3).
     *
     * @param array<string, bool> $signals
     * @param list<string> $policy
     */
    private static function policyMatches(Verdict $verdict, array $signals, array $policy): bool
    {
        if (in_array($verdict->value, $policy, true)) {
            return true;
        }
        foreach ($signals as $name => $isSet) {
            if ($isSet && in_array($name, $policy, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the named signal (spec 6.5) appears in block_on or review_on.
     * Listing decides whether an active signal appends its reason; which of the
     * two lists it is in is {@see self::resolveAction}'s business.
     */
    private function signalListed(string $signal): bool
    {
        return in_array($signal, $this->config['block_on'], true)
            || in_array($signal, $this->config['review_on'], true);
    }
}
