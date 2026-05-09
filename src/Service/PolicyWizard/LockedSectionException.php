<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\DocumentSection;
use App\Entity\User;
use RuntimeException;

/**
 * W6-A §0.A.4 — thrown by
 * {@see PolicySectionApprovalService::assertSectionEditable} when a CISO
 * (or any non-DPO actor) attempts to edit a privacy section that has
 * already received DPO sign-off and is therefore locked. The DPO MAY
 * re-open the section by editing it themselves; that path clears the
 * lock and reverts the status back to `dpo_sign_off`.
 *
 * The exception carries the section + the offending editor so the
 * controller can surface a precise translator message and emit the
 * `dpo_section_edit_blocked` audit event.
 */
final class LockedSectionException extends RuntimeException
{
    public function __construct(
        public readonly DocumentSection $section,
        public readonly User $editor,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Section #%d is locked after DPO sign-off; CISO/Top-Mgmt may not edit it.',
            $section->getId() ?? 0,
        ));
    }
}
