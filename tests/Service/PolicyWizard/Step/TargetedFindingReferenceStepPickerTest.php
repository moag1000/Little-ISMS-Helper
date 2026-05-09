<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\AuditFindingRepository;
use App\Service\PolicyWizard\Step\TargetedFindingReferenceStep;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit (May 2026) — Picker tests for TargetedFindingReferenceStep.
 *
 * Asserts the AuditFinding TomSelect picker contract:
 *   1. defaults() exposes the tenant's open findings to the template.
 *   2. validate() accepts the AUDIT_FINDING:<id> prefix when the ID
 *      points at a tenant-owned finding (and rejects foreign IDs).
 *   3. validate() still accepts free-text references for legacy /
 *      external systems.
 *   4. An empty submission keeps the previously persisted reference null.
 */
#[AllowMockObjectsWithoutExpectations]
final class TargetedFindingReferenceStepPickerTest extends TestCase
{
    private function makeRun(int $tenantId = 7): WizardRun
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($tenantId);

        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setMode(WizardStepKeys::MODE_TARGETED);
        $run->setStep(WizardStepKeys::STEP_TARGETED_FINDING);
        return $run;
    }

    private function makeFinding(int $id, int $tenantId = 7, string $severity = 'high'): AuditFinding
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($tenantId);

        $finding = new AuditFinding();
        $reflection = new \ReflectionClass($finding);
        $idProp = $reflection->getProperty('id');
        // Note: in PHP 8.5 setAccessible is a no-op since 8.1; harmless.
        $idProp->setValue($finding, $id);

        $finding->setTenant($tenant);
        $finding->setFindingNumber('NCR-2026-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT));
        $finding->setTitle('Audit Finding #' . $id);
        $finding->setDescription('test');
        $finding->setSeverity($severity);
        $finding->setStatus(AuditFinding::STATUS_OPEN);
        $finding->setDueDate(new DateTimeImmutable('2026-06-30'));
        return $finding;
    }

    #[Test]
    public function testCollectExposesOpenFindingsForTenant(): void
    {
        $run = $this->makeRun();
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->expects(self::once())
            ->method('findOpenByTenant')
            ->with($run->getTenant())
            ->willReturn([
                $this->makeFinding(11, severity: 'critical'),
                $this->makeFinding(12, severity: 'medium'),
            ]);

        $step = new TargetedFindingReferenceStep($repo);
        $defaults = $step->defaults($run);

        self::assertArrayHasKey('audit_findings', $defaults);
        self::assertCount(2, $defaults['audit_findings']);
        self::assertSame(11, $defaults['audit_findings'][0]['id']);
        self::assertSame('NCR-2026-011', $defaults['audit_findings'][0]['finding_number']);
        self::assertSame('critical', $defaults['audit_findings'][0]['severity']);
        self::assertSame('2026-06-30', $defaults['audit_findings'][0]['due_date']);
    }

    #[Test]
    public function testValidateAcceptsAuditFindingPrefix(): void
    {
        $run = $this->makeRun(tenantId: 7);
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->method('find')->with(42)->willReturn($this->makeFinding(42, tenantId: 7));

        $step = new TargetedFindingReferenceStep($repo);
        $result = $step->validate($run, ['finding_reference' => 'AUDIT_FINDING:42']);

        self::assertSame([], $result['errors']);
        self::assertSame('AUDIT_FINDING:42', $result['normalised_input']['finding_reference']);
        self::assertSame(42, $result['normalised_input']['finding_audit_finding_id']);
    }

    #[Test]
    public function testValidateRejectsFindingFromForeignTenant(): void
    {
        $run = $this->makeRun(tenantId: 7);
        $repo = $this->createMock(AuditFindingRepository::class);
        // Finding belongs to tenant 9, not 7 → reject.
        $repo->method('find')->with(99)->willReturn($this->makeFinding(99, tenantId: 9));

        $step = new TargetedFindingReferenceStep($repo);
        $result = $step->validate($run, ['finding_reference' => 'AUDIT_FINDING:99']);

        self::assertNotEmpty($result['errors']['finding_reference'] ?? []);
        self::assertContains(
            'policy_wizard.error.finding_reference_unknown',
            $result['errors']['finding_reference'],
        );
        self::assertNull($result['normalised_input']['finding_audit_finding_id']);
    }

    #[Test]
    public function testValidateAcceptsFreeTextReference(): void
    {
        $run = $this->makeRun();
        $repo = $this->createMock(AuditFindingRepository::class);
        $repo->expects(self::never())->method('find');

        $step = new TargetedFindingReferenceStep($repo);
        $result = $step->validate($run, ['finding_reference' => 'NCR-2026-04 (paper finding)']);

        self::assertSame([], $result['errors']);
        self::assertSame(
            'NCR-2026-04 (paper finding)',
            $result['normalised_input']['finding_reference'],
        );
        self::assertNull($result['normalised_input']['finding_audit_finding_id']);
    }

    #[Test]
    public function testEmptyFindingReferenceAllowed(): void
    {
        $run = $this->makeRun();
        $step = new TargetedFindingReferenceStep(null);

        $result = $step->validate($run, ['finding_reference' => '   ']);

        self::assertSame([], $result['errors']);
        self::assertNull($result['normalised_input']['finding_reference']);
        self::assertNull($result['normalised_input']['finding_audit_finding_id']);
    }
}
