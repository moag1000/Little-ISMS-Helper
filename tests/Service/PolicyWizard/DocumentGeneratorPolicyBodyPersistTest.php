<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Editable-policy-body persistence on the DocumentGenerator.
 *
 * Locks in the four behaviours that distinguish a policy-body-aware
 * generator from the legacy translation-only path:
 *
 *  1. fresh-generation persists the rendered body to the new
 *     `policyBody` column (alongside the existing description /
 *     substitutionVariables writes).
 *  2. re-generation against an existing draft with NO post-generation
 *     edits overwrites `policyBody` in place (wizard-baseline refresh).
 *  3. re-generation against an existing approved doc with a CHANGED
 *     hash creates a NEW supersedes-chain version that ALSO carries
 *     the rendered body.
 *  4. re-generation against an existing draft WITH post-generation
 *     edits PRESERVES the edited body — the wizard never silently
 *     destroys tenant work.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorPolicyBodyPersistTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    #[Test]
    public function testFreshGenerationPersistsPolicyBody(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control');
        $generator = $this->makeGenerator([$template], existing: null);

        $run = $this->makeRun($tenant);
        $generator->generate($run);

        $docs = $this->persistedOfType(Document::class);
        self::assertCount(1, $docs, 'one template → one persisted Document');

        $doc = $docs[0];
        self::assertNotNull($doc->getPolicyBody());
        self::assertNotSame('', $doc->getPolicyBody());
        self::assertStringContainsString('Body for policy.iso27001.access_control', (string) $doc->getPolicyBody());

        // The wizard-baseline state: edit-tracking columns stay NULL
        // even though the body is persisted. Drift signal stays false.
        self::assertNull($doc->getPolicyBodyEditedAt());
        self::assertNull($doc->getPolicyBodyEditedBy());
        self::assertFalse($doc->hasPostGenerationEdits());
    }

    #[Test]
    public function testRegenerationOverwritesUneditedDraftBody(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control');

        $existing = new Document();
        $existing->setTenant($tenant);
        $existing->setFilename('policy-old.md');
        $existing->setOriginalFilename('policy-old.md');
        $existing->setMimeType('text/markdown');
        $existing->setFileSize(10);
        $existing->setFilePath('virtual:test');
        $existing->setCategory('policy');
        $existing->setStatus('draft');
        $existing->setUploadedAt(new DateTimeImmutable());
        $existing->setPolicyBody('# Old wizard baseline');
        $existing->setSha256Hash('old-hash');
        $existing->setSubstitutionVariables(['_hash' => 'old-hash']);
        $this->stampId($existing, 9001);

        $generator = $this->makeGenerator([$template], existing: $existing);

        $generator->generate($this->makeRun($tenant));

        // The draft was overwritten — same row, refreshed body.
        self::assertNotSame('# Old wizard baseline', $existing->getPolicyBody());
        self::assertStringContainsString('Body for policy.iso27001.access_control', (string) $existing->getPolicyBody());
        self::assertFalse($existing->hasPostGenerationEdits(), 'no edits introduced by wizard refresh');
    }

    #[Test]
    public function testRegenerationOfApprovedDocCreatesSupersedingDocWithBody(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control');

        $existing = new Document();
        $existing->setTenant($tenant);
        $existing->setFilename('policy-approved.md');
        $existing->setOriginalFilename('policy-approved.md');
        $existing->setMimeType('text/markdown');
        $existing->setFileSize(10);
        $existing->setFilePath('virtual:test');
        $existing->setCategory('policy');
        $existing->setStatus('approved');
        $existing->setUploadedAt(new DateTimeImmutable());
        $existing->setPolicyBody('# Old approved body');
        $existing->setSha256Hash('different-hash-from-new-render');
        $existing->setSubstitutionVariables(['_hash' => 'different-hash-from-new-render']);
        $this->stampId($existing, 9002);

        $generator = $this->makeGenerator([$template], existing: $existing);

        $generator->generate($this->makeRun($tenant));

        $docs = $this->persistedOfType(Document::class);
        self::assertCount(1, $docs, 'a fresh Document is persisted (the supersedes-chain successor)');

        $newDoc = $docs[0];
        self::assertNotSame($existing, $newDoc);
        self::assertSame($existing, $newDoc->getSupersedes(), 'new doc must point back to the old one');

        // Both old + new carry persisted bodies — old stays untouched
        // (historical evidence), new carries the freshly rendered body.
        self::assertSame('# Old approved body', $existing->getPolicyBody());
        self::assertNotNull($newDoc->getPolicyBody());
        self::assertStringContainsString('Body for policy.iso27001.access_control', (string) $newDoc->getPolicyBody());
    }

    #[Test]
    public function testRegenerationPreservesEditedDraftPolicyBody(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control');

        $existing = new Document();
        $existing->setTenant($tenant);
        $existing->setFilename('policy-edited.md');
        $existing->setOriginalFilename('policy-edited.md');
        $existing->setMimeType('text/markdown');
        $existing->setFileSize(10);
        $existing->setFilePath('virtual:test');
        $existing->setCategory('policy');
        $existing->setStatus('draft');
        $existing->setUploadedAt(new DateTimeImmutable());

        $editedBody = "# Tenant override\n\nABC GmbH adds: never expose admin keys to suppliers.";
        $existing->setPolicyBody($editedBody);
        $existing->setPolicyBodyEditedAt(new DateTimeImmutable('2026-05-08 09:00:00'));
        $editor = new User();
        $editor->setEmail('ciso@abc.example');
        $existing->setPolicyBodyEditedBy($editor);
        $existing->setSha256Hash('old-hash');
        $existing->setSubstitutionVariables(['_hash' => 'old-hash']);
        $this->stampId($existing, 9003);

        self::assertTrue($existing->hasPostGenerationEdits(), 'precondition: edited');

        $generator = $this->makeGenerator([$template], existing: $existing);
        $generator->generate($this->makeRun($tenant));

        // The edited body MUST survive — the wizard never silently
        // destroys tenant work. Substitution variables refresh to the
        // new wizard hash (so a follow-up re-run sees the up-to-date
        // baseline) but the body itself is preserved.
        self::assertSame($editedBody, $existing->getPolicyBody());
        self::assertTrue($existing->hasPostGenerationEdits(), 'drift signal stays after re-gen');
        self::assertSame($editor, $existing->getPolicyBodyEditedBy());
    }

    // ───────────────────────── infrastructure ───────────────────────────

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
        $stub->method('getFullName')->willReturn('Tester User');
        $stub->method('getEmail')->willReturn('tester@example.com');
        return $stub;
    }

    private function makeTemplate(int $id, string $standard, string $topic): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setLinkedAnnexAControls([]);
        $template->setLinkedDoraArticles([]);
        $template->setVersion(1);

        $this->stampId($template, $id);
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

        $this->stampId($run, 42);
        return $run;
    }

    /**
     * @param list<PolicyTemplate> $templates
     */
    private function makeGenerator(array $templates, ?Document $existing): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof Document && $entity->getId() === null) {
                $this->stampId($entity, count($this->persisted) + 5000);
            }
        });
        $em->method('flush')->willReturnCallback(static fn (): null => null);
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $callable) use ($em) {
            try {
                return $callable($em);
            } catch (Throwable $error) {
                throw $error;
            }
        });

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
        $documentRepo->method('findOneBy')->willReturn($existing);

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
                str_ends_with($key, '.body') => "# Body for {$key}\n\nMore content.",
                default => $key,
            },
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
        );
    }

    /** @return list<object> */
    private function persistedOfType(string $class): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $obj): bool => $obj instanceof $class,
        ));
    }

    private function stampId(object $entity, int $id): void
    {
        $reflection = new ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }
}
