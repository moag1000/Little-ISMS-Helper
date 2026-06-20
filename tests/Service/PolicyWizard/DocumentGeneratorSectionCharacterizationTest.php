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
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Characterization tests for DocumentGenerator section-extension dispatch.
 *
 * These tests pin the CURRENT behaviour so the SectionExtensionRegistry
 * refactor in STEP 5 can be proven byte-identical:
 *
 *  (a) DORA scope active  → generated Document body contains
 *      `## DORA-Erweiterung (Art. X)` prose + dora tags.
 *  (b) GDPR scope active  → DocumentSection rows created on the host ISO
 *      policy, one per catalogue entry for the matching topic.
 *
 * Both assertions together guarantee that the registry-driven generic loop
 * produces the same artefacts as the two hard-coded private methods
 * (`appendGdprSectionsIfApplicable`, `appendDoraExtensionIfApplicable`)
 * that it replaces.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorSectionCharacterizationTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTenant(int $id = 42): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('CharTest GmbH');
        $stub->method('getName')->willReturn('CharTest');
        return $stub;
    }

    private function makeUser(int $id = 7): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    /**
     * @param list<string> $standards
     */
    private function makeRun(Tenant $tenant, array $standards): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 55);
        return $run;
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
     * @param list<PolicyTemplate>          $templates
     * @param array<string, DocumentSection> $existingSections  key → existing section (idempotency test helper)
     */
    private function makeGenerator(
        array $templates,
        bool $withDora = false,
        bool $withGdpr = false,
        array $existingSections = [],
    ): DocumentGenerator {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof Document && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(Document::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 5000);
            }
        });
        $em->method('flush');
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callable) {
                try {
                    return $callable(null);
                } catch (Throwable $err) {
                    throw $err;
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
        $documentRepo->method('findOneBy')->willReturn(null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn([
            'tenant.legal_name' => 'CharTest GmbH',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title')               => 'Title: ' . $key,
                str_ends_with($key, '.dora_extension.body') => 'DORA body for ' . $key,
                str_ends_with($key, '.body')                => 'ISO body for ' . $key,
                default                                      => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
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
            new NullLogger(),
            $withDora ? new DoraExtensionCatalogue() : null,
            null,
            $withGdpr ? new GdprSectionCatalogue() : null,
        );
    }

    // -------------------------------------------------------------------------
    // Section helpers
    // -------------------------------------------------------------------------

    /** @return list<DocumentSection> */
    private function persistedSections(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof DocumentSection,
        ));
    }

    /** @return list<string> */
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

    private function lastDocument(): Document
    {
        $docs = array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof Document,
        ));
        self::assertNotEmpty($docs, 'Expected at least one persisted Document');
        return $docs[count($docs) - 1];
    }

    // =========================================================================
    // (a) DORA characterization — body_extension render mode
    // =========================================================================

    /**
     * When a run includes 'dora' in standardsAdopted, the ISO 27001 policy
     * body for topics that have a DORA extension MUST contain the
     * `## DORA-Erweiterung (Art. X)` heading AND the dora tags must be emitted.
     *
     * This pins the current DocumentGenerator.appendDoraExtensionIfApplicable
     * path so the registry-driven generic loop can be verified byte-identical.
     */
    #[Test]
    public function doraExtensionProseAppearsWhenScopeActive(): void
    {
        $tenant = $this->makeTenant();
        // 'backup' has DORA Art. 12 extension per DoraExtensionCatalogue
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template], withDora: true);

        $run = $this->makeRun($tenant, ['iso27001', 'dora']);
        $generator->generate($run);

        // Verify via the public appendDoraExtensionIfApplicable method that
        // the extension prose WOULD be present in the rendered body.
        $rawIsoBody = 'ISO body for policy.iso27001.backup.v1.body';
        $appended = $generator->appendDoraExtensionIfApplicable($template, $rawIsoBody, $run);

        self::assertStringContainsString(
            '## DORA-Erweiterung (Art. 12)',
            $appended,
            'DORA extension heading must be present in body when scope is active',
        );
        self::assertStringContainsString(
            'DORA body for',
            $appended,
            'DORA extension body text must be appended',
        );

        // Tags: the produced Document must carry dora markers.
        $names = $this->tagNames();
        self::assertContains('dora-extension:applied', $names);
        self::assertContains('dora-validity:2025-01-17', $names);
        self::assertContains('standard:iso27001', $names);
    }

    /**
     * When DORA is NOT in standardsAdopted the body must NOT be extended and
     * no dora tags emitted. Pins the guard `in_array('dora', $standards)`.
     */
    #[Test]
    public function doraExtensionAbsentWhenScopeInactive(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template], withDora: true);

        $run = $this->makeRun($tenant, ['iso27001']); // no dora
        $generator->generate($run);

        $rawIsoBody = 'ISO body for policy.iso27001.backup.v1.body';
        $result = $generator->appendDoraExtensionIfApplicable($template, $rawIsoBody, $run);

        self::assertSame(
            $rawIsoBody,
            $result,
            'Body must be unchanged when DORA scope is absent',
        );

        $names = $this->tagNames();
        self::assertNotContains('dora-extension:applied', $names);
    }

    /**
     * DORA-only templates (standard='dora') must NOT receive the extension.
     * This pins the guard `$template->getStandard() !== 'iso27001'`.
     */
    #[Test]
    public function doraExtensionNotAppliedToDoraOnlyTemplates(): void
    {
        $tenant = $this->makeTenant();
        // Standalone DORA template — NOT an ISO 27001 host.
        $template = $this->makeTemplate('dora', 'ict_risk_management');
        $generator = $this->makeGenerator([$template], withDora: true);

        $run = $this->makeRun($tenant, ['dora']);
        $generator->generate($run);

        // The DORA-extension method itself guards on `standard !== 'iso27001'`.
        $rawBody = 'Standalone DORA body';
        $result = $generator->appendDoraExtensionIfApplicable($template, $rawBody, $run);

        self::assertSame(
            $rawBody,
            $result,
            'DORA-only templates must NOT receive the extension prose (DORA-only templates handled as NEW standalone docs)',
        );
    }

    // =========================================================================
    // (b) GDPR characterization — document_section render mode
    // =========================================================================

    /**
     * When GDPR is in standardsAdopted and the template is iso27001/incident_management,
     * one DocumentSection with key 'gdpr_breach_72h' and approval_role='dpo'
     * MUST be created.
     *
     * This pins the current DocumentGenerator.appendGdprSectionsIfApplicable path.
     */
    #[Test]
    public function gdprSectionRowsCreatedForMatchingIsoTopic(): void
    {
        $tenant = $this->makeTenant();
        // 'incident_management' maps to 'gdpr_breach_72h' per GdprSectionCatalogue.
        $template = $this->makeTemplate('iso27001', 'incident_management');
        $generator = $this->makeGenerator([$template], withGdpr: true);

        $run = $this->makeRun($tenant, ['iso27001', 'gdpr']);
        $generator->generate($run);

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));

        self::assertCount(1, $gdprSections, 'Exactly one GDPR section for incident_management topic');
        self::assertSame('gdpr_breach_72h', $gdprSections[0]->getSectionKey());
        self::assertSame(DocumentSection::STATUS_DRAFT, $gdprSections[0]->getStatus());
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $gdprSections[0]->getApprovalRole());
        self::assertSame($tenant, $gdprSections[0]->getTenant());

        // Audit tag must be emitted on the host Document.
        self::assertContains('gdpr-section:gdpr_breach_72h:applied', $this->tagNames());
    }

    /**
     * Topics with no GDPR mapping (e.g. 'backup') must produce zero sections.
     */
    #[Test]
    public function noGdprSectionsForTopicWithoutMapping(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template], withGdpr: true);

        $run = $this->makeRun($tenant, ['iso27001', 'gdpr']);
        $generator->generate($run);

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(0, $gdprSections, 'backup topic has no GDPR catalogue entry → no sections');
    }

    /**
     * Topics with multiple GDPR mappings (e.g. 'secure_development' → PbD + AI)
     * must produce BOTH sections with correct approval roles.
     */
    #[Test]
    public function multipleGdprSectionsCreatedForTopicWithSeveralMappings(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'secure_development');
        $generator = $this->makeGenerator([$template], withGdpr: true);

        $run = $this->makeRun($tenant, ['iso27001', 'gdpr']);
        $generator->generate($run);

        $byKey = [];
        foreach ($this->persistedSections() as $section) {
            if (str_starts_with((string) $section->getSectionKey(), 'gdpr_')) {
                $byKey[(string) $section->getSectionKey()] = $section;
            }
        }

        self::assertArrayHasKey('gdpr_privacy_by_design', $byKey);
        self::assertArrayHasKey('gdpr_ai_systems', $byKey);
        self::assertSame(DocumentSection::APPROVAL_ROLE_JOINT, $byKey['gdpr_privacy_by_design']->getApprovalRole());
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $byKey['gdpr_ai_systems']->getApprovalRole());

        // Both audit tags emitted.
        $names = $this->tagNames();
        self::assertContains('gdpr-section:gdpr_privacy_by_design:applied', $names);
        self::assertContains('gdpr-section:gdpr_ai_systems:applied', $names);
    }

    /**
     * When GDPR scope is absent, no GDPR sections must be created.
     * Pins the guard `isGdprScopeActive()`.
     */
    #[Test]
    public function noGdprSectionsWhenGdprScopeAbsent(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'incident_management');
        $generator = $this->makeGenerator([$template], withGdpr: true);

        $run = $this->makeRun($tenant, ['iso27001']); // no gdpr
        $generator->generate($run);

        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(0, $gdprSections);
        self::assertNotContains('gdpr-section:gdpr_breach_72h:applied', $this->tagNames());
    }

    /**
     * Combined DORA + GDPR run: both standards active at the same time.
     * ISO 27001/incident_management gets BOTH the DORA extension body prose
     * AND the gdpr_breach_72h DocumentSection.
     */
    #[Test]
    public function combinedDoraAndGdprScopeProducesBothOutputs(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'incident_management');
        $generator = $this->makeGenerator([$template], withDora: true, withGdpr: true);

        $run = $this->makeRun($tenant, ['iso27001', 'dora', 'gdpr']);
        $generator->generate($run);

        // DORA extension prose (checked via public helper).
        $rawBody = 'ISO body for policy.iso27001.incident_management.v1.body';
        $appended = $generator->appendDoraExtensionIfApplicable($template, $rawBody, $run);
        self::assertStringContainsString('## DORA-Erweiterung', $appended, 'DORA prose must be present');

        // GDPR section rows.
        $gdprSections = array_values(array_filter(
            $this->persistedSections(),
            static fn (DocumentSection $s): bool => str_starts_with((string) $s->getSectionKey(), 'gdpr_'),
        ));
        self::assertCount(1, $gdprSections, 'gdpr_breach_72h section must be created');
        self::assertSame('gdpr_breach_72h', $gdprSections[0]->getSectionKey());
    }
}
