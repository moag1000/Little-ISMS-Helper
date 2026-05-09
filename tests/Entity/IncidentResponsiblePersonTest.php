<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Incident;
use App\Entity\Person;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B2 — Incident gains a `responsiblePerson` Person
 * FK as the long-term governance owner, distinct from `assignedTo`
 * (action ticket assignee, kept as legacy string field) and
 * `reportedBy*` (audit-trail / first reporter).
 *
 * The new accessor `getEffectiveResponsiblePersonName()` prefers the
 * Person and falls back to the legacy `assignedTo` string when the
 * Person FK is not set.
 */
final class IncidentResponsiblePersonTest extends TestCase
{
    #[Test]
    public function effectiveAccessorPrefersPerson(): void
    {
        $person = new Person();
        $person->setFullName('Externer CISO');

        $incident = new Incident();
        $incident->setAssignedTo('alice@example.com');
        $incident->setResponsiblePerson($person);

        self::assertSame('Externer CISO', $incident->getEffectiveResponsiblePersonName());
        self::assertSame($person, $incident->getResponsiblePerson());
    }

    #[Test]
    public function effectiveAccessorFallsBackToAssignedToWhenPersonNull(): void
    {
        $incident = new Incident();
        $incident->setAssignedTo('Bob Builder');

        self::assertNull($incident->getResponsiblePerson());
        self::assertSame('Bob Builder', $incident->getEffectiveResponsiblePersonName());
    }

    #[Test]
    public function bothNullReturnsNull(): void
    {
        $incident = new Incident();

        self::assertNull($incident->getResponsiblePerson());
        self::assertNull($incident->getEffectiveResponsiblePersonName());
    }
}
