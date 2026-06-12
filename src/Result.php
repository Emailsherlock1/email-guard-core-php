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
     */
    public function __construct(
        public readonly Verdict $verdict,
        public readonly Action $action,
        public readonly array $reasons,
        public readonly bool $degraded,
        public readonly bool $apiCalled,
        public readonly ?array $apiResponse = null,
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
