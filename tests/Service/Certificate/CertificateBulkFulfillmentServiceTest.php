<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Entity\CertificateCoverageRule;
use App\Entity\ComplianceCertificate;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Certificate\CertificateBulkFulfillmentService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for {@see CertificateBulkFulfillmentService}.
 *
 * Requires a real database (APP_ENV=test with configured DATABASE_URL).
 * Each test is wrapped in a transaction that is rolled back in tearDown().
 */
#[Group('integration')]
class CertificateBulkFulfillmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private CertificateBulkFulfillmentService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(CertificateBulkFulfillmentService::class);

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
    public function applyMarksCoveredRequirementsVerifiedWithEvidenceAndAudit(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $framework = $this->createFramework('TESTFW');
        $r1 = $this->createRequirement($framework, 'R1');
        $r2 = $this->createRequirement($framework, 'R2');

        $rule = new CertificateCoverageRule();
        $rule->setFrameworkCode('TESTFW')
            ->setRequiredClass(null)
            ->setRequiredScopeTags([])
            ->setRequirementIds(['R1', 'R2'])
            ->setActive(true);
        $this->em->persist($rule);

        $doc = $this->createDocument($tenant, $user);

        $validUntil = new DateTimeImmutable('+2 years');
        $cert = new ComplianceCertificate();
        $cert->setTenant($tenant)
            ->setFrameworkCode('TESTFW')
            ->setCertBody('Test Cert Body')
            ->setCertNumber('CERT-12345')
            ->setCertificateDocument($doc)
            ->setValidUntil($validUntil)
            ->setStatus('active');
        $this->em->persist($cert);

        $this->em->flush();

        $auditBefore = $this->countBulkAuditRows();

        // ── First apply ──────────────────────────────────────────────────────
        $stats = $this->service->apply($cert, $user);

        self::assertSame(2, $stats['fulfilled']);
        self::assertFalse($stats['isFallback']);

        $fulfillments = $this->repo()->findBy([
            'tenant' => $tenant,
            'requirement' => [$r1, $r2],
        ]);
        self::assertCount(2, $fulfillments);

        foreach ($fulfillments as $f) {
            self::assertInstanceOf(ComplianceRequirementFulfillment::class, $f);
            self::assertSame(100, $f->getFulfillmentPercentage());
            self::assertSame(ComplianceRequirementFulfillmentStatus::Verified, $f->getStatusEnum());
            self::assertNotNull($f->getVerifiedAt());
            self::assertSame($user->getId(), $f->getVerifiedBy()?->getId());
            self::assertFalse($f->isEvidenceOutdated());
            self::assertTrue($f->getEvidenceDocuments()->contains($doc));
            self::assertCount(1, $f->getEvidenceDocuments());
            self::assertNotNull($f->getNextReviewDate());
            self::assertSame(
                $validUntil->format('Y-m-d'),
                $f->getNextReviewDate()->format('Y-m-d'),
            );
        }

        $auditAfterFirst = $this->countBulkAuditRows();
        self::assertGreaterThan($auditBefore, $auditAfterFirst, 'A bulk audit batch must be written.');

        // ── Second apply: idempotent ─────────────────────────────────────────
        $statsSecond = $this->service->apply($cert, $user);
        self::assertSame(2, $statsSecond['fulfilled']);

        $this->em->clear();

        $fulfillmentsAfter = $this->repo()->findBy([
            'tenant' => $tenant->getId(),
            'requirement' => [$r1->getId(), $r2->getId()],
        ]);
        // Still exactly two fulfillment rows — no duplicates.
        self::assertCount(2, $fulfillmentsAfter);

        foreach ($fulfillmentsAfter as $f) {
            // Evidence document still attached exactly once (no duplicate link).
            self::assertCount(1, $f->getEvidenceDocuments());
        }
    }

    private function repo(): ComplianceRequirementFulfillmentRepository
    {
        return self::getContainer()->get(ComplianceRequirementFulfillmentRepository::class);
    }

    private function countBulkAuditRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'bulk' AND entity_type = 'ComplianceRequirementFulfillment'",
        );
    }

    private function createTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Cert Test Tenant');
        $tenant->setCode('cert_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function createUser(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('cert_' . uniqid() . '@example.test');
        $user->setFirstName('Cert');
        $user->setLastName('Tester');
        $user->setTenant($tenant);
        $this->em->persist($user);

        return $user;
    }

    private function createFramework(string $code): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode($code)
            ->setName('Test Framework ' . $code)
            ->setDescription('Integration-test framework')
            ->setVersion('1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($framework);

        return $framework;
    }

    private function createRequirement(ComplianceFramework $framework, string $requirementId): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setFramework($framework)
            ->setRequirementId($requirementId)
            ->setTitle('Req ' . $requirementId)
            ->setDescription('Test requirement ' . $requirementId)
            ->setPriority('medium');
        $framework->addRequirement($req);
        $this->em->persist($req);

        return $req;
    }

    private function createDocument(Tenant $tenant, User $user): Document
    {
        $doc = new Document();
        $doc->setFilename('cert.pdf')
            ->setOriginalFilename('cert.pdf')
            ->setMimeType('application/pdf')
            ->setFileSize(1024)
            ->setFilePath('/tmp/cert.pdf')
            ->setCategory('certificate')
            ->setTenant($tenant)
            ->setUploadedBy($user);
        $this->em->persist($doc);

        return $doc;
    }
}
