<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentStatus;
use App\Repository\RiskIncidentLinkRepository;
use App\Service\AuditLogger;
use App\Service\Risk\RiskIncidentLinkService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\InvalidArgument\InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskIncidentLinkService.
 * Sprint 9B / F16.
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskIncidentLinkServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private RiskIncidentLinkRepository $repo;
    private AuditLogger $auditLogger;
    private RiskIncidentLinkService $service;
    private Tenant $tenant;
    private Risk $risk;
    private Incident $incident;

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->repo        = $this->createMock(RiskIncidentLinkRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new RiskIncidentLinkService($this->em, $this->repo, $this->auditLogger);

        $this->tenant   = new Tenant();
        $this->risk     = new Risk();
        $this->risk->setTenant($this->tenant);
        $this->incident = new Incident();
    }

    #[Test]
    public function linkCreatesNewLinkWhenNoneExists(): void
    {
        $this->repo->method('findOneByRiskAndIncident')->willReturn(null);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $link = $this->service->link($this->risk, $this->incident, 'materialized', null, 'Confirmed.');

        self::assertInstanceOf(RiskIncidentLink::class, $link);
        self::assertSame('materialized', $link->getLinkType());
        self::assertSame('Confirmed.', $link->getNotes());
    }

    #[Test]
    public function linkIsIdempotentWhenAlreadyExists(): void
    {
        $existing = new RiskIncidentLink();
        $existing->setLinkType('related');

        $this->repo->method('findOneByRiskAndIncident')->willReturn($existing);
        $this->em->expects(self::never())->method('persist');

        $result = $this->service->link($this->risk, $this->incident, 'materialized', null, null);

        self::assertSame($existing, $result);
        self::assertSame('related', $result->getLinkType(), 'Existing link type must not be overwritten');
    }

    #[Test]
    public function linkThrowsOnInvalidLinkType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->link($this->risk, $this->incident, 'invalid_type', null, null);
    }

    #[Test]
    public function unlinkRemovesExistingLink(): void
    {
        $link = new RiskIncidentLink();
        $this->repo->method('findOneByRiskAndIncident')->willReturn($link);
        $this->em->expects(self::once())->method('remove')->with($link);
        $this->em->expects(self::once())->method('flush');

        $this->service->unlink($this->risk, $this->incident);
    }

    #[Test]
    public function unlinkIsNoOpWhenNoLinkExists(): void
    {
        $this->repo->method('findOneByRiskAndIncident')->willReturn(null);
        $this->em->expects(self::never())->method('remove');

        $this->service->unlink($this->risk, $this->incident);
    }

    #[Test]
    public function suggestRiskUpdateReturnsEmptyWhenIncidentNotClosed(): void
    {
        $this->incident->setStatus(IncidentStatus::InResolution);

        $result = $this->service->suggestRiskUpdateOnIncidentClose($this->incident);

        self::assertSame([], $result);
    }

    #[Test]
    public function suggestRiskUpdateReturnsEmptyWhenNoLinksExist(): void
    {
        $this->incident->setStatus(IncidentStatus::Closed);
        $this->repo->method('findByIncident')->willReturn([]);

        $result = $this->service->suggestRiskUpdateOnIncidentClose($this->incident);

        self::assertSame([], $result);
    }

    #[Test]
    public function suggestRiskUpdateReturnsLinkedRisksWhenIncidentClosed(): void
    {
        $this->incident->setStatus(IncidentStatus::Closed);

        $link1 = new RiskIncidentLink();
        $link1->setRisk($this->risk);
        $link1->setLinkType('materialized');

        $risk2 = new Risk();
        $link2 = new RiskIncidentLink();
        $link2->setRisk($risk2);
        $link2->setLinkType('related');

        $this->repo->method('findByIncident')->willReturn([$link1, $link2]);

        $result = $this->service->suggestRiskUpdateOnIncidentClose($this->incident);

        self::assertCount(2, $result);
        self::assertContains($this->risk, $result);
        self::assertContains($risk2, $result);
    }

    #[Test]
    public function validLinkTypesConstantContainsExpectedValues(): void
    {
        self::assertContains('materialized', RiskIncidentLinkService::VALID_LINK_TYPES);
        self::assertContains('suspected', RiskIncidentLinkService::VALID_LINK_TYPES);
        self::assertContains('related', RiskIncidentLinkService::VALID_LINK_TYPES);
        self::assertContains('mitigation_failed', RiskIncidentLinkService::VALID_LINK_TYPES);
        self::assertCount(4, RiskIncidentLinkService::VALID_LINK_TYPES);
    }
}
