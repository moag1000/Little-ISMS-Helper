<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard W4-A — DocumentGenerator DORA-extension append tests.
 *
 * Verifies the §3 / Task 2 contract:
 *   - ISO 27001 bodies grow a `## DORA-Erweiterung (Art. X)` section
 *     when the run.standardsAdopted lists 'dora' AND the topic has a
 *     DORA extension entry in {@see DoraExtensionCatalogue}.
 *   - Documents resulting from the append carry the
 *     `dora-extension:applied` + `dora-validity:2025-01-17` tags
 *     (§8.5 audit-trail tagging).
 *   - When DORA is not in scope (or the topic has no extension) the
 *     ISO body is rendered verbatim — no append, no extra tags.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorDoraExtensionTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
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
        $stub->method('getFullName')->willReturn('Tester User');
        $stub->method('getEmail')->willReturn('tester@example.com');
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

    private function makeRun(Tenant $tenant, array $standards): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);

        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 99);
        return $run;
    }

    /**
     * Build a generator wired with the real {@see DoraExtensionCatalogue}.
     */
    private function makeGenerator(array $templates): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof Document && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(Document::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 2000);
            }
        });
        $em->method('flush');
        $em->method('wrapInTransaction')->willReturnCallback(
            function (callable $cb) {
                try {
                    return $cb(null);
                } catch (Throwable $err) {
                    throw $err;
                }
            },
        );

        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $templateRepo->method('findActiveByStandard')->willReturnCallback(
            static fn (string $standard): array => array_values(array_filter(
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
            'tenant.legal_name' => 'TestCo GmbH',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.dora_extension.body') => 'DORA-Erweiterung body for ' . $key,
                str_ends_with($key, '.body') => 'Base ISO body for ' . $key . ' — Tenant: {{ tenant.legal_name }}',
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
            null,
            new \Psr\Log\NullLogger(),
            new DoraExtensionCatalogue(),
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

    private function lastDocument(): Document
    {
        $docs = $this->persistedOfType(Document::class);
        self::assertNotEmpty($docs);
        return $docs[count($docs) - 1];
    }

    private function tagNames(): array
    {
        $tags = $this->persistedOfType(Tag::class);
        return array_map(static fn (Tag $t): string => (string) $t->getName(), $tags);
    }

    #[Test]
    public function testIso27001BodyAppendedWithDoraSectionWhenScopeActive(): void
    {
        $tenant = $this->makeTenant();
        // backup is a known DORA extension topic (Art. 12 vs A.8.13).
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template]);

        $run = $this->makeRun($tenant, ['iso27001', 'dora']);
        $generator->generate($run);

        $document = $this->lastDocument();
        $vars = $document->getSubstitutionVariables();
        self::assertIsArray($vars);

        // Body content lives in the document's substitution-vars hash
        // input (we hashed it). To check the actual rendered body we
        // re-call the public helper directly on the generator's render
        // surface — the description carries the first paragraph which
        // is the original ISO body.
        $description = $document->getDescription();
        self::assertIsString($description);
        self::assertStringContainsString('Base ISO body', $description);

        // Direct check on appendDoraExtensionIfApplicable — the public
        // helper exposes the append behaviour for verification.
        $appended = $generator->appendDoraExtensionIfApplicable(
            $template,
            'Base ISO body for backup',
            $run,
        );
        self::assertStringContainsString('## DORA-Erweiterung (Art. 12)', $appended);
        self::assertStringContainsString('DORA-Erweiterung body for', $appended);
    }

    #[Test]
    public function testNoExtensionWhenDoraScopeMissing(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template]);

        // ISO-only run — DORA NOT in standardsAdopted.
        $run = $this->makeRun($tenant, ['iso27001']);
        $generator->generate($run);

        $appended = $generator->appendDoraExtensionIfApplicable(
            $template,
            'Base ISO body for backup',
            $run,
        );
        self::assertSame(
            'Base ISO body for backup',
            $appended,
            'no DORA append when standardsAdopted lacks dora',
        );

        // Tags must NOT include the DORA markers.
        $names = $this->tagNames();
        self::assertNotContains('dora-extension:applied', $names);
        self::assertNotContains('dora-validity:2025-01-17', $names);

        // Same protection for unknown topics in a DORA-active run.
        $unknownTemplate = $this->makeTemplate('iso27001', 'compliance_review', 2);
        $doraRun = $this->makeRun($tenant, ['iso27001', 'dora']);
        $appendedUnknown = $generator->appendDoraExtensionIfApplicable(
            $unknownTemplate,
            'Base body — no DORA equivalent',
            $doraRun,
        );
        self::assertSame(
            'Base body — no DORA equivalent',
            $appendedUnknown,
            'topics absent from catalogue do NOT grow an extension',
        );
    }

    #[Test]
    public function testDoraExtensionTagsAppliedToDocument(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'backup');
        $generator = $this->makeGenerator([$template]);

        $run = $this->makeRun($tenant, ['iso27001', 'dora']);
        $generator->generate($run);

        // §8.5 — DORA extension tags must appear on the underlying
        // ISO Document so the audit-export view can pivot on them.
        $names = $this->tagNames();
        self::assertContains('dora-extension:applied', $names);
        self::assertContains('dora-validity:2025-01-17', $names);
        // ISO standard tag still present (this is an ISO Document).
        self::assertContains('standard:iso27001', $names);
        // Topic + wizard-run + version + policy-wizard-generated still emitted.
        self::assertContains('topic:backup', $names);
        self::assertContains('policy-wizard-generated', $names);
    }
}
