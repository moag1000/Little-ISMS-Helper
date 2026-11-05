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
use App\Service\Bsi\GsppReuseMap;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\SectionExtension\BsiSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\GsppSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * DocumentGenerator body extensions with the section-extension registry wired (as in
 * production): DORA keeps its own renderer, every other adopted `body_extension`
 * standard (here BSI and Grundschutz++) appends its prose, gets variable substitution
 * and tags the Document `<standard>-extension:applied`.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorBodyExtensionTest extends TestCase
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
     * Build a generator wired with the real DORA, BSI and Grundschutz++ catalogues.
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
                str_ends_with($key, '.gspp_extension.body') => '## Ergänzung Grundschutz++ für {{ tenant.legal_name }}',
                str_ends_with($key, '.bsi_extension.body') => '**BSI IT-Grundschutz-Erweiterung**',
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
            sectionExtensionRegistry: new SectionExtensionRegistry([
                new DoraExtensionCatalogue(),
                new BsiSectionCatalogue(),
                new GsppSectionCatalogue(new GsppReuseMap(__DIR__ . '/../../..')),
            ]),
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
    public function adopted_standards_append_their_sections_and_tag_the_document(): void
    {
        $template = $this->makeTemplate('iso27001', 'access_control');
        $this->makeGenerator([$template])->generate($this->makeRun($this->makeTenant(), ['iso27001', 'gspp', 'bsi']));

        $body = (string) $this->lastDocument()->getPolicyBody();
        self::assertStringContainsString('Base ISO body', $body);
        self::assertStringContainsString('## Ergänzung Grundschutz++ für TestCo GmbH', $body, 'extension prose is substituted');
        self::assertStringContainsString('**BSI IT-Grundschutz-Erweiterung**', $body);
        self::assertContains('gspp-extension:applied', $this->tagNames());
        self::assertContains('bsi-extension:applied', $this->tagNames());
    }

    #[Test]
    public function standards_not_adopted_add_nothing(): void
    {
        $template = $this->makeTemplate('iso27001', 'access_control');
        $this->makeGenerator([$template])->generate($this->makeRun($this->makeTenant(), ['iso27001']));

        self::assertStringNotContainsString('Grundschutz++', (string) $this->lastDocument()->getPolicyBody());
        self::assertNotContains('gspp-extension:applied', $this->tagNames());
    }

    #[Test]
    public function dora_extension_still_applies_with_the_registry_wired(): void
    {
        $template = $this->makeTemplate('iso27001', 'backup');
        $this->makeGenerator([$template])->generate($this->makeRun($this->makeTenant(), ['iso27001', 'dora']));

        self::assertStringContainsString('## DORA-Erweiterung', (string) $this->lastDocument()->getPolicyBody());
        self::assertContains('dora-extension:applied', $this->tagNames());
    }
}
