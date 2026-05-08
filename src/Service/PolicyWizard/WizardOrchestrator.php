<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\DocumentRepository;
use App\Repository\WizardRunRepository;
use BadMethodCallException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Policy-Wizard W2-A — public façade that drives a wizard run.
 *
 * Architecture: `docs/plans/policy-wizard/05-architecture.md` §5.
 *
 * Responsibilities:
 *  - {@see start}: bootstrap a fresh `WizardRun` (full / targeted /
 *    sandbox modes).
 *  - {@see resume}: rehydrate an in-progress run + report next step.
 *  - {@see processStep}: validate user input for a given step,
 *    persist into `WizardRun.inputs` (and any first-class columns the
 *    step decided to hoist), advance `step` to the next applicable
 *    one.
 *  - {@see complete}: invoke `DocumentGeneratorInterface::generate`,
 *    finalise lifecycle status. Wired to {@see DocumentGeneratorStub}
 *    for W2; returns empty `document_ids` until W3 lands.
 *  - {@see cancel}: mark run cancelled; delete when no documents
 *    were persisted.
 *
 * The orchestrator does NOT call `HierarchyOverrideValidator` directly
 * — it exposes {@see hierarchyConflicts} so the controller can render
 * the conflicts on the Step 7 review page (and block the submit).
 */
final class WizardOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WizardRunRepository $wizardRunRepository,
        private readonly StepEvaluator $stepEvaluator,
        private readonly DocumentGeneratorInterface $documentGenerator,
        private readonly HierarchyOverrideValidator $hierarchyValidator,
        private readonly ?ApprovalKickoffService $approvalKickoffService = null,
        private readonly ?DocumentRepository $documentRepository = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bootstrap a fresh wizard run for the tenant + user.
     *
     * @param list<string>|null $standards Initial standards selection
     *                                     (may be amended in Step 1).
     * @param string|null       $mode      One of MODE_FULL / MODE_TARGETED
     *                                     / MODE_SANDBOX. Defaults to FULL.
     * @param string|null       $findingRef Optional finding reference for
     *                                      targeted runs (P1 ISB).
     */
    public function start(
        Tenant $tenant,
        User $user,
        ?array $standards = null,
        ?string $mode = WizardStepKeys::MODE_FULL,
        ?string $findingRef = null,
    ): WizardRun {
        $mode ??= WizardStepKeys::MODE_FULL;
        if (!in_array($mode, [WizardStepKeys::MODE_FULL, WizardStepKeys::MODE_TARGETED, WizardStepKeys::MODE_SANDBOX], true)) {
            throw new InvalidArgumentException(sprintf('Unknown wizard mode: %s', $mode));
        }

        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStartedByUser($user);
        $run->setMode($mode);
        $run->setStartedAt(new DateTimeImmutable());
        $run->setStatus(
            $mode === WizardStepKeys::MODE_SANDBOX
                ? WizardStepKeys::STATUS_SANDBOX
                : WizardStepKeys::STATUS_IN_PROGRESS,
        );

        if ($standards !== null && $standards !== []) {
            $run->setStandardsAdopted(array_values($standards));
        }
        if ($findingRef !== null && $findingRef !== '') {
            $run->setFindingReference($findingRef);
        }

        // First applicable step in the chosen flow.
        $first = $this->stepEvaluator->firstStepFor($run) ?? WizardStepKeys::STEP_WELCOME;
        $run->setStep($first);
        $run->setInputs([]);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->logger->info('PolicyWizard run started', [
            'wizard_run_id' => $run->getId(),
            'tenant_id' => $tenant->getId(),
            'user_id' => $user->getId(),
            'mode' => $mode,
            'standards' => $standards,
        ]);

        return $run;
    }

    /**
     * Returns the next-step pointer + the user-facing step data
     * (defaults pre-fill + accumulated inputs so far).
     *
     * @return array{
     *   next_step: string|null,
     *   data: array{
     *     defaults: array<string, mixed>,
     *     inputs_so_far: array<string, mixed>,
     *     mode: string,
     *     status: string,
     *     standards: list<string>,
     *   }
     * }
     */
    public function resume(WizardRun $run): array
    {
        $current = $run->getStep();
        $defaults = [];
        if ($current !== '') {
            try {
                $defaults = $this->stepEvaluator->getStep($current)->defaults($run);
            } catch (InvalidArgumentException) {
                // Unknown step persisted — fall through with empty defaults.
                $defaults = [];
            }
        }

        return [
            'next_step' => $current === '' ? $this->stepEvaluator->firstStepFor($run) : $current,
            'data' => [
                'defaults' => $defaults,
                'inputs_so_far' => $run->getInputs() ?? [],
                'mode' => $run->getMode(),
                'status' => $run->getStatus(),
                'standards' => array_values($run->getStandardsAdopted() ?? []),
            ],
        ];
    }

    /**
     * Validate + persist a single step's input. Advances the run's
     * `step` pointer when validation passes.
     *
     * @param array<string, mixed> $input
     * @throws InvalidArgumentException When the step key is unknown or
     *         does not match the run's current step.
     * @throws StepValidationException When validation fails.
     */
    public function processStep(WizardRun $run, string $stepKey, array $input): WizardRun
    {
        $step = $this->stepEvaluator->getStep($stepKey);

        if ($run->getStep() !== $stepKey) {
            throw new InvalidArgumentException(sprintf(
                'Run is on step "%s" but caller submitted "%s".',
                $run->getStep(),
                $stepKey,
            ));
        }

        if (!$step->isApplicable($run)) {
            throw new InvalidArgumentException(sprintf(
                'Step "%s" is not applicable in mode "%s".',
                $stepKey,
                $run->getMode(),
            ));
        }

        $result = $step->validate($run, $input);
        $errors = $result['errors'] ?? [];
        if ($errors !== []) {
            throw new StepValidationException($stepKey, $errors);
        }

        $step->persist($run, $result['normalised_input']);

        // Advance pointer.
        $next = $this->stepEvaluator->nextStepFor($run);
        if ($next !== null) {
            $run->setStep($next);
        }

        $this->entityManager->flush();

        return $run;
    }

    /**
     * Run the document generator for a completed wizard run. For
     * sandbox runs returns an empty list of document ids and the
     * generator's preview payload (when populated). The W2 stub
     * generator throws BadMethodCallException — we swallow that and
     * return empty so the W2 wiring stays testable until W3 lands.
     *
     * @return array{document_ids: list<int>, wizard_run: WizardRun, sandbox_preview?: array<string, mixed>|null}
     */
    public function complete(WizardRun $run): array
    {
        // §11.6 / §6 Step 7: hierarchy conflicts BLOCK generation.
        $conflicts = $this->hierarchyConflicts($run);
        if ($conflicts !== []) {
            throw new HierarchyConflictException($conflicts);
        }

        $documentIds = [];
        $sandboxPreview = null;

        try {
            $result = $this->documentGenerator->generate($run);
            $documentIds = array_values($result['document_ids'] ?? []);
            $sandboxPreview = $result['sandbox_preview'] ?? null;
        } catch (BadMethodCallException $stubFailure) {
            // W2: DocumentGeneratorStub throws by design. Empty list is
            // the documented W2 contract; W3 swaps the stub for the real
            // implementation.
            $this->logger->info('PolicyWizard complete() running against stub generator', [
                'wizard_run_id' => $run->getId(),
                'stub_message' => $stubFailure->getMessage(),
            ]);
        } catch (RuntimeException $e) {
            $run->setStatus(WizardStepKeys::STATUS_FAILED);
            $run->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
            throw $e;
        }

        $isSandbox = $run->getMode() === WizardStepKeys::MODE_SANDBOX;

        $run->setGeneratedDocumentIds($documentIds);
        $run->setCompletedAt(new DateTimeImmutable());
        $run->setStatus(
            $isSandbox ? WizardStepKeys::STATUS_SANDBOX : WizardStepKeys::STATUS_COMPLETED,
        );

        $this->entityManager->flush();

        // W3-I §9.1: dispatch one `policy-approval` WorkflowInstance
        // per generated Document. Sandbox runs and missing kickoff
        // service are handled inside ApprovalKickoffService.
        if (!$isSandbox && $documentIds !== [] && $this->approvalKickoffService !== null) {
            $this->kickoffApprovalsFor($documentIds, $run);
        }

        return [
            'document_ids' => $documentIds,
            'wizard_run' => $run,
            'sandbox_preview' => $sandboxPreview,
        ];
    }

    /**
     * @param list<int> $documentIds
     */
    private function kickoffApprovalsFor(array $documentIds, WizardRun $run): void
    {
        $initiator = $run->getStartedByUser();
        if ($initiator === null || $this->documentRepository === null) {
            return;
        }
        foreach ($documentIds as $documentId) {
            $document = $this->documentRepository->find($documentId);
            if (!$document instanceof Document) {
                $this->logger->warning('PolicyWizard ApprovalKickoff: document vanished post-generate', [
                    'document_id' => $documentId,
                    'wizard_run_id' => $run->getId(),
                ]);
                continue;
            }
            $this->approvalKickoffService?->kickoff($document, $initiator);
        }
    }

    /**
     * Cancel an in-progress run. Sets status='cancelled'; deletes the
     * row when no documents have been persisted (architecture §6.4
     * "Cancel deletes the WizardRun without touching Document or
     * TenantPolicySetting").
     */
    public function cancel(WizardRun $run): void
    {
        $run->setStatus(WizardStepKeys::STATUS_CANCELLED);
        $run->setCompletedAt(new DateTimeImmutable());

        $hasDocuments = ($run->getGeneratedDocumentIds() ?? []) !== [];
        if (!$hasDocuments) {
            $this->entityManager->remove($run);
        }
        $this->entityManager->flush();
    }

    /**
     * Run the hierarchy-override validator over the run's inputs.
     * Exposed for the Step 7 controller / template so the UI can
     * surface conflicts before submit.
     *
     * @return list<array{
     *   key: string,
     *   parent_value: mixed,
     *   child_value: mixed,
     *   mode: \App\Service\TenantSettingResolver\OverrideMode,
     *   message: string,
     * }>
     */
    public function hierarchyConflicts(WizardRun $run): array
    {
        return $this->hierarchyValidator->validate($run);
    }
}
