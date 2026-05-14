<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * SLA Deadline Monitor Status — Sprint 7A F3 Wave 2
 *
 * Lifecycle states for a tracked SLA deadline:
 *  - active:    deadline is running; checkpoints may still fire
 *  - missed:    deadlineAt passed while status was still 'active'
 *  - satisfied: entity action (e.g. authority notification sent) closed the deadline before it was missed
 */
enum SlaDeadlineStatus: string
{
    case Active    = 'active';
    case Missed    = 'missed';
    case Satisfied = 'satisfied';
}
