<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Control;
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
use App\Service\AuditLogger;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\SoaAutoUpdateService;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Policy-Wizard — DocumentGenerator integration with SoaAutoUpdateService.
 *
 * Verifies that DocumentGenerator delegates SoA-status propagation to
 * SoaAutoUpdateService when wired:
 *  - persistent run  → propagateForDocument() called once per emitted Document
 *  - sandbox run     → SoaAutoUpdateService NOT called (no persistence)
 *
 * The service-internal status-bump and audit-emission semantics are
 * covered separately by {@see SoaAutoUpdateServiceTest}.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorSoaPropagationTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<string> actions logged via the audit-logger spy */
    private array $auditActions = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->auditActions = [];
    }

    private function makeTenant(int $id = 41): Tenant
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

    private function makeTemplate(int $id, string $topic, array $linkedAnnexA): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey('iso27001.' . $topic);
        $template->setStandard('iso27001');
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.iso27001.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.iso27001.' . $topic . '.v1.body');
        $template->setLinkedAnnexAControls($linkedAnnexA);
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);
        return $template;
    }

    private function makeControl(int $id, string $controlId, string $impl = 'not_started'): Control
    {
        $c = new Control();
        $c->setControlId($controlId);
        $c->setName('Control ' . $controlId);
        $c->setDescription('desc');
        $c->setCategory('cat');
        $c->setApplicable(false);
        $c->setImplementationStatus($impl);
        $reflection = new \ReflectionProperty(Control::class, 'id');
        $reflection->setValue($c, $id);
        return $c;
    }

    private function makeRun(Tenant $tenant, string $mode = WizardStepKeys::MODE_FULL): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted(['iso27001']);
        $run->setMode($mode);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([
            WizardStepKeys::STEP_ORG_SCOPE => [
                'legal_name'      => 'TestCo GmbH',
                'scope_statement' => 'Whole company.',
            ],
        ]);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 77);
        return $run;
    }

    /**
     * @param list<PolicyTemplate>      $templates
     * @param array<string, Control>    $controlsByRef
     */
    private function makeGenerator(array $templates, array $controlsByRef): DocumentGenerator
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
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $cb) {
            try {
                return $cb(null);
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
                str_ends_with($key, '.body')  => "Body for {$key}\n\nTenant: TestCo GmbH / Scope: Whole company.",
                default                       => $key,
            },
        );

        // Real SoaAutoUpdateService (final → cannot be mocked) wired
        // with an audit-logger spy. We observe SoA propagation by
        // counting `policy_wizard.soa_auto_updated` events emitted on
        // the spy: one per persistent Document, zero in sandbox mode.
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('logCustom')->willReturnCallback(
            function (string $action): void {
                $this->auditActions[] = $action;
            },
        );

        $soaService = new SoaAutoUpdateService(
            $controlRepo,
            $em,
            $auditLogger,
            null, // userRepo — multi-user path; not relevant here
            new \Psr\Log\NullLogger(),
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
            null, // documentSectionRepository
            new \Psr\Log\NullLogger(),
            null, // doraExtensionCatalogue
            null, // policySettingProvider
            null, // gdprSectionCatalogue
            $soaService,
        );
    }

    #[Test]
    public function testGenerateDocumentTriggersSoaUpdate(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'access_control', ['A.5.15']),
            $this->makeTemplate(2, 'cryptography',   ['A.8.24']),
        ];
        $controls = [
            '5.15' => $this->makeControl(501, '5.15', 'not_implemented'),
            '8.24' => $this->makeControl(502, '8.24', 'not_implemented'),
        ];
        $generator = $this->makeGenerator($templates, $controls);

        $generator->generate($this->makeRun($tenant));

        // Two emitted Documents, each with one Annex-A control bumped
        // → exactly two `policy_wizard.soa_auto_updated` audit events.
        $bumps = array_filter(
            $this->auditActions,
            static fn (string $a): bool => $a === 'policy_wizard.soa_auto_updated',
        );
        self::assertCount(
            2,
            $bumps,
            'SoaAutoUpdateService must emit one soa_auto_updated event per generated Document with linked controls',
        );
    }

    #[Test]
    public function testSandboxRunSkipsSoaUpdate(): void
    {
        $tenant = $this->makeTenant();
        $templates = [
            $this->makeTemplate(1, 'access_control', ['A.5.15']),
        ];
        $controls = [
            '5.15' => $this->makeControl(501, '5.15', 'not_implemented'),
        ];
        $generator = $this->makeGenerator($templates, $controls);

        $generator->generate($this->makeRun($tenant, mode: WizardStepKeys::MODE_SANDBOX));

        // Sandbox runs neither persist Documents nor invoke updateSoa
        // → no audit events of any kind from the SoA pipeline.
        $bumps = array_filter(
            $this->auditActions,
            static fn (string $a): bool => $a === 'policy_wizard.soa_auto_updated',
        );
        self::assertCount(
            0,
            $bumps,
            'sandbox runs do not persist Documents → SoaAutoUpdateService must NOT be invoked',
        );
    }
}
