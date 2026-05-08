<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\PolicyTemplate;
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
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard W3-I — DocumentGenerator DPO-section auto-creation tests.
 *
 * Closes the production-trigger gap "PolicyTemplate.dpoSectionRequired
 * exists but the generator never creates the privacy_addendum row".
 * Architecture §6 + §0.A — when dpoSectionRequired=true, the generator
 * must persist exactly one `privacy_addendum` DocumentSection per
 * Document, status=draft, idempotent on re-runs.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorDpoSectionTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var array<int, DocumentSection> */
    private array $sectionsByDocId = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->sectionsByDocId = [];
    }

    private function makeTenant(int $id = 11): Tenant
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

    private function makeTemplate(
        int $id,
        string $standard,
        string $topic,
        bool $dpoRequired,
        array $linkedAnnexA = [],
    ): PolicyTemplate {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setLinkedAnnexAControls($linkedAnnexA);
        $template->setDpoSectionRequired($dpoRequired);
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);
        return $template;
    }

    private function makeRun(Tenant $tenant, array $standards = ['iso27001']): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([
            WizardStepKeys::STEP_ORG_SCOPE => [
                'legal_name' => 'TestCo GmbH',
                'scope_statement' => 'Whole company.',
            ],
        ]);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 42);
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
                $reflection->setValue($entity, count($this->persisted) + 1000);
            }
            if ($entity instanceof DocumentSection) {
                $docId = $entity->getDocument()?->getId();
                if (is_int($docId)) {
                    $this->sectionsByDocId[$docId] = $entity;
                }
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
        $existingDoc = $opts['existing_document'] ?? null;
        $documentRepo->method('findOneBy')->willReturn($existingDoc);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn($opts['variables'] ?? [
            'tenant.legal_name' => 'TestCo GmbH',
            'tenant.scope_statement' => 'Whole company.',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.body') => "Body for {$key}\n\nTenant: {{ tenant.legal_name }}",
                default => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $existingSection = $opts['existing_section'] ?? null;
        $sectionRepo->method('findOneByDocumentAndKey')->willReturn($existingSection);

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

    #[Test]
    public function testCreatesPrivacyAddendumWhenDpoSectionRequired(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'privacy', dpoRequired: true);
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        $sections = $this->persistedSections();
        self::assertCount(1, $sections, 'exactly one DocumentSection per dpoSectionRequired template');
        self::assertSame('privacy_addendum', $sections[0]->getSectionKey());
        self::assertSame(DocumentSection::STATUS_DRAFT, $sections[0]->getStatus());
        self::assertSame($tenant, $sections[0]->getTenant(), 'tenant_id propagated for multi-tenant scoping');
        self::assertNotNull($sections[0]->getDocument());
    }

    #[Test]
    public function testNoSectionWhenDpoSectionNotRequired(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(2, 'iso27001', 'access_control', dpoRequired: false);
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        self::assertCount(0, $this->persistedSections(), 'non-DPO templates must not emit DocumentSection rows');
    }

    #[Test]
    public function testIdempotentReGeneration(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(3, 'iso27001', 'privacy', dpoRequired: true);

        // Existing Document + already-existing privacy_addendum section
        // (simulates the second wizard run with unchanged variables).
        $existingDoc = new Document();
        $existingDoc->setTenant($tenant);
        $existingDoc->setStatus('approved');
        $existingDoc->setIsImmutable(true);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($existingDoc, 555);
        // Match the canonical hash so the §10 reuse path fires.
        $probe = $this->makeGenerator([$template]);
        $probe->generate($this->makeRun($tenant));
        $newDoc = array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof Document,
        ))[0];
        $hash = $newDoc->getSubstitutionVariables()['_hash'] ?? null;
        self::assertIsString($hash);
        $this->setUp();
        $existingDoc->setSubstitutionVariables(['_hash' => $hash]);

        $existingSection = new DocumentSection();
        $existingSection->setDocument($existingDoc);
        $existingSection->setSectionKey('privacy_addendum');
        $existingSection->setStatus(DocumentSection::STATUS_DRAFT);
        $existingSection->setTenant($tenant);

        $generator = $this->makeGenerator([$template], [
            'existing_document' => $existingDoc,
            'existing_section' => $existingSection,
        ]);

        $generator->generate($this->makeRun($tenant));

        self::assertCount(
            0,
            $this->persistedSections(),
            'idempotent: existing privacy_addendum must NOT be duplicated on re-run',
        );
    }

    #[Test]
    public function testNewVersionCarriesNewSection(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(4, 'iso27001', 'privacy', dpoRequired: true);

        // Existing approved Document with a STALE hash → new version path.
        $existingDoc = new Document();
        $existingDoc->setTenant($tenant);
        $existingDoc->setStatus('approved');
        $existingDoc->setIsImmutable(true);
        $existingDoc->setSubstitutionVariables(['_hash' => 'previous-hash-no-match']);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($existingDoc, 777);

        $generator = $this->makeGenerator([$template], [
            'existing_document' => $existingDoc,
            // No existing section for the NEW Document → fresh section.
            'existing_section' => null,
        ]);

        $generator->generate($this->makeRun($tenant));

        $sections = $this->persistedSections();
        self::assertCount(1, $sections, 'new Document version → fresh privacy_addendum row');
        self::assertNotSame(
            $existingDoc,
            $sections[0]->getDocument(),
            'new section must be bound to the NEW Document, not the superseded one',
        );
    }
}
