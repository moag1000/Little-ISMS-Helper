<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Service\ProcessingActivityService;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059: the auto-DPIA suggestion used to flush from postPersist. The
 * re-entrant commit failed (no reference number), closed the EntityManager
 * and was swallowed: no DPIA, the activity's audit entry lost, and every
 * later write of the request failed. Real database, real listener chain.
 */
final class AutoReactionDpiaSuggestListenerDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ?Tenant $tenant = null;
    private int $auditLogHighWater = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $uid = uniqid('', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Auto DPIA ' . $uid);
        $this->tenant->setCode('adpia_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant);

        $this->auditLogHighWater = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $this->conn->executeStatement('DELETE FROM data_protection_impact_assessment WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM processing_activity WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM audit_log WHERE id > ?', [$this->auditLogHighWater]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function highRiskActivityGetsExactlyOneDpiaAndTheRequestKeepsWorking(): void
    {
        $service = self::getContainer()->get(ProcessingActivityService::class);

        $activity = new ProcessingActivity();
        $activity->setName('Payroll ' . uniqid());
        $activity->setPurposes(['Payroll processing']);
        $activity->setDataSubjectCategories(['employees']); // vulnerable category → high risk
        $activity->setPersonalDataCategories(['contact_data', 'bank_data']);
        $activity->setLegalBasis('contract');
        $service->create($activity);
        $id = (int) $activity->getId();

        self::assertTrue($this->em->isOpen(), 'the EntityManager stays open');
        $dpias = $this->conn->fetchAllAssociative(
            'SELECT reference_number, status FROM data_protection_impact_assessment WHERE processing_activity_id = ?',
            [$id],
        );
        self::assertCount(1, $dpias, 'exactly one DPIA suggestion');
        self::assertMatchesRegularExpression('/^DPIA-\d{4}-\d+$/', (string) $dpias[0]['reference_number']);
        self::assertSame('draft', $dpias[0]['status']);
        self::assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE id > ? AND entity_type = 'ProcessingActivity' AND entity_id = ? AND action = 'create'",
            [$this->auditLogHighWater, $id],
        ), "the activity's own audit entry is written");

        // A further write in the same request works and does not add a second DPIA.
        $activity->setName($activity->getName() . ' (updated)');
        $service->update($activity);
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM data_protection_impact_assessment WHERE processing_activity_id = ?',
            [$id],
        ));
        self::assertSame(
            $activity->getName(),
            $this->conn->fetchOne('SELECT name FROM processing_activity WHERE id = ?', [$id]),
        );

        $this->em->clear();
        $dpia = $this->em->getRepository(DataProtectionImpactAssessment::class)->findOneBy(['processingActivity' => $id]);
        self::assertInstanceOf(DataProtectionImpactAssessment::class, $dpia);
        self::assertSame($this->tenant?->getId(), $dpia->getTenant()?->getId());
    }

    /**
     * #1059 re-review N-2: the skeleton holds only what the activity states;
     * the assessment is empty, so it is honestly incomplete — listed as
     * incomplete, refused by the submit gate, not counted as complete.
     */
    #[Test]
    public function autoSuggestedSkeletonIsIncompleteEverywhere(): void
    {
        $container = self::getContainer();
        $activity = new ProcessingActivity();
        $activity->setName(substr('Payroll ' . uniqid() . ' ' . str_repeat('n', 255), 0, 255)); // DPIA title would exceed 255
        $activity->setPurposes(['Payroll processing']);
        $activity->setDataSubjectCategories(['employees']);
        $activity->setPersonalDataCategories(['bank_data']);
        $activity->setLegalBasis(''); // NOT NULL column, NotBlank only in forms (imports / API)
        $container->get(ProcessingActivityService::class)->create($activity);

        $repository = $container->get(\App\Repository\DataProtectionImpactAssessmentRepository::class);
        $dpia = $repository->findOneBy(['processingActivity' => $activity]);
        self::assertInstanceOf(DataProtectionImpactAssessment::class, $dpia, 'an over-long title / empty legal basis does not break the insert');
        self::assertSame(255, mb_strlen((string) $dpia->getTitle()));
        self::assertSame('', $dpia->getLegalBasis());
        self::assertSame('Payroll processing', $dpia->getProcessingPurposes(), 'derived from the activity');
        self::assertSame('', $dpia->getNecessityAssessment(), 'no placeholder text');
        self::assertSame('', $dpia->getRiskLevel());

        self::assertFalse($dpia->isComplete());
        self::assertLessThan(100, $dpia->getCompletenessPercentage());
        self::assertContains(
            $dpia->getId(),
            array_map(static fn (DataProtectionImpactAssessment $d): ?int => $d->getId(), $repository->findIncomplete($this->tenant)),
        );

        $dpiaService = $container->get(\App\Service\DataProtectionImpactAssessmentService::class);
        $score = $dpiaService->calculateComplianceScore();
        self::assertSame(0, $score['complete_dpias'], 'the skeleton does not count as complete');
        self::assertSame(0, $repository->getStatistics($this->tenant)['completeness_rate']);

        try {
            $dpiaService->submitForReview($dpia);
            self::fail('an untouched skeleton must not pass the submit gate');
        } catch (\App\Exception\BusinessRule\BusinessRuleException $e) {
            self::assertStringContainsString('complete', $e->getMessage());
        }
        self::assertSame('draft', $dpia->getStatus());
    }
}
