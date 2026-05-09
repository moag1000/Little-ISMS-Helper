<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\LifecycleStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Form-Audit follow-up (May 2026) — covers the LifecycleStep
 * enhancements:
 *  - per_policy_overrides[template_key] persists per-template.
 *  - approver_per_template[template_key] persists user-id INTs.
 *  - default_approver_user_id (tenant-wide fallback) is collected.
 *  - role-strings (e.g. 'ROLE_CISO') are REJECTED — picker must yield
 *    user-ids.
 *  - empty per_policy_overrides remains valid (advanced/optional).
 */
final class LifecycleStepEnhancementsTest extends TestCase
{
    private function makeRun(): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted(['iso27001']);
        $run->setStep(WizardStepKeys::STEP_LIFECYCLE);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs([]);
        return $run;
    }

    #[Test]
    public function testPerPolicyOverridesPersist(): void
    {
        $step = new LifecycleStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'default_review_interval_months' => 12,
            'per_policy_overrides' => [
                'iso27001.access_control' => 6,
                'iso27001.crypto_policy' => 24,
            ],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['iso27001.access_control' => 6, 'iso27001.crypto_policy' => 24],
            $result['normalised_input']['per_policy_overrides'],
        );
    }

    #[Test]
    public function testApproverPerTemplatePersistsAsUserId(): void
    {
        $step = new LifecycleStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'default_review_interval_months' => 12,
            'approver_per_template' => [
                'iso27001.access_control' => '42',
                'iso27001.crypto_policy' => 17,
            ],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['iso27001.access_control' => 42, 'iso27001.crypto_policy' => 17],
            $result['normalised_input']['approver_per_template'],
        );
    }

    #[Test]
    public function testDefaultApproverFallback(): void
    {
        $step = new LifecycleStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'default_review_interval_months' => 12,
            'default_approver_user_id' => '99',
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(99, $result['normalised_input']['default_approver_user_id']);
    }

    #[Test]
    public function testRoleStringRejectedRequiresUserId(): void
    {
        $step = new LifecycleStep();
        $run = $this->makeRun();

        // Old-style role string in approver map should be flagged.
        $result = $step->validate($run, [
            'default_review_interval_months' => 12,
            'approver_per_template' => [
                'iso27001.access_control' => 'ROLE_CISO',
            ],
        ]);

        self::assertNotEmpty($result['errors']['approver_per_template'] ?? []);
        self::assertContains(
            'policy_wizard.error.approver_must_be_user_id',
            $result['errors']['approver_per_template'],
        );

        // Same for the default fallback.
        $result2 = $step->validate($run, [
            'default_review_interval_months' => 12,
            'default_approver_user_id' => 'ROLE_ADMIN',
        ]);
        self::assertNotEmpty($result2['errors']['default_approver_user_id'] ?? []);
    }

    #[Test]
    public function testEmptyOverridesAllowed(): void
    {
        $step = new LifecycleStep();
        $run = $this->makeRun();

        $result = $step->validate($run, [
            'default_review_interval_months' => 12,
            'per_policy_overrides' => [],
            'approver_per_template' => [],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['normalised_input']['per_policy_overrides']);
        self::assertSame([], $result['normalised_input']['approver_per_template']);
        self::assertNull($result['normalised_input']['default_approver_user_id']);
    }
}
