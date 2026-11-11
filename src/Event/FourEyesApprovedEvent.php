<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * S34: a four-eyes request was approved by a second person. A request type whose
 * change waits for the approval (mapping override) applies it on this event, so
 * nothing is written before the counter-signature.
 */
final class FourEyesApprovedEvent extends Event
{
    public function __construct(
        public readonly FourEyesApprovalRequest $request,
        public readonly User $approver,
    ) {
    }
}
