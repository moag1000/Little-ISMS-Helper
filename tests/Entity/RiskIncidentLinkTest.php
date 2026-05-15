<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskIncidentLink entity.
 * Sprint 9B / F16.
 */
final class RiskIncidentLinkTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrectOnConstruction(): void
    {
        $link = new RiskIncidentLink();

        self::assertSame('related', $link->getLinkType());
        self::assertNull($link->getId());
        self::assertNull($link->getRisk());
        self::assertNull($link->getIncident());
        self::assertNull($link->getLinkedBy());
        self::assertNull($link->getNotes());
        self::assertInstanceOf(DateTimeImmutable::class, $link->getLinkedAt());
    }

    #[Test]
    public function settersAndGettersRoundTrip(): void
    {
        $tenant   = new Tenant();
        $risk     = new Risk();
        $incident = new Incident();
        $user     = new User();
        $ts       = new DateTimeImmutable('2026-05-17 11:00:00');

        $link = new RiskIncidentLink();
        $link->setTenant($tenant);
        $link->setRisk($risk);
        $link->setIncident($incident);
        $link->setLinkType('materialized');
        $link->setLinkedBy($user);
        $link->setNotes('Confirmed loss event.');
        $link->setLinkedAt($ts);

        self::assertSame($tenant, $link->getTenant());
        self::assertSame($risk, $link->getRisk());
        self::assertSame($incident, $link->getIncident());
        self::assertSame('materialized', $link->getLinkType());
        self::assertSame($user, $link->getLinkedBy());
        self::assertSame('Confirmed loss event.', $link->getNotes());
        self::assertSame($ts, $link->getLinkedAt());
    }

    #[Test]
    public function getLinkTypeLabelReturnsHumanLabel(): void
    {
        $link = new RiskIncidentLink();

        $link->setLinkType('materialized');
        self::assertSame('Materialized', $link->getLinkTypeLabel());

        $link->setLinkType('suspected');
        self::assertSame('Suspected', $link->getLinkTypeLabel());

        $link->setLinkType('mitigation_failed');
        self::assertSame('Mitigation Failed', $link->getLinkTypeLabel());

        $link->setLinkType('related');
        self::assertSame('Related', $link->getLinkTypeLabel());

        // Unknown type falls through to default 'Related'
        $link->setLinkType('unknown_type');
        self::assertSame('Related', $link->getLinkTypeLabel());
    }

    #[Test]
    public function notesCanBeNull(): void
    {
        $link = new RiskIncidentLink();
        $link->setNotes(null);
        self::assertNull($link->getNotes());
    }

    #[Test]
    public function linkedByCanBeNull(): void
    {
        $link = new RiskIncidentLink();
        $link->setLinkedBy(null);
        self::assertNull($link->getLinkedBy());
    }
}
