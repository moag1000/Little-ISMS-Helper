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
}
