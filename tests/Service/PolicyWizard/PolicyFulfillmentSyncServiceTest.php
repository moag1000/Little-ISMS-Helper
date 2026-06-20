<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\PolicyWizard\PolicyFulfillmentSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PolicyFulfillmentSyncService unit tests.
 *
 * Verifies the status-transition matrix for ComplianceRequirementFulfillment
 * rows when a policy Document is generated:
 *   not_started / not_implemented  →  in_progress  (bump)
 *   in_progress                    →  unchanged    (no-downgrade)
 *   implemented                    →  unchanged    (no-downgrade)
 *   verified                       →  unchanged    (no-downgrade)
 *
 * Also verifies:
 *   - fulfillmentPercentage set to 25 when was 0
 *   - evidence document attached via addEvidenceDocument()
 *   - EntityManager::persist() + flush() called on actual change
 *   - EntityManager::persist() NOT called when no change occurs
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyFulfillmentSyncServiceTest extends TestCase
{
    private function makeTenant(int $id = 42): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeWizardRun(int $id = 99): WizardRun
    {
        $run = new WizardRun();
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        return $run;
    }

    private function makeDocument(Tenant $tenant, PolicyTemplate $template, int $id = 501): Document
    {
        $document = new Document();
        $document->setTenant($tenant);
        $document->setGeneratedFromTemplate($template);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);
        return $document;
    }

    private function makePolicyTemplate(
        array $linkedAnnexAControls = [],
        array $linkedBausteine = [],
        array $linkedBsiBausteine = [],
        array $linkedDoraArticles = [],
        array $iso27701Clauses2025 = [],
    ): PolicyTemplate {
        $template = $this->createStub(PolicyTemplate::class);
        $template->method('getLinkedAnnexAControls')->willReturn($linkedAnnexAControls);
        $template->method('getLinkedBausteine')->willReturn($linkedBausteine);
        $template->method('getLinkedBsiBausteine')->willReturn($linkedBsiBausteine);
        $template->method('getLinkedDoraArticles')->willReturn($linkedDoraArticles);
        $template->method('getIso27701Clauses2025')->willReturn($iso27701Clauses2025);
        return $template;
    }

    private function makeFulfillment(string $status = 'not_started', int $percentage = 0): ComplianceRequirementFulfillment
    {
        $f = new ComplianceRequirementFulfillment();
        $f->setStatus($status);
        $f->setFulfillmentPercentage($percentage);
        return $f;
    }

    /**
     * Build the service with controlled mocks.
     *
     * @param array<string, ComplianceRequirementFulfillment|null> $fulfillmentsByReqId
     * @param array<string, list<ComplianceRequirement>>           $requirementsByReqId
     */
    private function makeService(
        array $fulfillmentsByReqId,
        array $requirementsByReqId,
        ?EntityManagerInterface $em = null,
    ): PolicyFulfillmentSyncService {
        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($requirementsByReqId): array {
                $id = $criteria['requirementId'] ?? null;
                return $requirementsByReqId[$id] ?? [];
            },
        );

        $fulfillmentService = $this->createMock(ComplianceRequirementFulfillmentService::class);
        $fulfillmentService->method('getOrCreateFulfillment')->willReturnCallback(
            function (Tenant $tenant, ComplianceRequirement $req) use ($fulfillmentsByReqId): ComplianceRequirementFulfillment {
                $id = $req->getRequirementId();
                if (isset($fulfillmentsByReqId[$id])) {
                    return $fulfillmentsByReqId[$id];
                }
                // Return a fresh fulfillment for unmapped requirements.
                return new ComplianceRequirementFulfillment();
            },
        );

        $em ??= $this->createMock(EntityManagerInterface::class);

        return new PolicyFulfillmentSyncService($fulfillmentService, $requirementRepo, $em);
    }

    /** Helper: build a ComplianceRequirement stub with a given requirementId. */
    private function makeRequirement(string $requirementId): ComplianceRequirement
    {
        $req = $this->createStub(ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn($requirementId);
        return $req;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Positive bump tests
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testBumpsNotStartedToInProgress(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.15']);
        $document  = $this->makeDocument($tenant, $template);
        $run       = $this->makeWizardRun();

        $req         = $this->makeRequirement('A.5.15');
        $fulfillment = $this->makeFulfillment('not_started', 0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = $this->makeService(
            ['A.5.15' => $fulfillment],
            ['A.5.15' => [$req]],
            $em,
        );

        $result = $service->syncForDocument($document, $run);

        self::assertSame('in_progress', $fulfillment->getStatus());
        self::assertSame(['A.5.15' => 'in_progress'], $result);
    }

    #[Test]
    public function testSetsFulfillmentPercentageTo25WhenWasZero(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.15']);
        $document  = $this->makeDocument($tenant, $template);

        $req         = $this->makeRequirement('A.5.15');
        $fulfillment = $this->makeFulfillment('not_started', 0);

        $service = $this->makeService(
            ['A.5.15' => $fulfillment],
            ['A.5.15' => [$req]],
        );

        $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame(25, $fulfillment->getFulfillmentPercentage());
    }

    #[Test]
    public function testAttachesDocumentAsEvidence(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.15']);
        $document  = $this->makeDocument($tenant, $template);

        $req         = $this->makeRequirement('A.5.15');
        $fulfillment = $this->makeFulfillment('not_started', 0);

        $service = $this->makeService(
            ['A.5.15' => $fulfillment],
            ['A.5.15' => [$req]],
        );

        $service->syncForDocument($document, $this->makeWizardRun());

        self::assertTrue(
            $fulfillment->getEvidenceDocuments()->contains($document),
            'Generated document must be attached as evidence',
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // No-downgrade / no-op tests
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testDoesNotDowngradeImplemented(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.18']);
        $document  = $this->makeDocument($tenant, $template);

        $req         = $this->makeRequirement('A.5.18');
        $fulfillment = $this->makeFulfillment('implemented', 80);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $service = $this->makeService(
            ['A.5.18' => $fulfillment],
            ['A.5.18' => [$req]],
            $em,
        );

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame('implemented', $fulfillment->getStatus(), 'implemented must not be downgraded');
        self::assertSame([], $result, 'no-op must return empty change-map');
    }

    #[Test]
    public function testDoesNotDowngradeVerified(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.19']);
        $document  = $this->makeDocument($tenant, $template);

        $req         = $this->makeRequirement('A.5.19');
        $fulfillment = $this->makeFulfillment('verified', 100);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $service = $this->makeService(
            ['A.5.19' => $fulfillment],
            ['A.5.19' => [$req]],
            $em,
        );

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame('verified', $fulfillment->getStatus());
        self::assertSame([], $result);
    }

    #[Test]
    public function testDoesNotOverwriteAlreadyInProgress(): void
    {
        $tenant    = $this->makeTenant();
        $template  = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.17']);
        $document  = $this->makeDocument($tenant, $template);

        $req         = $this->makeRequirement('A.5.17');
        $fulfillment = $this->makeFulfillment('in_progress', 50);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $service = $this->makeService(
            ['A.5.17' => $fulfillment],
            ['A.5.17' => [$req]],
            $em,
        );

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame('in_progress', $fulfillment->getStatus());
        self::assertSame(50, $fulfillment->getFulfillmentPercentage(), 'existing percentage must not be overwritten');
        self::assertSame([], $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Guard / edge-case tests
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testReturnsEmptyWhenTemplateIsNull(): void
    {
        $tenant   = $this->makeTenant();
        $document = new Document();
        $document->setTenant($tenant);
        // No template set → getGeneratedFromTemplate() returns null

        $service = $this->makeService([], []);

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame([], $result);
    }

    #[Test]
    public function testReturnsEmptyWhenTenantIsNull(): void
    {
        $template = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.15']);
        $document = new Document();
        $document->setGeneratedFromTemplate($template);
        // No tenant set → getTenant() returns null

        $service = $this->makeService([], []);

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame([], $result);
    }

    #[Test]
    public function testSkipsRequirementIdWithNoMatchInRepository(): void
    {
        $tenant   = $this->makeTenant();
        $template = $this->makePolicyTemplate(linkedAnnexAControls: ['A.5.99']);
        $document = $this->makeDocument($tenant, $template);

        // Repository returns empty for 'A.5.99'
        $service = $this->makeService([], ['A.5.99' => []]);

        $result = $service->syncForDocument($document, $this->makeWizardRun());

        self::assertSame([], $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Non-AnnexA link field tests
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function testBumpsDoraArticleToInProgress(): void
    {
        $tenant   = $this->makeTenant();
        // Use a DORA article link — no AnnexA controls
        $template = $this->makePolicyTemplate(linkedDoraArticles: ['Art. 9.4']);
        $document = $this->makeDocument($tenant, $template);
        $run      = $this->makeWizardRun();

        $req         = $this->makeRequirement('Art. 9.4');
        $fulfillment = $this->makeFulfillment('not_started', 0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = $this->makeService(
            ['Art. 9.4' => $fulfillment],
            ['Art. 9.4' => [$req]],
            $em,
        );

        $result = $service->syncForDocument($document, $run);

        self::assertSame('in_progress', $fulfillment->getStatus());
        self::assertSame(['Art. 9.4' => 'in_progress'], $result);
    }
}
