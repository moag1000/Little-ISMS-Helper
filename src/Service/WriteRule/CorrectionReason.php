<?php

declare(strict_types=1);

namespace App\Service\WriteRule;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * S28 (F-06): why a manager corrects a create-only value (`#[CreateOnly]`: when an
 * incident or a data breach became known, the risk a treatment plan belongs to).
 *
 * The reason is part of the correction like the reason of a lifecycle step with
 * `reason_required`: without one {@see \App\EventListener\WriteRuleGuard} refuses the
 * flush, with one it writes an audit-log entry carrying it. The web form fills it from
 * its reason field ({@see \App\Form\Extension\WriteRuleFormExtension}); REST and the
 * assistant (MCP) send it as the `X-Correction-Reason` header of the request.
 * Request-local: reset after every request and every worker message.
 */
final class CorrectionReason implements ResetInterface
{
    public const string HEADER = 'X-Correction-Reason';
    public const int MAX_LENGTH = 1000;

    private ?string $reason = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(?string $reason): void
    {
        $this->reason = self::clean($reason);
    }

    /** The reason given for this request, or null when there is none. */
    public function current(): ?string
    {
        return $this->reason ?? self::clean($this->requestStack->getMainRequest()?->headers->get(self::HEADER));
    }

    public function reset(): void
    {
        $this->reason = null;
    }

    private static function clean(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : mb_substr($reason, 0, self::MAX_LENGTH);
    }
}
