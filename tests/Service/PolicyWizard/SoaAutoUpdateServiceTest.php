<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\SoaAutoUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard — SoaAutoUpdateService unit tests.
 *
 * User-mandate (2026-05-08): "wenn nur ein Nutzer dann auch die Control
 * Umsetzungsbewertung ändern jeweils".
 *
 * Verifies the status-transition matrix:
 *   not_implemented / not_started / null  →  in_progress
 *   planned                                →  in_progress
 *   in_progress                            →  unchanged (manual progress)
 *   implemented                            →  unchanged
 *
 * Plus audit-trail emission:
 *   - one `policy_wizard.soa_auto_updated` per actually-changed row
 *   - one additional `policy_wizard.soa_self_assessment` per row when
 *     the tenant has exactly one active user (no separation of duties)
 */
#[AllowMockObjectsWithoutExpectations]
final class SoaAutoUpdateServiceTest extends TestCase
{
    /** @var list<array{action: string, payload: array<string, mixed>|null}> */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        $this->auditEvents = [];
    }

    private function makeTenant(int $id = 31): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeWizardRun(int $id = 88): WizardRun
    {
        $run = new WizardRun();
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        return $run;
    }

    /**
     * @param list<string> $linkedAnnexAControls
     */
    private function makePolicyTemplate(array $linkedAnnexAControls): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setLinkedAnnexAControls($linkedAnnexAControls);
        return $template;
    }

    private function makeDocument(
        int $id,
        Tenant $tenant,
        PolicyTemplate $template,
    ): Document {
        $document = new Document();
        $document->setTenant($tenant);
        $document->setGeneratedFromTemplate($template);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);
        return $document;
    }

    private function makeControl(int $id, string $controlId, ?string $status): Control
    {
        $control = new Control();
        $control->setControlId($controlId);
        if ($status !== null) {
            $control->setImplementationStatus($status);
        }
        $reflection = new \ReflectionProperty(Control::class, 'id');
        $reflection->setValue($control, $id);
        return $control;
    }

    /**
     * @param array<string, Control|null> $controlsByRef map controlId/ref → Control
     */
    private function makeService(
        array $controlsByRef,
        ?int $singleUserCount = null,
    ): SoaAutoUpdateService {
        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($controlsByRef): ?Control {
                $candidate = $criteria['controlId'] ?? null;
                if (!is_string($candidate)) {
                    return null;
                }
                return $controlsByRef[$candidate] ?? null;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist'); // no-op; we assert mutations on $control directly

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues): void {
                $this->auditEvents[] = ['action' => $action, 'payload' => $newValues];
            },
        );

        $userRepo = null;
        if ($singleUserCount !== null) {
            $userRepo = $this->createMock(UserRepository::class);
            // Stub the chained QB → Query → getSingleScalarResult chain.
            // Query is final-ish in newer Doctrine; build via getMockBuilder
            // with disabled constructor + onlyMethods so the strict
            // return-type on QueryBuilder::getQuery() (`: Query`) is met.
            $query = $this->getMockBuilder(Query::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getSingleScalarResult'])
                ->getMock();
            $query->method('getSingleScalarResult')->willReturn($singleUserCount);
            $qb = $this->createMock(QueryBuilder::class);
            $qb->method('select')->willReturnSelf();
            $qb->method('andWhere')->willReturnSelf();
            $qb->method('setParameter')->willReturnSelf();
            $qb->method('getQuery')->willReturn($query);
            $userRepo->method('createQueryBuilder')->willReturn($qb);
        }

        return new SoaAutoUpdateService(
            $controlRepo,
            $em,
            $auditLogger,
            $userRepo,
        );
    }

    #[Test]
    public function testPropagatesNotImplementedToInProgress(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makePolicyTemplate(['A.5.15', 'A.5.16']);
        $document = $this->makeDocument(801, $tenant, $template);

        // Templates use "A.5.15" notation; resolveControl strips leading
        // "A." → repository lookup hits "5.15".
        $c1 = $this->makeControl(11, '5.15', 'not_implemented');
        $c2 = $this->makeControl(12, '5.16', null);
        $service = $this->makeService(['5.15' => $c1, '5.16' => $c2]);

        $changes = $service->propagateForDocument($document, $this->makeWizardRun());

        self::assertSame('in_progress', $c1->getImplementationStatus());
        self::assertSame('in_progress', $c2->getImplementationStatus());
        self::assertArrayHasKey('A.5.15', $changes);
        self::assertArrayHasKey('A.5.16', $changes);
        self::assertSame('in_progress', $changes['A.5.15']);
    }

    #[Test]
    public function testLeavesInProgressUntouched(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makePolicyTemplate(['A.5.17']);
        $document = $this->makeDocument(802, $tenant, $template);

        $c = $this->makeControl(13, '5.17', 'in_progress');
        $service = $this->makeService(['5.17' => $c]);

        $changes = $service->propagateForDocument($document, $this->makeWizardRun());

        self::assertSame('in_progress', $c->getImplementationStatus(), 'manual in_progress must not be overwritten');
        self::assertSame([], $changes, 'no-op runs return empty change-map');
    }

    #[Test]
    public function testLeavesImplementedUntouched(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makePolicyTemplate(['A.5.18']);
        $document = $this->makeDocument(803, $tenant, $template);

        $c = $this->makeControl(14, '5.18', 'implemented');
        $service = $this->makeService(['5.18' => $c]);

        $changes = $service->propagateForDocument($document, $this->makeWizardRun());

        self::assertSame('implemented', $c->getImplementationStatus(), 'implemented status is the target ceiling');
        self::assertSame([], $changes);
    }

    #[Test]
    public function testEmitsAuditLogPerControl(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makePolicyTemplate(['A.5.15', 'A.5.16']);
        $document = $this->makeDocument(804, $tenant, $template);

        $c1 = $this->makeControl(15, '5.15', 'not_implemented');
        $c2 = $this->makeControl(16, '5.16', 'planned');
        // Multi-user tenant (no userRepo wired) → no self-assessment events.
        $service = $this->makeService(['5.15' => $c1, '5.16' => $c2]);

        $service->propagateForDocument($document, $this->makeWizardRun(id: 90));

        $bumpedEvents = array_filter(
            $this->auditEvents,
            static fn (array $e): bool => $e['action'] === 'policy_wizard.soa_auto_updated',
        );
        self::assertCount(2, $bumpedEvents, 'one soa_auto_updated event per actually-changed control');

        $selfAssessmentEvents = array_filter(
            $this->auditEvents,
            static fn (array $e): bool => $e['action'] === 'policy_wizard.soa_self_assessment',
        );
        self::assertCount(0, $selfAssessmentEvents, 'multi-user tenants must not get self-assessment events');

        $first = array_values($bumpedEvents)[0];
        $payload = $first['payload'] ?? [];
        self::assertSame('in_progress', $payload['implementation_status'] ?? null);
        self::assertSame(804, $payload['document_id'] ?? null);
        self::assertSame(90, $payload['wizard_run_id'] ?? null);
    }

    #[Test]
    public function testSingleUserTenantEmitsSelfAssessmentEvent(): void
    {
        $tenant = $this->makeTenant();
        $template = $this->makePolicyTemplate(['A.5.15']);
        $document = $this->makeDocument(805, $tenant, $template);

        $c = $this->makeControl(17, '5.15', 'not_started');
        // singleUserCount=1 → tenant has exactly ONE active user.
        $service = $this->makeService(['5.15' => $c], singleUserCount: 1);

        $service->propagateForDocument($document, $this->makeWizardRun());

        $bumped = array_filter(
            $this->auditEvents,
            static fn (array $e): bool => $e['action'] === 'policy_wizard.soa_auto_updated',
        );
        $selfAssessment = array_filter(
            $this->auditEvents,
            static fn (array $e): bool => $e['action'] === 'policy_wizard.soa_self_assessment',
        );
        self::assertCount(1, $bumped, 'still exactly one bump event');
        self::assertCount(1, $selfAssessment, 'plus exactly one self-assessment event for the single-user-tenant edge');

        $event = array_values($selfAssessment)[0];
        $payload = $event['payload'] ?? [];
        self::assertSame('tenant.single_active_user', $payload['reason'] ?? null);
        self::assertSame('A.5.15', $payload['control_ref'] ?? null);
    }
}
