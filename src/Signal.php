<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * The signal vocabulary (email-guard-spec, section 6.5).
 *
 * A signal is a boolean property of the remote response that overlays the
 * verdict instead of replacing it, and that an integrator can place in
 * `block_on` / `review_on` next to verdicts. Signal names are reserved by the
 * spec, so this list is the whole vocabulary: an implementation must not mint
 * names of its own, or an integrator's `block_on` entry would mean different
 * things in different languages.
 *
 * Everything that decides on a signal reads {@see self::FIELDS}. Adding the
 * next signal is a row here plus its two properties on {@see Result}, not a
 * new branch in {@see EmailGuard}.
 */
final class Signal
{
    /** The address sits on a relay / forwarding / alias-masking service (spec 1.2.0). */
    public const RELAY = 'relay';

    /** The mail host is a known or suspected spam trap (spec 1.4.0). */
    public const SPAM_TRAP = 'spam_trap';

    /**
     * Signal name => [boolean source field, detail field] in the API response.
     *
     * The array order is load-bearing: it is the order active signals append
     * their reasons (spec 6.4), and the conformance vectors compare reason
     * lists as sequences. Sorting or regrouping this array changes behavior.
     *
     * The detail field is informational and never influences the action
     * (`relay_provider` names the service, `spam_trap_type` says how the host
     * was identified).
     *
     * @var array<string, array{string, string}>
     */
    public const FIELDS = [
        self::RELAY => ['relay', 'relay_provider'],
        self::SPAM_TRAP => ['spam_trap', 'spam_trap_type'],
    ];

    /**
     * Every signal name this library knows, in spec-table order.
     *
     * Public because the spec (6.3) asks implementations to expose it: an
     * unknown token in `block_on` / `review_on` is inert by design, so a typo
     * and a signal the installed version predates look identical from the
     * config alone. This list is how an integrator tells them apart.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::FIELDS);
    }

    private function __construct()
    {
    }
}
