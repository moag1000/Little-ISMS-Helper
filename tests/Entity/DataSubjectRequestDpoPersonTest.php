<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DataSubjectRequest;
use App\Entity\Person;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B2 — DataSubjectRequest gains a `dpoPerson`
 * Person FK as the governance-side Data Protection Officer accountable
 * for the DSR, distinct from `assignedTo` (action handler, kept).
 *
 * `getEffectiveDpoName()` prefers the new Person FK and falls back to
 * the assignment User's full name (single-DPO orgs commonly assign the
 * action to the DPO, so the fallback gives sensible defaults).
 */
final class DataSubjectRequestDpoPersonTest extends TestCase
{
    #[Test]
    public function effectiveAccessorPrefersPerson(): void
    {
        $assignee = new User();
        $assignee->setFirstName('Action');
        $assignee->setLastName('Handler');

        $dpo = new Person();
        $dpo->setFullName('External DPO Consultant');

        $dsr = new DataSubjectRequest();
        $dsr->setAssignedTo($assignee);
        $dsr->setDpoPerson($dpo);

        self::assertSame($dpo, $dsr->getDpoPerson());
        self::assertSame('External DPO Consultant', $dsr->getEffectiveDpoName());
    }

    #[Test]
    public function effectiveAccessorFallsBackToAssignedToUserWhenPersonNull(): void
    {
        $assignee = new User();
        $assignee->setFirstName('Internal');
        $assignee->setLastName('DPO');

        $dsr = new DataSubjectRequest();
        $dsr->setAssignedTo($assignee);

        self::assertNull($dsr->getDpoPerson());
        self::assertSame('Internal DPO', $dsr->getEffectiveDpoName());
    }

    #[Test]
    public function bothNullReturnsNull(): void
    {
        $dsr = new DataSubjectRequest();

        self::assertNull($dsr->getDpoPerson());
        self::assertNull($dsr->getEffectiveDpoName());
    }
}
