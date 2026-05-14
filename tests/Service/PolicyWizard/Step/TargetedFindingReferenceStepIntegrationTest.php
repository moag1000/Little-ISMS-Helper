<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\AuditFindingRepository;
use App\Repository\WizardRunRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\DocumentGeneratorInterface;
use App\Service\PolicyWizard\HierarchyOverrideValidator;
use App\Service\PolicyWizard\Step\TargetedFindingReferenceStep;
use App\Service\PolicyWizard\StepEvaluator;
use App\Service\PolicyWizard\WizardOrchestrator;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit (May 2026) — integration test for the AuditFinding picker
 * audit-trail link.
 *
 * When the user submits a STEP_TARGETED_FINDING form with an
 * `AUDIT_FINDING:<id>` reference picked from the TomSelect dropdown,
 * {@see WizardOrchestrator::processStep} must emit one structured
 * audit-log entry (`logCustom`) tying the WizardRun to the AuditFinding
 * entity so future auditors can prove the policy fix was triggered by
 * the finding.
 */
#[AllowMockObjectsWithoutExpectations]
final class TargetedFindingReferenceStepIntegrationTest extends TestCase
{
    /**
     * Force-set the auto-generated `id` on a test entity. Doctrine's
     * GeneratedValue normally fills it on flush; we bypass for unit tests.
     */
    private static function forceId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        $idProp = $reflection->getProperty('id');
        // Note: in PHP 8.5 setAccessible is a no-op since 8.1; harmless.
        $idProp->setValue($entity, $id);
    }

    #[Test]
    public function testAuditFindingPrefixWritesStructuredAuditLog(): void
    {
        // -- Tenant + run --------------------------------------------------
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(7);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(99);

        $run = new WizardRun();
        self::forceId($run, 555);
        $run->setTenant($tenant);
        $run->setStartedByUser($user);
        $run->setMode(WizardStepKeys::MODE_TARGETED);
        $run->setStep(WizardStepKeys::STEP_TARGETED_FINDING);

        // -- Audit finding the picker resolves -----------------------------
        $finding = new AuditFinding();
        self::forceId($finding, 42);
        $finding->setTenant($tenant);
        $finding->setFindingNumber('NCR-2026-042');
        $finding->setTitle('Open finding the wizard re-run targets');
        $finding->setDescription('test');
        $finding->setSeverity(AuditFinding::SEVERITY_HIGH);
        $finding->setStatus(AuditFinding::STATUS_OPEN);

        $findingRepo = $this->createMock(AuditFindingRepository::class);
        $findingRepo->method('find')->willReturn($finding);

        // -- Real StepEvaluator with only the picker step ------------------
        // (Other steps aren't registered → nextStepFor returns null which
        // leaves the run pointer alone — fine for this test.)
        $step = new TargetedFindingReferenceStep($findingRepo);
        $stepEvaluator = new StepEvaluator([$step]);

        // -- AuditLogger expects exactly one structured call ----------------
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logCustom')
            ->with(
                'policy_wizard.finding_link',
                'AuditFinding',
                42,
                null,
                self::callback(static function (?array $newValues): bool {
                    return is_array($newValues)
                        && ($newValues['wizard_run_id'] ?? null) === 555
                        && ($newValues['tenant_id'] ?? null) === 7
                        && ($newValues['finding_reference'] ?? null) === 'AUDIT_FINDING:42';
                }),
                self::stringContains('AuditFinding 42'),
            );

        // -- Orchestrator wiring (mocks where the integration is irrelevant)
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $orchestrator = new WizardOrchestrator(
            $em,
            $this->createMock(WizardRunRepository::class),
            $stepEvaluator,
            $this->createMock(DocumentGeneratorInterface::class),
            $this->createMock(HierarchyOverrideValidator::class),
            null,
            null,
            new \Psr\Log\NullLogger(),
            null,
            $auditLogger,
        );

        // -- Act -----------------------------------------------------------
        $orchestrator->processStep(
            $run,
            WizardStepKeys::STEP_TARGETED_FINDING,
            ['finding_reference' => 'AUDIT_FINDING:42'],
        );

        // -- Assert side-effects -------------------------------------------
        self::assertSame('AUDIT_FINDING:42', $run->getFindingReference());
    }
}
