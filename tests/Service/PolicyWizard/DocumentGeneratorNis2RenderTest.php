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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Smoke test for NIS2 PolicyTemplate rendering through DocumentGenerator.
 *
 * Validates that:
 *   1. A NIS2 template (`standard='nis2'`) is picked up via
 *      `findActiveByStandard('nis2')` and produces a Document with the
 *      rendered body persisted.
 *   2. Variable substitution works for the canonical NIS2 placeholders
 *      ({{ tenant.legal_name }}, {{ roles.ciso.fullName }} etc.).
 *
 * The translator stub returns a body fragment that exercises the
 * substitution path rather than loading the full YAML; this keeps the
 * test fast and deterministic while still covering the wiring.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorNis2RenderTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    #[Test]
    public function testNis2TopicRendersWithSubstitutions(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeNis2Template(1, 'nis2_governance_framework');

        $generator = $this->makeGenerator([$template]);

        $run = $this->makeRun($tenant, ['nis2']);
        $generator->generate($run);

        $docs = $this->persistedOfType(Document::class);
        self::assertCount(1, $docs, 'one NIS2 template → one persisted Document');

        $body = (string) $docs[0]->getPolicyBody();
        self::assertNotSame('', $body, 'NIS2 body must persist');

        // Substitutions must have happened — raw placeholders must be gone.
        self::assertStringNotContainsString(
            '{{ tenant.legal_name }}',
            $body,
            'tenant.legal_name placeholder must be substituted',
        );
        self::assertStringNotContainsString(
            '{{ roles.ciso.fullName }}',
            $body,
            'roles.ciso.fullName placeholder must be substituted',
        );

        // And the substituted values must be present.
        self::assertStringContainsString('TestCo GmbH', $body, 'tenant legal name substituted');
        self::assertStringContainsString('Anna Tester', $body, 'CISO full name substituted');

        // The norm anchor is part of the rendered text so that auditors
        // can grep for "Art. 21" / "NIS2-RL" inside the produced doc.
        self::assertStringContainsString('NIS2', $body);
        self::assertStringContainsString('Art. 21', $body);
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

    private function makeNis2Template(int $id, string $topic): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey('nis2.' . $topic);
        $template->setStandard('nis2');
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.nis2.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.nis2.' . $topic . '.v1.body');
        $template->setNormRef('NIS2 Art. 20-21 Abs. 1');
        $template->setLinkedAnnexAControls(['A.5.1', 'A.5.2', 'A.5.3', 'A.5.4']);
        $template->setLinkedDoraArticles(null);
        $template->setApprovalChain(['ROLE_CISO', 'ROLE_TOP_MGMT']);
        $template->setReviewIntervalMonths(12);
        $template->setVersion(1);
        $template->setIsActive(true);

        $this->stampId($template, $id);
        return $template;
    }

    /**
     * @param list<string> $standards
     */
    private function makeRun(Tenant $tenant, array $standards = ['nis2']): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([
            WizardStepKeys::STEP_ORG_SCOPE => [
                'legal_name' => 'TestCo GmbH',
                'scope_statement' => 'All NIS2-relevant systems and services.',
            ],
        ]);

        $this->stampId($run, 42);
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
        $documentRepo->method('findOneBy')->willReturn(null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn([
            'tenant.legal_name' => 'TestCo GmbH',
            'tenant.scope_statement' => 'All NIS2-relevant systems and services.',
            'roles.ciso.fullName' => 'Anna Tester',
            'roles.isb.fullName' => 'Bernd ISO',
            'roles.dpo.fullName' => 'Carla DPO',
            'roles.hr.fullName' => 'Doris HR',
            'crypto.algorithms' => 'AES-256, RSA-3072, SHA-256',
            'tenant.contact_domain' => 'testco.example',
            'nis2.entity_class' => 'wesentliche Einrichtung',
            'nis2.sector' => 'Digitale Infrastruktur',
            'nis2.competent_authority' => 'BSI',
            'approval.chain' => 'CISO -> Top-Management',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'NIS2 Cybersecurity-Governance-Rahmenwerk',
                str_ends_with($key, '.body') => <<<MD
                # NIS2-Cybersecurity-Governance-Rahmenwerk der {{ tenant.legal_name }}

                *Regulatorische Grundlage: NIS2-RL Art. 20 + Art. 21 Abs. 1.*

                ## 1. Zweck
                Diese Leitlinie etabliert das NIS2-Governance-Rahmenwerk; verantwortet von
                {{ roles.ciso.fullName }} (CISO).

                ## 2. Geltungsbereich
                {{ tenant.scope_statement }}

                ## 3. Approval
                {{ approval.chain }}
                MD,
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
