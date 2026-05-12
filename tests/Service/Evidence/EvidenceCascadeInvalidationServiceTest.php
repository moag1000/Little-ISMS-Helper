<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Entity\Control;
use App\Entity\DocumentVersion;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Repository\EvidenceReverificationTaskRepository;
use App\Service\AuditLogger;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F4 — EvidenceCascadeInvalidationService unit tests.
 *
 * Focus: cascade-invalidation correctness — when a new DocumentVersion is published
 * for a document that is evidence on one or more controls, those controls must have
 * evidenceOutdated set to true and reverification tasks must be created.
 */
class EvidenceCascadeInvalidationServiceTest extends TestCase
{
    private EvidenceCascadeInvalidationService $service;
    private ControlRepository $controlRepo;
    private ComplianceRequirementFulfillmentRepository $crfRepo;
    private EvidenceReverificationTaskRepository $taskRepo;
    private AuditLogger $auditLogger;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->controlRepo = $this->createMock(ControlRepository::class);
        $this->crfRepo = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->taskRepo = $this->createMock(EvidenceReverificationTaskRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new EvidenceCascadeInvalidationService(
            $this->em,
            $this->controlRepo,
            $this->crfRepo,
            $this->taskRepo,
            $this->auditLogger,
        );
    }

    #[Test]
    public function testInvalidateReturnsZeroCountsWhenNoDocumentOrTenant(): void
    {
        $version = new DocumentVersion();
        // No document or tenant set

        $result = $this->service->invalidate($version);

        self::assertSame(0, $result['controls']);
        self::assertSame(0, $result['fulfillments']);
        self::assertSame(0, $result['tasks']);
    }

    #[Test]
    public function testInvalidateWithVersionWithoutDocumentReturnsZeroCounts(): void
    {
        // A version with no document set — the service should handle this gracefully
        $version = new DocumentVersion();
        // No document or tenant set on version

        $this->em->expects($this->never())->method('flush');
        $this->auditLogger->expects($this->never())->method('logBulk');

        $result = $this->service->invalidate($version);

        self::assertSame(0, $result['controls']);
        self::assertSame(0, $result['fulfillments']);
        self::assertSame(0, $result['tasks']);
    }

    #[Test]
    public function testMarkControlReverifiedSetsEvidenceOutdatedFalse(): void
    {
        $control = new Control();
        $control->setEvidenceOutdated(true);

        $this->em->expects($this->once())->method('flush');
        $this->auditLogger->expects($this->once())->method('logCustom');

        $this->service->markControlReverified($control);

        self::assertFalse($control->isEvidenceOutdated());
    }

    #[Test]
    public function testMarkFulfillmentReverifiedSetsEvidenceOutdatedFalse(): void
    {
        $fulfillment = new \App\Entity\ComplianceRequirementFulfillment();
        $fulfillment->setEvidenceOutdated(true);

        $this->em->expects($this->once())->method('flush');
        $this->auditLogger->expects($this->once())->method('logCustom');

        $this->service->markFulfillmentReverified($fulfillment);

        self::assertFalse($fulfillment->isEvidenceOutdated());
    }

}
