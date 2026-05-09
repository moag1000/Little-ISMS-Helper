<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard W6-C — DocumentGenerator GDPR section-injector tests.
 *
 * Verifies the §0 Decision Matrix v2 contract: when the run carries
 * GDPR scope, ISO 27001 host policies grow per-topic privacy sections
 * with the catalogue's approval-role recorded on each row. The
 * sections drive the W6-A split-state approval gate so the DPO can
 * sign off independently of the CISO-owned host content.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorGdprSectionsTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    private function makeTenant(int $id = 21): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeUser(int $id = 9): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeTemplate(string $standard, string $topic, int $id = 1): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);
        return $template;
    }

    /**
     * @param list<string> $standards
     */
    private function makeRun(Tenant $tenant, array $standards = ['iso27001', 'gdpr']): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 77);
        return $run;
    }

    /**
     * @param list<PolicyTemplate> $templates
     * @param array<string, mixed> $opts
     */
    private function makeGenerator(array $templates, array $opts = []): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof Document && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(Document::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 3000);
            }
        });
        $em->method('flush');
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callable) {
                try {
                    return $callable(null);
                } catch (Throwable $error) {
                    throw $error;
                }
            },
        );

        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $templateRepo->method('findActiveByStandard')->willReturnCallback(
            static fn (string $standard) => array_values(array_filter(
                $templates,
                static fn (PolicyTemplate $t): bool => $t->getStandard() === $standard,
            )),
        );

        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findOneBy')->willReturn(null);

        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $dclRepo->method('findOneByDocumentAndControl')->willReturn(null);

        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findOneBy')->willReturn($opts['existing_document'] ?? null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn([
            'tenant.legal_name' => 'TestCo GmbH',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.body') => 'Base ISO body for ' . $key,
                default => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $existingSections = $opts['existing_sections'] ?? [];
        $sectionRepo->method('findOneByDocumentAndKey')->willReturnCallback(
            static fn (Document $doc, string $key): ?DocumentSection => $existingSections[$key] ?? null,
        );

        return new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            $sectionRepo,
            new \Psr\Log\NullLogger(),
            null,
            null,
            new GdprSectionCatalogue(),
        );
    }

    /** @return list<DocumentSection> */
    private function persistedSections(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof DocumentSection,
        ));
    }

    private function tagNames(): array
    {
        return array_values(array_map(
            static fn (Tag $t): string => (string) $t->getName(),
            array_filter(
                $this->persisted,
                static fn (object $o): bool => $o instanceof Tag,
            ),
        ));
    }

    #[Test]
    public function testGdprSectionsInjectedWhenScopeActive(): void
    {
        $tenant = $this->makeTenant();
        // incident_management → exactly 1 GDPR section (gdpr_breach_72h, dpo).
        $template = $this->makeTemplate('iso27001', 'incident_management');
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant, ['iso27001', 'gdpr']));

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(1, $gdprSections);
        self::assertSame('gdpr_breach_72h', $gdprSections[0]->getSectionKey());
        self::assertSame(DocumentSection::STATUS_DRAFT, $gdprSections[0]->getStatus());
        self::assertSame($tenant, $gdprSections[0]->getTenant(), 'tenant_id propagated for multi-tenant scoping');

        // Audit-tag emitted on the host Document.
        self::assertContains('gdpr-section:gdpr_breach_72h:applied', $this->tagNames());
    }

    #[Test]
    public function testNoSectionsWhenGdprScopeMissing(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'incident_management');
        $generator = $this->makeGenerator([$template]);

        // ISO-only run — GDPR NOT in standardsAdopted.
        $generator->generate($this->makeRun($tenant, ['iso27001']));

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(0, $gdprSections, 'no GDPR sections when scope is missing');

        // Tags must NOT include the GDPR markers.
        $names = $this->tagNames();
        self::assertNotContains('gdpr-section:gdpr_breach_72h:applied', $names);
    }

    #[Test]
    public function testApprovalRoleMatchesCatalogue(): void
    {
        $tenant = $this->makeTenant();
        // secure_development carries TWO sections with DIFFERENT roles
        // (PbD=joint, AI=dpo) — the most stringent contract test.
        $template = $this->makeTemplate('iso27001', 'secure_development');
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant, ['iso27001', 'gdpr']));

        $byKey = [];
        foreach ($this->persistedSections() as $section) {
            $key = (string) $section->getSectionKey();
            if (str_starts_with($key, 'gdpr_')) {
                $byKey[$key] = $section;
            }
        }
        self::assertArrayHasKey('gdpr_privacy_by_design', $byKey);
        self::assertArrayHasKey('gdpr_ai_systems', $byKey);
        self::assertSame(
            DocumentSection::APPROVAL_ROLE_JOINT,
            $byKey['gdpr_privacy_by_design']->getApprovalRole(),
            'PbD section must carry approval_role=joint per §0 Decision Matrix v2',
        );
        self::assertSame(
            DocumentSection::APPROVAL_ROLE_DPO,
            $byKey['gdpr_ai_systems']->getApprovalRole(),
            'AI section must carry approval_role=dpo per §0 Decision Matrix v2',
        );

        // Both audit-trail tags emitted.
        $names = $this->tagNames();
        self::assertContains('gdpr-section:gdpr_privacy_by_design:applied', $names);
        self::assertContains('gdpr-section:gdpr_ai_systems:applied', $names);
    }

    #[Test]
    public function testIdempotentNoDoubleInjectionOnReGeneration(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'incident_management');

        // Existing section already in place for this Document — the
        // re-run must NOT spawn a duplicate (uniqueness guarantee on
        // the (document_id, section_key) constraint).
        $existingSection = new DocumentSection();
        $existingSection->setSectionKey('gdpr_breach_72h');
        $existingSection->setStatus(DocumentSection::STATUS_DPO_SIGN_OFF);
        $existingSection->setTenant($tenant);
        $existingSection->setApprovalRole(DocumentSection::APPROVAL_ROLE_DPO);

        $generator = $this->makeGenerator([$template], [
            'existing_sections' => [
                'gdpr_breach_72h' => $existingSection,
                'privacy_addendum' => $existingSection,
            ],
        ]);

        $generator->generate($this->makeRun($tenant, ['iso27001', 'gdpr']));

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(
            0,
            $gdprSections,
            'idempotent: re-generating a Document with existing GDPR sections must NOT duplicate them',
        );
    }
}
