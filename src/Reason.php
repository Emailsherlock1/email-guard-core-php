<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * Reason codes produced by the local checks (email-guard-spec, section 6.2).
 * Reasons from the Verify API pass through verbatim and are not enumerated
 * here; the spec requires unrecognized strings to survive untouched.
 */
final class Reason
{
    public const BAD_SYNTAX = 'bad_syntax';
    public const RESERVED_TLD = 'reserved_tld';
    public const DISPOSABLE_PROVIDER = 'disposable_provider';

    /**
     * Umbrella reason for any degraded check (spec 5.2). Always present when a
     * wanted API call failed, regardless of cause. The granular reasons below
     * are appended after it when the failure can be attributed.
     */
    public const API_UNAVAILABLE = 'api_unavailable';

    /** Total or connect timeout budget exceeded (spec 5.2). */
    public const API_TIMEOUT = 'api_timeout';

    /** DNS, TLS, or connection failure before any HTTP status (spec 5.2). */
    public const API_UNREACHABLE = 'api_unreachable';

    /** The API answered a 4xx status: bad key, rate limit, … (spec 5.2). */
    public const API_HTTP_4XX = 'api_http_4xx';

    /** The API answered a 5xx status (spec 5.2). */
    public const API_HTTP_5XX = 'api_http_5xx';

    /** A 2xx answer whose body was not the expected JSON (spec 5.2). */
    public const API_BAD_RESPONSE = 'api_bad_response';

    /**
     * Appended when the `relay` signal is active (spec 6.5). Not a local-check
     * reason: it rides the result's reason list so telemetry can attribute a
     * relay-driven decision.
     *
     * A signal reason is the signal's own name, so these two constants are
     * deliberately equal to {@see Signal::RELAY} / {@see Signal::SPAM_TRAP}
     * rather than derived from them: this class is the reason vocabulary and
     * {@see Signal} the signal vocabulary, and a caller reading a reason list
     * should not have to know that one is spelled from the other.
     */
    public const RELAY = Signal::RELAY;

    /**
     * Appended when the `spam_trap` signal is active (spec 6.5): the mail host
     * is a known or suspected spam trap. Sending to it damages the sender's
     * reputation, which is why the address accepting mail is not reassuring.
     */
    public const SPAM_TRAP = Signal::SPAM_TRAP;

    private function __construct()
    {
    }
}
