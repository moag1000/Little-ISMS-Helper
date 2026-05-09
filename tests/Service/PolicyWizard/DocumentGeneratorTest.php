<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\EntityTag;
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
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard W3 — DocumentGenerator unit tests.
 *
 * Mocks the persistence + repository layer to verify the §8.1-§8.7
 * pipeline contract: per-topic Document creation, DocumentControlLink
 * fan-out, SoA max-comparator, tagging, sandbox preview, re-generation
 * detection, atomic-rollback semantics, and DPO-flag immutability gate.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    private bool $rolledBack = false;

    /**
     * @var array{persist:int, flush:int, remove:int, txn:int}
     */
    private array $emCounters = ['persist' => 0, 'flush' => 0, 'remove' => 0, 'txn' => 0];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->removed = [];
        $this->rolledBack = false;
        $this->emCounters = ['persist' => 0, 'flush' => 0, 'remove' => 0, 'txn' => 0];
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

    private function makeTemplate(
        int $id,
        string $standard,
        string $topic,
        array $linkedAnnexA = [],
        array $linkedDora = [],
    ): PolicyTemplate {
        // We use a real entity but seed via reflection so we don't need
        // setters for `id`. PolicyTemplate.id is GeneratedValue.
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setLinkedAnnexAControls($linkedAnnexA);
        $template->setLinkedDoraArticles($linkedDora);
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);

        return $template;
    }

    private function makeControl(int $id, string $controlId, ?string $impl = 'not_started', ?bool $applicable = false): Control
    {
        $control = new Control();
        $control->setControlId($controlId);
        $control->setName('Control ' . $controlId);
        $control->setDescription('desc');
        $control->setCategory('cat');
        $control->setApplicable($applicable ?? false);
        $control->setImplementationStatus($impl ?? 'not_started');

        $reflection = new \ReflectionProperty(Control::class, 'id');
        $reflection->setValue($control, $id);
        return $control;
    }

    private function makeRun(
        Tenant $tenant,
        array $standards = ['iso27001'],
        string $mode = WizardStepKeys::MODE_FULL,
    ): WizardRun {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode($mode);
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
     * Build a generator wired to mocks. Returns the generator + the
     * underlying mocks so individual tests can adjust expectations.
     *
     * @param list<PolicyTemplate> $templates
     * @param array<string, Control> $controlsByRef
     * @param array<string, mixed> $opts
     */
    private function makeGenerator(
        array $templates = [],
        array $controlsByRef = [],
        array $opts = [],
    ): DocumentGenerator {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            $this->emCounters['persist']++;
            // Stamp synthetic ID on Documents so post-persist tag/link
            // logic can read it (entityId on EntityTag).
            if ($entity instanceof Document && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(Document::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 1000);
            }
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->emCounters['flush']++;
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
            $this->emCounters['remove']++;
        });
        $shouldThrow = $opts['throw_in_transaction'] ?? null;
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $callable) use ($shouldThrow) {
            $this->emCounters['txn']++;
            if ($shouldThrow instanceof Throwable) {
                $this->rolledBack = true;
                throw $shouldThrow;
            }
            try {
                return $callable($em = null);
            } catch (Throwable $error) {
                $this->rolledBack = true;
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
        $controlRepo->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($controlsByRef): ?Control {
                $cid = $criteria['controlId'] ?? null;
                if (!is_string($cid)) {
                    return null;
                }
                return $controlsByRef[$cid] ?? null;
            },
        );

        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $dclRepo->method('findOneByDocumentAndControl')->willReturn(null);

        $documentRepo = $this->createMock(DocumentRepository::class);
        $existingDoc = $opts['existing_document'] ?? null;
        if ($existingDoc !== null) {
            $documentRepo->method('findOneBy')->willReturn($existingDoc);
        } else {
            $documentRepo->method('findOneBy')->willReturn(null);
        }

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
                str_ends_with($key, '.body') => "Body for {$key}\n\nMore content. Tenant: {{ tenant.legal_name }} / Scope: {{ tenant.scope_statement }}",
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

    #[Test]
    public function testGeneratesDocumentForEachTopic(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']),
            $this->makeTemplate(2, 'iso27001', 'cryptography', ['A.8.24']),
        ];
        $generator = $this->makeGenerator($templates);

        $run = $this->makeRun($tenant);
        $result = $generator->generate($run);

        self::assertCount(2, $result['document_ids']);
        self::assertNull($result['sandbox_preview']);
        self::assertCount(2, $this->persistedOfType(Document::class));
    }

    #[Test]
    public function testCreatesDocumentControlLinkForAnnexAReferences(): void
    {
        $tenant = $this->makeTenant();
        $controlA515 = $this->makeControl(501, '5.15');
        $controlA518 = $this->makeControl(502, '5.18');
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15', 'A.5.18']),
        ];
        $generator = $this->makeGenerator($templates, [
            '5.15' => $controlA515,
            '5.18' => $controlA518,
        ]);

        $generator->generate($this->makeRun($tenant));

        $links = $this->persistedOfType(DocumentControlLink::class);
        self::assertCount(2, $links, 'two Annex A refs → two DocumentControlLinks');
        foreach ($links as $link) {
            self::assertInstanceOf(DocumentControlLink::class, $link);
            self::assertSame(DocumentControlLink::SOURCE_POLICY_WIZARD, $link->getSource());
            self::assertSame(DocumentControlLink::EVIDENCE_POLICY, $link->getEvidenceType());
        }
    }

    #[Test]
    public function testUpdatesSoaApplicableTrue(): void
    {
        $tenant = $this->makeTenant();
        $control = $this->makeControl(601, '5.15', impl: 'not_started', applicable: false);
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']),
        ];
        $generator = $this->makeGenerator($templates, ['5.15' => $control]);

        $generator->generate($this->makeRun($tenant));

        self::assertTrue($control->isApplicable(), 'policy presence implies in-scope');
        self::assertSame('in_progress', $control->getImplementationStatus(), 'wizard bumps to in_progress');
    }

    #[Test]
    public function testSoaImplementationStatusNeverDowngrades(): void
    {
        $tenant = $this->makeTenant();
        // Already verified — must NOT be pushed back.
        $control = $this->makeControl(701, '5.15', impl: 'verified', applicable: true);
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']),
        ];
        $generator = $this->makeGenerator($templates, ['5.15' => $control]);

        $generator->generate($this->makeRun($tenant));

        self::assertSame('verified', $control->getImplementationStatus());
        self::assertTrue($control->isApplicable());
    }

    #[Test]
    public function testTagsApplied(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'dora', 'ict_third_party', linkedDora: ['Art. 9.4']),
        ];
        $generator = $this->makeGenerator($templates);

        $generator->generate($this->makeRun($tenant, ['dora']));

        $tags = $this->persistedOfType(Tag::class);
        $tagNames = array_map(static fn (Tag $t): string => $t->getName(), $tags);

        self::assertContains('policy-wizard-generated', $tagNames);
        self::assertContains('standard:dora', $tagNames);
        self::assertContains('topic:ict_third_party', $tagNames);
        self::assertContains('version:1', $tagNames);
        self::assertContains('wizard-run:42', $tagNames);
        self::assertContains(
            'dora-validity:2025-01-17',
            $tagNames,
            'DORA templates must carry the validity tag (§8.5)',
        );

        $entityTags = $this->persistedOfType(EntityTag::class);
        self::assertCount(count($tagNames), $entityTags, 'one EntityTag per Tag for the document');
        foreach ($entityTags as $et) {
            self::assertSame(Document::class, $et->getEntityClass());
        }
    }

    #[Test]
    public function testSandboxModePersistsNothing(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']),
        ];
        $generator = $this->makeGenerator($templates);

        $run = $this->makeRun($tenant, mode: WizardStepKeys::MODE_SANDBOX);
        $result = $generator->generate($run);

        self::assertSame([], $result['document_ids']);
        self::assertNotNull($result['sandbox_preview']);
        self::assertSame([], $this->persisted, 'sandbox MUST NOT persist anything');
        self::assertSame(0, $this->emCounters['flush']);
        self::assertSame(0, $this->emCounters['txn'], 'sandbox skips the persistent transaction');

        // Sandbox preview must be visible inside WizardRun.inputs for §6.4.
        $inputs = $run->getInputs();
        self::assertIsArray($inputs);
        self::assertArrayHasKey('sandbox_preview', $inputs);
    }

    #[Test]
    public function testReGenerationSkipsUnchanged(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']);
        $variables = [
            'tenant.legal_name' => 'TestCo GmbH',
            'tenant.scope_statement' => 'Whole company.',
        ];

        // Pre-build a generator once to learn the canonical hash.
        $probe = $this->makeGenerator([$template], [], ['variables' => $variables]);
        $probeRun = $this->makeRun($tenant);
        $probe->generate($probeRun);
        $producedDoc = $this->persistedOfType(Document::class)[0] ?? null;
        self::assertInstanceOf(Document::class, $producedDoc);
        $existingHash = $producedDoc->getSubstitutionVariables()['_hash'] ?? null;
        self::assertIsString($existingHash);

        // Reset and re-run with an "approved" prior document carrying the
        // SAME hash. The generator must reuse it (no new Document persisted).
        $this->setUp();

        $existing = new Document();
        $existing->setTenant($tenant);
        $existing->setStatus('approved');
        $existing->setSubstitutionVariables(['_hash' => $existingHash]);
        $existing->setIsImmutable(true);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($existing, 999);

        $generator = $this->makeGenerator([$template], [], [
            'variables' => $variables,
            'existing_document' => $existing,
        ]);
        $result = $generator->generate($this->makeRun($tenant));

        self::assertSame([999], $result['document_ids'], 'must reuse the existing approved Document');
        $newDocs = array_filter(
            $this->persistedOfType(Document::class),
            static fn (Document $d): bool => $d->getId() !== 999,
        );
        self::assertCount(0, $newDocs, 'no NEW Document should be persisted');
    }

    #[Test]
    public function testReGenerationCreatesNewVersionWhenChanged(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']);

        $existing = new Document();
        $existing->setTenant($tenant);
        $existing->setStatus('approved');
        $existing->setIsImmutable(true);
        $existing->setSubstitutionVariables(['_hash' => 'previous-hash-value']);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($existing, 555);

        $generator = $this->makeGenerator([$template], [], [
            'existing_document' => $existing,
        ]);

        $result = $generator->generate($this->makeRun($tenant));

        self::assertCount(1, $result['document_ids']);
        $newDocs = array_filter(
            $this->persistedOfType(Document::class),
            static fn (Document $d): bool => $d !== $existing && $d->getId() !== 555,
        );
        self::assertNotEmpty($newDocs, 'a NEW Document version must be created');
        $newest = array_values($newDocs)[0];
        self::assertSame($existing, $newest->getSupersedes(), 'new version must point to old via supersedes');
    }

    #[Test]
    public function testAtomicTransactionRollsBackOnError(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'iso27001', 'access_control', ['A.5.15']),
        ];
        $boom = new RuntimeException('forced failure for rollback test');
        $generator = $this->makeGenerator($templates, [], [
            'throw_in_transaction' => $boom,
        ]);

        try {
            $generator->generate($this->makeRun($tenant));
            self::fail('generator did not propagate the exception');
        } catch (RuntimeException $caught) {
            self::assertSame($boom, $caught);
        }

        self::assertTrue($this->rolledBack, 'wrapInTransaction caught + re-threw the failure');
        self::assertSame(1, $this->emCounters['txn'], 'transaction was opened exactly once');
    }

    #[Test]
    public function testDpoSectionRequiredFlaggedDocumentsNotImmediatelyImmutable(): void
    {
        // §10: only `status='approved'` flips isImmutable=true. The
        // generator emits documents in `status='draft'` regardless of
        // dpo_section_required, so DPO-flagged drafts stay editable
        // until approval workflow completes.
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(1, 'iso27001', 'privacy', ['A.5.34']);
        $template->setDpoSectionRequired(true);

        $generator = $this->makeGenerator([$template]);
        $generator->generate($this->makeRun($tenant));

        $docs = $this->persistedOfType(Document::class);
        self::assertCount(1, $docs);
        $document = $docs[0];
        self::assertFalse(
            $document->isImmutable(),
            'DPO-required policies must NOT be locked at generation time',
        );
        self::assertSame('draft', $document->getStatus(), 'generated documents start as draft (§10)');
    }
}
