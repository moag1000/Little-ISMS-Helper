<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Entity\CertificateCoverageRule;
use App\Entity\ComplianceCertificate;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Certificate\CertificateBulkFulfillmentService;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end integration test proving that certificate-fulfilled controls are
 * re-reviewed once the certificate expires, via the EXISTING generic
 * date-based reverification mechanism (no certificate-specific code path).
 *
 * Flow:
 *   1. Apply a certificate whose validUntil is in the PAST. The bulk service
 *      sets each covered fulfillment's nextReviewDate = cert.validUntil and
 *      evidenceOutdated = false (verified-via-certificate).
 *   2. Run the generic expiry scan
 *      ({@see EvidenceCascadeInvalidationService::flagExpiredEvidence()}, which
 *      reuses {@see ComplianceRequirementFulfillmentRepository::findOverdueForReview()}).
 *   3. Assert the cert-fulfilled fulfillment is now flagged evidenceOutdated=true.
 *
 * Requires a real database (APP_ENV=test). Each test runs in a transaction
 * rolled back in tearDown().
 */
#[Group('integration')]
class CertificateExpiryReviewTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private CertificateBulkFulfillmentService $bulkService;

    private EvidenceCascadeInvalidationService $cascadeService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->bulkService = self::getContainer()->get(CertificateBulkFulfillmentService::class);
        $this->cascadeService = self::getContainer()->get(EvidenceCascadeInvalidationService::class);

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
    public function expiredCertificateTriggersReReviewOfFulfilledControls(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $framework = $this->createFramework('EXPFW');
        $r1 = $this->createRequirement($framework, 'R1');
        $r2 = $this->createRequirement($framework, 'R2');

        $rule = new CertificateCoverageRule();
        $rule->setFrameworkCode('EXPFW')
            ->setRequiredClass(null)
            ->setRequiredScopeTags([])
            ->setRequirementIds(['R1', 'R2'])
            ->setActive(true);
        $this->em->persist($rule);

        $doc = $this->createDocument($tenant, $user);

        // Certificate already expired: validUntil in the past.
        $validUntil = new DateTimeImmutable('-1 day');
        $cert = new ComplianceCertificate();
        $cert->setTenant($tenant)
            ->setFrameworkCode('EXPFW')
            ->setCertBody('Test Cert Body')
            ->setCertNumber('CERT-EXPIRED-1')
            ->setCertificateDocument($doc)
            ->setValidUntil($validUntil)
            ->setStatus('active');
        $this->em->persist($cert);

        $this->em->flush();

        // ── Apply the (expired) certificate ─────────────────────────────────
        $stats = $this->bulkService->apply($cert, $user);
        self::assertSame(2, $stats['fulfilled']);

        // Right after apply: verified-via-certificate, NOT yet flagged outdated,
        // but nextReviewDate is in the past (== cert.validUntil).
        $fulfillments = $this->repo()->findBy([
            'tenant' => $tenant,
            'requirement' => [$r1, $r2],
        ]);
        self::assertCount(2, $fulfillments);
        foreach ($fulfillments as $f) {
            self::assertFalse($f->isEvidenceOutdated(), 'Not yet flagged before the expiry scan runs.');
            self::assertNotNull($f->getNextReviewDate());
            self::assertTrue(
                $f->isOverdueForReview(),
                'nextReviewDate is in the past — the generic scan must pick it up.',
            );
        }

        // ── Run the generic expiry re-review mechanism ──────────────────────
        $flagged = $this->cascadeService->flagExpiredEvidence($tenant, $user);
        self::assertSame(2, $flagged, 'Both cert-fulfilled fulfillments flagged for re-review.');

        // ── Assert real DB state after the real mechanism ──────────────────
        $this->em->clear();
        $after = $this->repo()->findBy([
            'tenant' => $tenant->getId(),
            'requirement' => [$r1->getId(), $r2->getId()],
        ]);
        self::assertCount(2, $after);
        foreach ($after as $f) {
            self::assertTrue(
                $f->isEvidenceOutdated(),
                'Cert-fulfilled control must be flagged evidenceOutdated after expiry.',
            );
        }

        // A bulk audit batch documenting the expiry re-review must exist.
        // The event_type is stored in the batch entry's new_values JSON column.
        $auditRows = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'bulk'
               AND entity_type = 'ComplianceRequirementFulfillment'
               AND new_values LIKE '%fulfillment.evidence_expired%'",
        );
        self::assertGreaterThan(0, $auditRows, 'A bulk expiry-review audit batch must be written.');

        // ── Idempotent: a second scan flags nothing new ─────────────────────
        $second = $this->cascadeService->flagExpiredEvidence($tenant, $user);
        self::assertSame(0, $second, 'Already-flagged fulfillments are not re-flagged.');
    }

    private function repo(): ComplianceRequirementFulfillmentRepository
    {
        return self::getContainer()->get(ComplianceRequirementFulfillmentRepository::class);
    }

    private function createTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Cert Expiry Tenant');
        $tenant->setCode('certexp_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function createUser(Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail('certexp_' . uniqid() . '@example.test');
        $user->setFirstName('Cert');
        $user->setLastName('Expiry');
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
