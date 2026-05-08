<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

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
 * W6-A §0.A.1 + §0.A.2 — DocumentGenerator section-gate honoring tests.
 *
 * Verifies that the generator:
 *  1. honors the explicit `dpoGatedSectionKeys` list when set (creates
 *     N rows instead of the default single `privacy_addendum`);
 *  2. respects per-key `approvalRole` overrides (joint role round-trips
 *     onto the persisted DocumentSection);
 *  3. defaults the role to `dpo` when no override is authored;
 *  4. falls back to the legacy single-key behaviour when
 *     `dpoGatedSectionKeys` is null/empty (W3-I backward-compat).
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorDpoSectionGateTest extends TestCase
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
        return $stub;
    }

    private function makeTemplate(
        int $id,
        string $standard,
        string $topic,
        bool $dpoRequired,
        ?array $gatedKeys = null,
        ?array $roleOverrides = null,
    ): PolicyTemplate {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setDpoSectionRequired($dpoRequired);
        $template->setDpoGatedSectionKeys($gatedKeys);
        $template->setVersion(1);

        if ($roleOverrides !== null) {
            $template->setRequiredVariables([
                [
                    'key' => 'dpo_section_role_overrides',
                    'type' => 'map',
                    'value' => $roleOverrides,
                ],
            ]);
        }

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
        $reflection->setValue($run, 142);
        return $run;
    }

    /** @param list<PolicyTemplate> $templates */
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
    public function dpoGatedSectionKeysSpawnOneSectionPerEntry(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(
            id: 1,
            standard: 'iso27001',
            topic: 'privacy',
            dpoRequired: true,
            gatedKeys: ['privacy_addendum', 'privacy_addendum_breach', 'privacy_addendum_int_transfers'],
        );
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        $sections = $this->persistedSections();
        $keys = array_map(static fn (DocumentSection $s): string => (string) $s->getSectionKey(), $sections);

        self::assertCount(3, $sections, 'one DocumentSection per gated key');
        self::assertSame(
            ['privacy_addendum', 'privacy_addendum_breach', 'privacy_addendum_int_transfers'],
            $keys,
        );
        foreach ($sections as $section) {
            self::assertSame(
                DocumentSection::APPROVAL_ROLE_DPO,
                $section->getApprovalRole(),
                'default role for gated sections is `dpo`',
            );
            self::assertSame(DocumentSection::STATUS_DRAFT, $section->getStatus());
        }
    }

    #[Test]
    public function jointRoleOverrideRoundTripsOntoPersistedSection(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(
            id: 2,
            standard: 'iso27001',
            topic: 'privacy',
            dpoRequired: true,
            gatedKeys: ['privacy_addendum', 'privacy_addendum_special_categories'],
            roleOverrides: [
                'privacy_addendum_special_categories' => DocumentSection::APPROVAL_ROLE_JOINT,
            ],
        );
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        $sections = $this->persistedSections();
        self::assertCount(2, $sections);

        $byKey = [];
        foreach ($sections as $section) {
            $byKey[(string) $section->getSectionKey()] = $section;
        }

        self::assertSame(
            DocumentSection::APPROVAL_ROLE_DPO,
            $byKey['privacy_addendum']->getApprovalRole(),
            'unmapped key keeps default `dpo` role',
        );
        self::assertSame(
            DocumentSection::APPROVAL_ROLE_JOINT,
            $byKey['privacy_addendum_special_categories']->getApprovalRole(),
            'override map controls per-key role',
        );
    }

    #[Test]
    public function legacySingleKeyFallbackWhenGatedKeysAreNull(): void
    {
        // Backward-compat: dpoSectionRequired=true + dpoGatedSectionKeys=null
        // MUST behave exactly like the W3-I implementation (one row keyed
        // `privacy_addendum`).
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(
            id: 3,
            standard: 'iso27001',
            topic: 'privacy',
            dpoRequired: true,
            gatedKeys: null,
        );
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        $sections = $this->persistedSections();
        self::assertCount(1, $sections);
        self::assertSame('privacy_addendum', $sections[0]->getSectionKey());
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $sections[0]->getApprovalRole());
    }

    #[Test]
    public function noSectionsWhenDpoSectionRequiredIsFalseEvenWithGatedKeys(): void
    {
        // Defensive: a template author may set gated keys but forget the
        // toggle. Without `dpoSectionRequired=true` the generator must
        // NOT spawn any privacy section — the toggle is the master flag.
        $tenant = $this->makeTenant();
        $template = $this->makeTemplate(
            id: 4,
            standard: 'iso27001',
            topic: 'access_control',
            dpoRequired: false,
            gatedKeys: ['privacy_addendum'],
        );
        $generator = $this->makeGenerator([$template]);

        $generator->generate($this->makeRun($tenant));

        self::assertCount(
            0,
            $this->persistedSections(),
            'master toggle dpoSectionRequired must gate the entire section-creation path',
        );
    }
}
