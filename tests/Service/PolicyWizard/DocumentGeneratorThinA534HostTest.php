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
 * Policy-Wizard W6-C — DocumentGenerator thin A.5.34 host emission tests.
 *
 * Verifies the §0 Decision Matrix v2 row 18 contract: when the tenant
 * adopts both ISO 27001 AND GDPR, the wizard emits one thin
 * cross-reference Document at A.5.34 listing the 5 standalone privacy
 * artefacts. The thin host satisfies the ISO "shall maintain a
 * topic-specific policy" wording without duplicating content.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorThinA534HostTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    private function makeTenant(int $id = 31): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeUser(int $id = 12): User
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
    private function makeRun(Tenant $tenant, array $standards): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 555);
        return $run;
    }

    /**
     * @param list<PolicyTemplate> $templates
     */
    private function makeGenerator(array $templates): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof Document && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(Document::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 4000);
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
        $documentRepo->method('findOneBy')->willReturn(null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn([
            'tenant.legal_name' => 'TestCo GmbH',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            // Return the key verbatim — exercises the stub-body path
            // until W6-F ships the full prose translation.
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.body') => 'Base ISO body for ' . $key,
                default => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $sectionRepo->method('findOneByDocumentAndKey')->willReturn(null);

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

    /** @return list<Document> */
    private function persistedDocuments(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof Document,
        ));
    }

    private function findThinHost(): ?Document
    {
        foreach ($this->persistedDocuments() as $doc) {
            if ($doc->getEntityType() === 'iso_a534_thin_host') {
                return $doc;
            }
        }
        return null;
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
    public function testThinHostEmittedWhenIsoAndGdprActive(): void
    {
        $tenant = $this->makeTenant();
        // One arbitrary ISO template so the run has at least one
        // template to chew through; the thin host emission is independent.
        $template = $this->makeTemplate('iso27001', 'top_level');
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant, ['iso27001', 'gdpr']));

        $host = $this->findThinHost();
        self::assertNotNull($host, 'thin A.5.34 host must be emitted when ISO + GDPR are both active');
        self::assertSame('iso_a534_thin_host', $host->getEntityType());
        self::assertSame('policy', $host->getCategory());
        self::assertSame('draft', $host->getStatus());
        self::assertSame($tenant, $host->getTenant(), 'tenant_id propagated for multi-tenant scoping');

        // Marker tags are present.
        $names = $this->tagNames();
        self::assertContains('policy-wizard:thin-host', $names);
        self::assertContains('iso27001:A.5.34', $names);
        self::assertContains('topic:iso_a534_thin_host', $names);
    }

    #[Test]
    public function testThinHostSkippedWhenStandaloneOnly(): void
    {
        $tenant = $this->makeTenant();

        // ISO-only run — no GDPR scope. Thin host MUST NOT emit.
        $template = $this->makeTemplate('iso27001', 'top_level');
        $generator = $this->makeGenerator([$template]);
        $generator->generate($this->makeRun($tenant, ['iso27001']));
        self::assertNull($this->findThinHost(), 'no thin host when only ISO is adopted');

        $this->setUp();

        // GDPR-only run — no ISO scope. Thin host MUST NOT emit
        // (A.5.34 is an ISO 27001 control; without ISO scope there is
        // nothing to host the cross-reference under).
        $gdprTemplate = $this->makeTemplate('gdpr', 'privacy_policy', 2);
        $generator = $this->makeGenerator([$gdprTemplate]);
        $generator->generate($this->makeRun($tenant, ['gdpr']));
        self::assertNull(
            $this->findThinHost(),
            'no thin host when GDPR is adopted without ISO',
        );
    }

    #[Test]
    public function testThinHostBodyReferencesAllFiveStandalones(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate('iso27001', 'top_level');
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant, ['iso27001', 'gdpr']));

        $host = $this->findThinHost();
        self::assertNotNull($host);

        // The substitutionVariables manifest carries the cross-reference
        // list explicitly so audit exports can pivot on it without
        // re-parsing the body.
        $vars = $host->getSubstitutionVariables();
        self::assertIsArray($vars);
        self::assertArrayHasKey('_cross_references', $vars);
        $refs = $vars['_cross_references'];
        self::assertIsArray($refs);

        // Every one of the 5 standalone privacy artefacts per §0 v2:
        // DPO Charter, RoPA Methodology, DPIA Methodology, DSR Procedure,
        // Retention Schedule.
        self::assertContains('dpo_charter', $refs);
        self::assertContains('ropa_methodology', $refs);
        self::assertContains('dpia_methodology', $refs);
        self::assertContains('dsr_procedure', $refs);
        self::assertContains('retention_schedule', $refs);
        self::assertCount(5, $refs, 'thin host must reference exactly the 5 standalone privacy artefacts');

        // The marker `_thin_host=true` lets the audit-export view
        // distinguish thin hosts from regular ISO topic policies.
        self::assertSame(true, $vars['_thin_host'] ?? null);
        self::assertSame('A.5.34', $vars['_iso_control'] ?? null);
    }
}
