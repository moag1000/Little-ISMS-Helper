<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\ExistingDocumentInventoryService;
use App\Service\PolicyWizard\Step\BestandsaufnahmeStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-C — BestandsaufnahmeStep tests.
 *
 * Exercises the brownfield-detection gate, the per-row decision
 * validation contract, and the persisted-shape guarantee under
 * `WizardRun.inputs.bestandsaufnahme.decisions`.
 */
#[AllowMockObjectsWithoutExpectations]
final class BestandsaufnahmeStepTest extends TestCase
{
    private function makeRun(string $mode = WizardStepKeys::MODE_FULL): WizardRun
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setMode($mode);
        $run->setStep(WizardStepKeys::STEP_BESTANDSAUFNAHME);
        return $run;
    }

    private function makeInventoryServiceWithRows(array $rows): ExistingDocumentInventoryService
    {
        $svc = $this->createMock(ExistingDocumentInventoryService::class);
        $svc->method('inventoryFor')->willReturn($rows);
        return $svc;
    }

    #[Test]
    public function isApplicableTrueForBrownfieldFullModeRun(): void
    {
        $svc = $this->makeInventoryServiceWithRows([
            [
                'id' => 11,
                'title' => 'ISMS-Leitlinie',
                'documentType' => 'policy',
                'lastApprovedAt' => null,
                'ownerName' => null,
                'hasPolicyWizardTag' => false,
                'suggestedAction' => 'review',
            ],
        ]);
        $step = new BestandsaufnahmeStep($svc);
        $run = $this->makeRun();

        self::assertTrue($step->isApplicable($run), 'Full-mode brownfield run must enter Step 0.');
    }

    #[Test]
    public function isApplicableFalseForGreenfieldTenant(): void
    {
        $svc = $this->makeInventoryServiceWithRows([]);
        $step = new BestandsaufnahmeStep($svc);
        $run = $this->makeRun();

        self::assertFalse($step->isApplicable($run), 'Greenfield tenant must skip Step 0.');
    }

    #[Test]
    public function isApplicableFalseInTargetedAndSandboxModes(): void
    {
        $svc = $this->makeInventoryServiceWithRows([
            [
                'id' => 11,
                'title' => 'ISMS-Leitlinie',
                'documentType' => 'policy',
                'lastApprovedAt' => null,
                'ownerName' => null,
                'hasPolicyWizardTag' => false,
                'suggestedAction' => 'review',
            ],
        ]);
        $step = new BestandsaufnahmeStep($svc);

        $targeted = $this->makeRun(WizardStepKeys::MODE_TARGETED);
        self::assertFalse($step->isApplicable($targeted), 'Targeted re-runs must skip Step 0.');

        $sandbox = $this->makeRun(WizardStepKeys::MODE_SANDBOX);
        self::assertFalse($step->isApplicable($sandbox), 'Sandbox runs must skip Step 0.');
    }

    #[Test]
    public function isApplicableFalseWhenAllExistingDocumentsAreWizardManaged(): void
    {
        $svc = $this->makeInventoryServiceWithRows([
            [
                'id' => 22,
                'title' => 'ISMS-Leitlinie (wizard-managed)',
                'documentType' => 'policy',
                'lastApprovedAt' => null,
                'ownerName' => null,
                'hasPolicyWizardTag' => true,
                'suggestedAction' => 'keep',
            ],
        ]);
        $step = new BestandsaufnahmeStep($svc);
        $run = $this->makeRun();

        self::assertFalse($step->isApplicable($run), 'All wizard-managed → nothing to triage; skip Step 0.');
    }

    #[Test]
    public function validatePersistsDecisionsAndRejectsMissingTargetTopic(): void
    {
        $svc = $this->makeInventoryServiceWithRows([
            [
                'id' => 11,
                'title' => 'ISMS-Leitlinie',
                'documentType' => 'policy',
                'lastApprovedAt' => null,
                'ownerName' => null,
                'hasPolicyWizardTag' => false,
                'suggestedAction' => 'review',
            ],
            [
                'id' => 12,
                'title' => 'Crypto Policy 2018',
                'documentType' => 'policy',
                'lastApprovedAt' => null,
                'ownerName' => null,
                'hasPolicyWizardTag' => false,
                'suggestedAction' => 'replace',
            ],
        ]);
        $step = new BestandsaufnahmeStep($svc);
        $run = $this->makeRun();

        $input = [
            'decisions' => [
                11 => ['action' => 'replace', 'rationale' => 'Out of date'],
                // 12 has merge_into_topic but no target_topic → must error.
                12 => ['action' => 'merge_into_topic', 'rationale' => ''],
            ],
        ];

        $result = $step->validate($run, $input);

        self::assertArrayHasKey('decisions.12', $result['errors'], 'merge without target_topic must error.');
        self::assertContains(
            'policy_wizard.error.bestandsaufnahme.target_topic_required',
            $result['errors']['decisions.12'],
        );

        // The replace decision must be normalised + present.
        $normalised = $result['normalised_input']['decisions'];
        self::assertSame('replace', $normalised[11]['action']);
        self::assertNull($normalised[11]['target_topic']);
        self::assertSame('Out of date', $normalised[11]['rationale']);

        // After persist the slot must surface under the canonical key.
        $step->persist($run, $result['normalised_input']);
        self::assertSame(
            $result['normalised_input'],
            $run->getInputs()[WizardStepKeys::STEP_BESTANDSAUFNAHME] ?? null,
        );
    }
}
