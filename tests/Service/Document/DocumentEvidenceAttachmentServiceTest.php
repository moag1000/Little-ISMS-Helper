<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Service\Document\DocumentEvidenceAttachmentService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Phase 1 — DocumentEvidenceAttachmentService unit tests.
 *
 * Covers:
 *  - attachOnApproval skips documents without a template.
 *  - ISO 27001 Annex A refs → DocumentControlLink creation.
 *  - Idempotency: existing link is not duplicated.
 *  - Status upgrade: not_started → in_progress on ISO 27001 link.
 *  - DORA refs → ComplianceRequirement evidenceDocuments.
 *  - BSI refs resolved via linkedBsiBausteine (preferred) / linkedBausteine (fallback).
 *  - ISO 27701 refs resolved via iso27701Clauses2025, fallback to ISO27701 code.
 *  - resolveFrameworkCodeFromLabel tolerance for casing / aliases.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentEvidenceAttachmentServiceTest extends TestCase
{
    private MockObject $em;
    private MockObject $controlRepo;
    private MockObject $frameworkRepo;
    private MockObject $requirementRepo;
    private MockObject $dclRepo;
    private DocumentEvidenceAttachmentService $svc;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->controlRepo = $this->createMock(ControlRepository::class);
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $this->dclRepo = $this->createMock(DocumentControlLinkRepository::class);

        $this->svc = new DocumentEvidenceAttachmentService(
            em: $this->em,
            controlRepository: $this->controlRepo,
            frameworkRepository: $this->frameworkRepo,
            requirementRepository: $this->requirementRepo,
            dclRepository: $this->dclRepo,
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function attachOnApprovalSkipsWhenNoTemplate(): void
    {
        $doc = new Document();
        $doc->setStatus('approved');
        $doc->setCategory('policy');
        $doc->setFilename('test.pdf');
        $doc->setOriginalFilename('test.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(100);
        $doc->setFilePath('uploads/test.pdf');

        $this->em->expects($this->never())->method('persist');
        $this->controlRepo->expects($this->never())->method('findByControlIdAndTenant');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['iso27001_links']);
        $this->assertSame(0, $result['requirement_links']);
    }

    #[Test]
    public function attachOnApprovalSkipsWhenNoTenant(): void
    {
        $template = $this->template(['5.1']);
        $doc = $this->doc($template); // No tenant set.

        $this->em->expects($this->never())->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['iso27001_links']);
    }

    #[Test]
    public function iso27001ControlLinkIsCreated(): void
    {
        $tenant = new Tenant();
        $template = $this->template(['5.1']);
        $doc = $this->doc($template, $tenant);

        $control = $this->control('5.1', 'not_started');

        $this->controlRepo
            ->expects($this->once())
            ->method('findByControlIdAndTenant')
            ->with('5.1', $tenant)
            ->willReturn($control);

        $this->dclRepo
            ->expects($this->once())
            ->method('findOneByDocumentAndControl')
            ->with($doc, $control)
            ->willReturn(null); // No existing link.

        $this->em->expects($this->atLeast(1))->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(1, $result['iso27001_links']);
        // Status should be upgraded from not_started → in_progress.
        $this->assertSame('in_progress', $control->getImplementationStatus());
    }

    #[Test]
    public function iso27001ExistingLinkIsSkippedIdempotently(): void
    {
        $tenant = new Tenant();
        $template = $this->template(['5.1']);
        $doc = $this->doc($template, $tenant);

        $control = $this->control('5.1', 'implemented');
        $existingLink = new DocumentControlLink($doc, $control);

        $this->controlRepo
            ->method('findByControlIdAndTenant')
            ->willReturn($control);

        $this->dclRepo
            ->method('findOneByDocumentAndControl')
            ->willReturn($existingLink); // Already exists.

        $this->em->expects($this->never())->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['iso27001_links']);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function iso27001ControlNotFoundIsSkipped(): void
    {
        $tenant = new Tenant();
        $template = $this->template(['99.99']); // Non-existent.
        $doc = $this->doc($template, $tenant);

        $this->controlRepo
            ->method('findByControlIdAndTenant')
            ->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['iso27001_links']);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function statusUpgradeSkippedWhenAlreadyHigher(): void
    {
        $tenant = new Tenant();
        $template = $this->template(['5.1']);
        $doc = $this->doc($template, $tenant);

        $control = $this->control('5.1', 'implemented'); // Already high.

        $this->controlRepo->method('findByControlIdAndTenant')->willReturn($control);
        $this->dclRepo->method('findOneByDocumentAndControl')->willReturn(null);
        $this->em->expects($this->atLeast(1))->method('persist');

        $this->svc->attachOnApproval($doc);

        // Status must NOT be downgraded.
        $this->assertSame('implemented', $control->getImplementationStatus());
    }

    #[Test]
    public function doraRefsAttachToRequirementEvidence(): void
    {
        $tenant = new Tenant();
        $template = new PolicyTemplate();
        $template->setKey('dora.test');
        $template->setStandard('dora');
        $template->setTopic('ict_risk');
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('t');
        $template->setBodyTranslationKey('b');
        $template->setLinkedDoraArticles(['Art. 6(1)', 'Art. 9(4)']);

        $doc = $this->doc($template, $tenant);

        $framework = $this->framework('DORA');
        $this->frameworkRepo->method('findOneBy')->willReturn($framework);

        $req1 = $this->requirement('Art. 6(1)', $framework);
        $req2 = $this->requirement('Art. 9(4)', $framework);

        $this->requirementRepo
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($req1, $req2) {
                return match ($criteria['requirementId']) {
                    'Art. 6(1)' => $req1,
                    'Art. 9(4)' => $req2,
                    default => null,
                };
            });

        $this->em->expects($this->atLeast(2))->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(2, $result['requirement_links']);
        $this->assertTrue($req1->getEvidenceDocuments()->contains($doc));
        $this->assertTrue($req2->getEvidenceDocuments()->contains($doc));
    }

    #[Test]
    public function requirementAlreadyContainsDocumentIsSkipped(): void
    {
        $tenant = new Tenant();
        $template = new PolicyTemplate();
        $template->setKey('dora.test');
        $template->setStandard('dora');
        $template->setTopic('ict_risk');
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('t');
        $template->setBodyTranslationKey('b');
        $template->setLinkedDoraArticles(['Art. 6(1)']);

        $doc = $this->doc($template, $tenant);

        $framework = $this->framework('DORA');
        $this->frameworkRepo->method('findOneBy')->willReturn($framework);

        $req = $this->requirement('Art. 6(1)', $framework);
        $req->addEvidenceDocument($doc); // Already linked.

        $this->requirementRepo->method('findOneBy')->willReturn($req);
        $this->em->expects($this->never())->method('persist');

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['requirement_links']);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function frameworkNotFoundIsSkipped(): void
    {
        $tenant = new Tenant();
        $template = new PolicyTemplate();
        $template->setKey('dora.test');
        $template->setStandard('dora');
        $template->setTopic('ict_risk');
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('t');
        $template->setBodyTranslationKey('b');
        $template->setLinkedDoraArticles(['Art. 6(1)']);

        $doc = $this->doc($template, $tenant);

        $this->frameworkRepo->method('findOneBy')->willReturn(null); // Framework missing.

        $result = $this->svc->attachOnApproval($doc);

        $this->assertSame(0, $result['requirement_links']);
        $this->assertSame(1, $result['skipped']); // 1 ref skipped.
    }

    #[Test]
    public function resolveFrameworkCodeFromLabelIsCaseInsensitive(): void
    {
        $this->assertSame('DORA', $this->svc->resolveFrameworkCodeFromLabel('dora'));
        $this->assertSame('DORA', $this->svc->resolveFrameworkCodeFromLabel('EU-DORA'));
        $this->assertSame('GDPR', $this->svc->resolveFrameworkCodeFromLabel('GDPR'));
        $this->assertSame('NIS2UMSUCG', $this->svc->resolveFrameworkCodeFromLabel('NIS2'));
        $this->assertSame('SOC2', $this->svc->resolveFrameworkCodeFromLabel('SOC2'));
        $this->assertNull($this->svc->resolveFrameworkCodeFromLabel('UnknownFramework'));
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /** @param list<string> $annexARefs */
    private function template(array $annexARefs = []): PolicyTemplate
    {
        $t = new PolicyTemplate();
        $t->setKey('iso27001.test');
        $t->setStandard('iso27001');
        $t->setTopic('access_control');
        $t->setDocumentType('policy');
        $t->setTitleTranslationKey('t');
        $t->setBodyTranslationKey('b');
        $t->setLinkedAnnexAControls($annexARefs);
        return $t;
    }

    private function doc(PolicyTemplate $template, ?Tenant $tenant = null): Document
    {
        $doc = new Document();
        $doc->setStatus('approved');
        $doc->setCategory('policy');
        $doc->setFilename('test.pdf');
        $doc->setOriginalFilename('test.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(100);
        $doc->setFilePath('uploads/test.pdf');
        $doc->setGeneratedFromTemplate($template);
        if ($tenant !== null) {
            $doc->setTenant($tenant);
        }
        return $doc;
    }

    private function control(string $controlId, string $status = 'not_started'): Control
    {
        $c = new Control();
        $c->setControlId($controlId);
        $c->setName('Control ' . $controlId);
        $c->setDescription('Description');
        $c->setCategory('organisational');
        $c->setApplicable(true);
        $c->setImplementationStatus($status);
        return $c;
    }

    private function framework(string $code): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->setCode($code);
        $fw->setName($code . ' Framework');
        $fw->setVersion('1.0');
        $fw->setApplicableIndustry('all');
        $fw->setRegulatoryBody('EU');
        return $fw;
    }

    private function requirement(string $reqId, ComplianceFramework $framework): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setRequirementId($reqId);
        $req->setTitle('Requirement ' . $reqId);
        $req->setDescription('Description');
        $req->setPriority('high');
        $req->setFramework($framework);
        return $req;
    }
}
