<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Service\ManagementReviewAutoCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ISO 27001 Cl. 9.3 auto-collect moved out of ManagementReportService;
 * this pins that the review is still persisted with the §9.3 narratives.
 */
#[Group('integration')]
final class ManagementReviewAutoCollectorTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function createsAPersistedReviewWithTheClause93Narratives(): void
    {
        $suffix = substr(uniqid(), -6);
        $tenant = new Tenant();
        $tenant->setName('AutoCollect ' . $suffix);
        $tenant->setCode('ac_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);
        $this->em->flush();

        $collector = self::getContainer()->get(ManagementReviewAutoCollector::class);
        $review = $collector->createManagementReviewFromReport($tenant, new \DateTimeImmutable('2026-09-30'), 'en');

        self::assertNotNull($review->getId());
        self::assertSame($tenant, $review->getTenant());
        self::assertSame('2026-09-30', $review->getReviewDate()?->format('Y-m-d'));
        self::assertStringStartsWith('Risk landscape', (string) $review->getRisksReview());
        self::assertStringStartsWith('Audit results', (string) $review->getAuditResults());
        self::assertStringStartsWith('Non-conformities status', (string) $review->getNonConformitiesStatus());
        self::assertStringStartsWith('Corrective actions status', (string) $review->getCorrectiveActionsStatus());
        self::assertStringStartsWith('Context changes', (string) $review->getChangesRelevantToISMS());
        self::assertInstanceOf(ManagementReview::class, $this->em->find(ManagementReview::class, $review->getId()));
    }

    /**
     * The "audit results" and "non-conformities" narratives read
     * audit_data keys the report never produced (always 0). They now carry
     * the audit KPI's figures — planned / completed audits of this calendar
     * year, open findings as backlog — for the review's organisation only.
     */
    #[Test]
    public function auditNarrativesShowTheOrganisationsAuditFigures(): void
    {
        $tenant = $this->tenant('own');
        $other = $this->tenant('other');
        $this->audit($tenant, 'completed', [\App\Enum\AuditFindingStatus::Open, \App\Enum\AuditFindingStatus::Closed]);
        $this->audit($tenant, 'planned', []);
        $this->audit($other, 'completed', [\App\Enum\AuditFindingStatus::Open]);
        $this->em->flush();

        $collector = self::getContainer()->get(ManagementReviewAutoCollector::class);
        $review = $collector->createManagementReviewFromReport($tenant, new \DateTimeImmutable('today'), 'en');

        $auditResults = (string) $review->getAuditResults();
        self::assertStringContainsString('Completed audits (this year): 1', $auditResults);
        self::assertStringContainsString('Planned audits (this year): 2', $auditResults);
        $nc = (string) $review->getNonConformitiesStatus();
        self::assertStringContainsString('Total: 2 findings', $nc);
        self::assertStringContainsString('Open: 1', $nc);
    }

    private function tenant(string $label): Tenant
    {
        $suffix = substr(uniqid(), -6);
        $tenant = (new Tenant())->setName('Audit ' . $label . ' ' . $suffix)->setCode('aud_' . $label . '_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }

    /**
     * @param list<\App\Enum\AuditFindingStatus> $findingStatuses
     */
    private function audit(Tenant $tenant, string $status, array $findingStatuses): void
    {
        $audit = new \App\Entity\InternalAudit();
        $audit->setTenant($tenant);
        $audit->setAuditNumber('A-' . substr(uniqid(), -8));
        $audit->setTitle('Audit ' . $status);
        $audit->setPlannedDate(new \DateTime());
        $audit->setStatus($status); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $this->em->persist($audit);
        foreach ($findingStatuses as $i => $findingStatus) {
            $finding = (new \App\Entity\AuditFinding())->setStatus($findingStatus);
            $finding->setTenant($tenant);
            $finding->setTitle('F' . $i);
            $finding->setFindingNumber('F-' . substr(uniqid(), -8) . $i);
            $finding->setDescription('Finding ' . $i);
            $audit->addStructuredFinding($finding);
            $this->em->persist($finding);
        }
    }
}
