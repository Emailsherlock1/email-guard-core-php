<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard;

/**
 * Outcome of one guard check (email-guard-spec, section 7).
 */
final class Result
{
    /**
     * @param list<string> $reasons ordered reason codes, possibly empty
     * @param bool $degraded true only when a wanted API call failed
     * @param bool $apiCalled whether the transport was hit at all
     * @param array<string, mixed>|null $apiResponse raw decoded API response,
     *   informational only; it never influences the action
     * @param bool $relay the relay signal (spec 6.5): the address sits on a
     *   relay / forwarding / alias-masking service. Deliverable and reaching a
     *   real person, just masked. Only ever set by the remote check. Inert
     *   unless the integrator lists `relay` in block_on / review_on.
     * @param ?string $relayProvider the service name when $relay is true (e.g.
     *   "SimpleLogin"), null otherwise. Informational.
     * @param bool $spamTrap the spam_trap signal (spec 6.5): the mail host is a
     *   known or suspected spam trap. Unlike relay this says nothing about
     *   deliverability, and a trap accepting mail is the whole problem with it.
     *   Only ever set by the remote check. Inert unless the integrator lists
     *   `spam_trap` in block_on / review_on.
     * @param ?string $spamTrapType how the host was identified when $spamTrap
     *   is true: "known_trap_domain" (curated registry) or
     *   "reputation_heuristic" (inferred, and so the weaker of the two). Null
     *   otherwise. Informational, but worth reading before acting on a deny.
     */
    public function __construct(
        public readonly Verdict $verdict,
        public readonly Action $action,
        public readonly array $reasons,
        public readonly bool $degraded,
        public readonly bool $apiCalled,
        public readonly ?array $apiResponse = null,
        public readonly bool $relay = false,
        public readonly ?string $relayProvider = null,
        public readonly bool $spamTrap = false,
        public readonly ?string $spamTrapType = null,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->action === Action::Allow;
    }

    public function isDenied(): bool
    {
        return $this->action === Action::Deny;
    }

    public function needsReview(): bool
    {
        return $this->action === Action::Review;
    }
}
