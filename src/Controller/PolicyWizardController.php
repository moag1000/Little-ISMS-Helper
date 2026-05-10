<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IndustryPresetBundle;
use App\Entity\WizardRun;
use App\Repository\IndustryPresetBundleRepository;
use App\Repository\PersonRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\UserRepository;
use App\Repository\WizardRunRepository;
use App\Security\Voter\PolicyWizardVoter;
use App\Repository\DocumentRepository;
use App\Service\PolicyWizard\CrossCoverageCalculator;
use App\Service\PolicyWizard\ExistingDocumentInventoryService;
use App\Service\PolicyWizard\ExistingDocumentMatcher;
use App\Service\PolicyWizard\HierarchyConflictException;
use App\Service\PolicyWizard\KonzernDefaultsWizardVariant;
use App\Service\PolicyWizard\StepEvaluator;
use App\Service\PolicyWizard\StepValidationException;
use App\Service\PolicyWizard\WizardOrchestrator;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Policy-Wizard Controller — Phase 4-C / Sprint W2-B.
 *
 * HTTP surface for the seven-step Policy-Wizard. Class-level voter check
 * gates everything behind {@see PolicyWizardVoter::RUN_FULL}; per-action
 * voter checks tighten scope for sandbox/targeted modes (the CISO/Admin
 * baseline already covers them via role hierarchy, but we re-vote with
 * the right attribute so the audit log is precise).
 *
 * Spec: `docs/plans/policy-wizard/05-architecture.md` §6 + §7.
 */
#[Route('/policy-wizard', name: 'app_policy_wizard_')]
final class PolicyWizardController extends AbstractController
{
    public function __construct(
        private readonly WizardOrchestrator $orchestrator,
        private readonly StepEvaluator $stepEvaluator,
        private readonly WizardRunRepository $wizardRunRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly KonzernDefaultsWizardVariant $konzernDefaultsVariant,
        private readonly IndustryPresetBundleRepository $presetBundleRepository,
        private readonly ExistingDocumentInventoryService $inventoryService,
        private readonly ExistingDocumentMatcher $documentMatcher,
        private readonly DocumentRepository $documentRepository,
        private readonly UserRepository $userRepository,
        private readonly PolicyTemplateRepository $policyTemplateRepository,
        private readonly PersonRepository $personRepository,
        private readonly CrossCoverageCalculator $crossCoverageCalculator,
    ) {
    }

    /**
     * Landing page — shows in-progress runs and the three start modes.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $openRuns = $tenant !== null
            ? $this->wizardRunRepository->findOpenForTenant($tenant)
            : [];

        return $this->render('policy_wizard/index.html.twig', [
            'open_runs' => $openRuns,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Start a new wizard run. Mode is supplied via POST and validated
     * against {@see WizardStepKeys}.
     */
    #[Route('/start', name: 'start', methods: ['POST'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    #[IsCsrfTokenValid('policy_wizard_start', tokenKey: '_token')]
    public function start(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.no_tenant', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $modeInput = (string) $request->request->get('mode', WizardStepKeys::MODE_FULL);
        $mode = match ($modeInput) {
            WizardStepKeys::MODE_FULL,
            WizardStepKeys::MODE_TARGETED,
            WizardStepKeys::MODE_SANDBOX => $modeInput,
            default => WizardStepKeys::MODE_FULL,
        };

        // Per-mode authorisation (audit trail precision).
        $attribute = match ($mode) {
            WizardStepKeys::MODE_TARGETED => PolicyWizardVoter::RUN_TARGETED,
            WizardStepKeys::MODE_SANDBOX => PolicyWizardVoter::RUN_SANDBOX,
            default => PolicyWizardVoter::RUN_FULL,
        };
        if (!$this->isGranted($attribute, $tenant)) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $standardsRaw = $request->request->all('standards');
        $standards = is_array($standardsRaw) && $standardsRaw !== []
            ? array_values(array_filter(array_map(
                static fn ($v): string => is_string($v) ? strtolower(trim($v)) : '',
                $standardsRaw,
            ), static fn (string $v): bool => $v !== ''))
            : null;

        $findingRefRaw = $request->request->get('finding_reference');
        $findingRef = is_string($findingRefRaw) ? trim($findingRefRaw) : null;
        if ($findingRef === '') {
            $findingRef = null;
        }

        $run = $this->orchestrator->start($tenant, $user, $standards, $mode, $findingRef);

        $this->addFlash('success', $this->translator->trans('policy_wizard.message.run_started', [], 'policy_wizard'));

        return $this->redirectToRoute('app_policy_wizard_step_show', [
            'runId' => $run->getId(),
            'step' => $run->getStep(),
        ]);
    }

    /**
     * Render the form for a single wizard step.
     */
    #[Route('/run/{runId}/step/{step}', name: 'step_show', methods: ['GET'], requirements: ['runId' => '\d+', 'step' => '[a-z_]+'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    public function stepShow(int $runId, string $step): Response
    {
        $run = $this->loadAuthorisedRun($runId);
        if (!$run instanceof WizardRun) {
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        // If the run has been advanced past this step, redirect to the
        // canonical pointer to keep the URL honest.
        if ($run->getStep() !== $step) {
            return $this->redirectToRoute('app_policy_wizard_step_show', [
                'runId' => $run->getId(),
                'step' => $run->getStep(),
            ]);
        }

        try {
            $resume = $this->orchestrator->resume($run);
        } catch (\InvalidArgumentException) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.invalid_step', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $isTerminal = $this->stepEvaluator->isTerminalStep($run, $step);
        $hierarchyConflicts = $isTerminal ? $this->orchestrator->hierarchyConflicts($run) : [];

        // Welcome step (W4-B) ships the IndustryPresetBundle picker.
        $industryPresetBundles = $step === WizardStepKeys::STEP_WELCOME
            ? $this->presetBundleRepository->findActiveBundles()
            : [];

        // W4-C — Step 0 Bestandsaufnahme inventory + topic suggestions.
        $bestandsaufnahmePayload = $step === WizardStepKeys::STEP_BESTANDSAUFNAHME
            ? $this->buildBestandsaufnahmePayload($run)
            : ['inventory_rows' => [], 'topic_suggestions_by_doc' => [], 'available_topics' => []];

        $stepExtras = $this->buildStepExtras($run, $step, $isTerminal);
        $progress = $this->buildProgressPayload($run, $step);

        return $this->render('policy_wizard/step.html.twig', array_merge([
            'run' => $run,
            'step' => $step,
            'defaults' => $resume['data']['defaults'] ?? [],
            'inputs_so_far' => $resume['data']['inputs_so_far'] ?? [],
            'standards' => $resume['data']['standards'] ?? [],
            'is_terminal' => $isTerminal,
            'hierarchy_conflicts' => $hierarchyConflicts,
            'errors' => [],
            'industry_preset_bundles' => $industryPresetBundles,
            'inventory_rows' => $bestandsaufnahmePayload['inventory_rows'],
            'topic_suggestions_by_doc' => $bestandsaufnahmePayload['topic_suggestions_by_doc'],
            'available_topics' => $bestandsaufnahmePayload['available_topics'],
            'current_step_index' => $progress['current_step_index'],
            'total_steps_in_mode' => $progress['total_steps_in_mode'],
        ], $stepExtras));
    }

    /**
     * Submit the form for a single wizard step. Advances to next step
     * on success; re-renders with errors otherwise.
     */
    #[Route('/run/{runId}/step/{step}', name: 'step_submit', methods: ['POST'], requirements: ['runId' => '\d+', 'step' => '[a-z_]+'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    #[IsCsrfTokenValid('policy_wizard_step', tokenKey: '_token')]
    public function stepSubmit(int $runId, string $step, Request $request): Response
    {
        $run = $this->loadAuthorisedRun($runId);
        if (!$run instanceof WizardRun) {
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        if ($run->getStep() !== $step) {
            return $this->redirectToRoute('app_policy_wizard_step_show', [
                'runId' => $run->getId(),
                'step' => $run->getStep(),
            ]);
        }

        $payload = $request->request->all();
        unset($payload['_token']);

        try {
            $this->orchestrator->processStep($run, $step, $payload);
        } catch (StepValidationException $e) {
            $isTerminal = $this->stepEvaluator->isTerminalStep($run, $step);
            $hierarchyConflicts = $isTerminal ? $this->orchestrator->hierarchyConflicts($run) : [];

            $industryPresetBundles = $step === WizardStepKeys::STEP_WELCOME
                ? $this->presetBundleRepository->findActiveBundles()
                : [];

            $bestandsaufnahmePayload = $step === WizardStepKeys::STEP_BESTANDSAUFNAHME
                ? $this->buildBestandsaufnahmePayload($run)
                : ['inventory_rows' => [], 'topic_suggestions_by_doc' => [], 'available_topics' => []];

            $stepExtras = $this->buildStepExtras($run, $step, $isTerminal);
            $progress = $this->buildProgressPayload($run, $step);

            // Merge the step's own defaults() output (e.g. available_locations,
            // prefilled hints) UNDER the user's submitted payload so the form
            // re-render keeps its picker/empty-state context intact. User
            // input always wins.
            $stepDefaults = [];
            try {
                $stepDefaults = $this->stepEvaluator->getStep($step)->defaults($run);
            } catch (\Throwable) {
                // Defensive: if the step has no defaults() helper, fall through.
            }
            $mergedDefaults = array_merge($stepDefaults, $payload);

            return $this->render('policy_wizard/step.html.twig', array_merge([
                'run' => $run,
                'step' => $step,
                'defaults' => $mergedDefaults,
                'inputs_so_far' => $run->getInputs() ?? [],
                'standards' => $run->getStandardsAdopted() ?? [],
                'is_terminal' => $isTerminal,
                'hierarchy_conflicts' => $hierarchyConflicts,
                'errors' => $e->errors,
                'industry_preset_bundles' => $industryPresetBundles,
                'inventory_rows' => $bestandsaufnahmePayload['inventory_rows'],
                'topic_suggestions_by_doc' => $bestandsaufnahmePayload['topic_suggestions_by_doc'],
                'available_topics' => $bestandsaufnahmePayload['available_topics'],
                'current_step_index' => $progress['current_step_index'],
                'total_steps_in_mode' => $progress['total_steps_in_mode'],
            ], $stepExtras), new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        } catch (\InvalidArgumentException) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.invalid_step', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $next = $run->getStep();
        if ($next === $step || $next === '') {
            // Flow complete — bounce to terminal step show so user can
            // press Generate (handled by complete()).
            return $this->redirectToRoute('app_policy_wizard_step_show', [
                'runId' => $run->getId(),
                'step' => $step,
            ]);
        }

        return $this->redirectToRoute('app_policy_wizard_step_show', [
            'runId' => $run->getId(),
            'step' => $next,
        ]);
    }

    /**
     * Cancel an in-progress run.
     */
    #[Route('/run/{runId}/cancel', name: 'cancel', methods: ['POST'], requirements: ['runId' => '\d+'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    #[IsCsrfTokenValid('policy_wizard_cancel', tokenKey: '_token')]
    public function cancel(int $runId): Response
    {
        $run = $this->loadAuthorisedRun($runId);
        if (!$run instanceof WizardRun) {
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $this->orchestrator->cancel($run);
        $this->addFlash('info', $this->translator->trans('policy_wizard.message.run_cancelled', [], 'policy_wizard'));

        return $this->redirectToRoute('app_policy_wizard_index');
    }

    /**
     * Generate / complete the run — invokes orchestrator::complete and
     * renders the result page.
     */
    #[Route('/run/{runId}/complete', name: 'complete', methods: ['POST'], requirements: ['runId' => '\d+'])]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    #[IsCsrfTokenValid('policy_wizard_complete', tokenKey: '_token')]
    public function complete(int $runId): Response
    {
        $run = $this->loadAuthorisedRun($runId);
        if (!$run instanceof WizardRun) {
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        try {
            $result = $this->orchestrator->complete($run);
        } catch (HierarchyConflictException $conflict) {
            $this->addFlash('danger', $this->translator->trans('policy_wizard.step.review_generate.conflicts_heading', [], 'policy_wizard'));
            $stepExtras = $this->buildStepExtras($run, $run->getStep(), true);
            $progress = $this->buildProgressPayload($run, $run->getStep());

            return $this->render('policy_wizard/step.html.twig', array_merge([
                'run' => $run,
                'step' => $run->getStep(),
                'defaults' => [],
                'inputs_so_far' => $run->getInputs() ?? [],
                'standards' => $run->getStandardsAdopted() ?? [],
                'is_terminal' => true,
                'hierarchy_conflicts' => $conflict->conflicts,
                'errors' => [],
                'current_step_index' => $progress['current_step_index'],
                'total_steps_in_mode' => $progress['total_steps_in_mode'],
            ], $stepExtras), new Response('', Response::HTTP_CONFLICT));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('app_policy_wizard_step_show', [
                'runId' => $run->getId(),
                'step' => $run->getStep(),
            ]);
        }

        $this->addFlash('success', $this->translator->trans('policy_wizard.message.run_completed', [], 'policy_wizard'));

        // Hydrate Document rows so the result template can render real
        // titles + standard badges + view/PDF links instead of the bare
        // "Document #X" placeholders. Skip the lookup when there are no
        // document_ids (sandbox / targeted-rerun without diff).
        $resultDocuments = [];
        $documentIds = $result['document_ids'] ?? [];
        if ($documentIds !== []) {
            foreach ($this->documentRepository->findBy(['id' => $documentIds]) as $doc) {
                $workflow = $this->crossCoverageCalculator->findActiveWorkflowFor($doc);
                $resultDocuments[] = [
                    'id' => $doc->getId(),
                    'title' => $doc->getOriginalFilename() ?: $doc->getFilename() ?: ('Document #' . $doc->getId()),
                    'standard' => $doc->getGeneratedFromTemplate()?->getStandard(),
                    'workflow_id' => $workflow?->getId(),
                    'workflow_status' => $workflow?->getStatus(),
                    'approval_history' => $workflow?->getApprovalHistory() ?? [],
                ];
            }
        }

        $crossCoverage = $this->crossCoverageCalculator->calculateForRun($run);

        return $this->render('policy_wizard/result.html.twig', [
            'run' => $run,
            'document_ids' => $documentIds,
            'result_documents' => $resultDocuments,
            'sandbox_preview' => $result['sandbox_preview'] ?? null,
            'cross_coverage' => $crossCoverage,
        ]);
    }

    /**
     * W4-B Industry-Preset preview — returns the bundle's metadata as
     * JSON so the Stimulus controller can pre-tick checkboxes and
     * surface an estimated document count.
     */
    #[Route(
        '/preset-preview/{key}',
        name: 'preset_preview',
        methods: ['GET'],
        requirements: ['key' => '[a-z0-9_-]+'],
    )]
    #[IsGranted('POLICY_WIZARD_RUN_FULL')]
    public function presetPreview(string $key): JsonResponse
    {
        $bundle = $this->presetBundleRepository->findByKey($key);
        if (!$bundle instanceof IndustryPresetBundle || !$bundle->isActive()) {
            return new JsonResponse(
                ['error' => 'unknown_or_inactive_bundle', 'key' => $key],
                Response::HTTP_NOT_FOUND,
            );
        }

        // Document-count estimate mirrors the welcome-step Twig preview:
        // ~4 docs per pre-selected standard. Cheap heuristic — full
        // template-driven count comes once the wizard renders.
        $estimatedDocumentCount = count($bundle->getPreselectedStandards()) * 4;

        return new JsonResponse([
            'key' => $bundle->getKey(),
            'label' => $bundle->getLabel(),
            'description' => $bundle->getDescription(),
            'preselected_standards' => $bundle->getPreselectedStandards(),
            'default_risk_appetite_tier' => $bundle->getDefaultRiskAppetiteTier(),
            'default_data_classification_levels' => $bundle->getDefaultDataClassificationLevels(),
            'default_backup_rpo_hours' => $bundle->getDefaultBackupRpoHours(),
            'default_patch_sla_critical_hours' => $bundle->getDefaultPatchSlaCriticalHours(),
            'dpo_sections_auto_enabled' => $bundle->isDpoSectionsAutoEnabled(),
            'regulatory_references' => $bundle->getRegulatoryReferences(),
            'estimated_document_count' => $estimatedDocumentCount,
        ]);
    }

    /**
     * Konzern-Defaults landing — Konzern-CISO entry point. Lists open
     * Konzern-Defaults runs (if any) and offers a "Start defaults wizard"
     * button. The voter ensures only ROLE_GROUP_CISO / ROLE_GROUP_BCM_OFFICER
     * with holding-tree access reach this surface.
     */
    #[Route('/konzern-defaults', name: 'konzern_defaults', methods: ['GET'])]
    public function konzernDefaults(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$this->isGranted(PolicyWizardVoter::KONZERN_DEFAULTS, $tenant)) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $openRuns = $tenant !== null
            ? $this->wizardRunRepository->findOpenForTenant($tenant)
            : [];

        return $this->render('policy_wizard/konzern_defaults.html.twig', [
            'open_runs' => $openRuns,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Start a Konzern-Defaults run — POST endpoint that bootstraps a
     * WizardRun flagged with `inputs.konzern_defaults=true` and routes
     * the user into the standard step flow.
     */
    #[Route('/konzern-defaults/start', name: 'konzern_defaults_start', methods: ['POST'])]
    #[IsCsrfTokenValid('policy_wizard_konzern_defaults_start', tokenKey: '_token')]
    public function konzernDefaultsStart(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.no_tenant', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_konzern_defaults');
        }

        if (!$this->isGranted(PolicyWizardVoter::KONZERN_DEFAULTS, $tenant)) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_index');
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return $this->redirectToRoute('app_policy_wizard_konzern_defaults');
        }

        $standardsRaw = $request->request->all('standards');
        $standards = is_array($standardsRaw) && $standardsRaw !== []
            ? array_values(array_filter(array_map(
                static fn ($v): string => is_string($v) ? strtolower(trim($v)) : '',
                $standardsRaw,
            ), static fn (string $v): bool => $v !== ''))
            : null;

        $run = $this->konzernDefaultsVariant->start($tenant, $user, $standards);

        $this->addFlash('success', $this->translator->trans('policy_wizard.message.run_started', [], 'policy_wizard'));

        return $this->redirectToRoute('app_policy_wizard_step_show', [
            'runId' => $run->getId(),
            'step' => $run->getStep(),
        ]);
    }

    /**
     * W4-C — assemble the per-row inventory + topic-suggestion bundle the
     * Step-0 Bestandsaufnahme template renders. Returns empty arrays when
     * the run has no tenant (defensive).
     *
     * @return array{
     *   inventory_rows: list<array<string, mixed>>,
     *   topic_suggestions_by_doc: array<int, list<array{topic: string, confidence: float}>>,
     *   available_topics: list<string>,
     * }
     */
    private function buildBestandsaufnahmePayload(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            return [
                'inventory_rows' => [],
                'topic_suggestions_by_doc' => [],
                'available_topics' => [],
            ];
        }

        $rows = $this->inventoryService->inventoryFor($tenant);
        $suggestions = [];
        foreach ($rows as $row) {
            $documentId = (int) ($row['id'] ?? 0);
            if ($documentId <= 0) {
                continue;
            }
            $document = $this->documentRepository->find($documentId);
            if ($document === null) {
                continue;
            }
            $suggestions[$documentId] = $this->documentMatcher->match($document);
        }

        return [
            'inventory_rows' => $rows,
            'topic_suggestions_by_doc' => $suggestions,
            'available_topics' => ExistingDocumentMatcher::knownTopics(),
        ];
    }

    /**
     * Build the step-specific extra context (User-pickers, policy
     * templates, preset bundles, consistency warnings) that the step
     * partials need. Centralised so the GET show + POST submit + complete
     * error paths stay in sync.
     *
     * @return array<string, mixed>
     */
    private function buildStepExtras(WizardRun $run, string $step, bool $isTerminal): array
    {
        $tenant = $run->getTenant();
        $standards = $run->getStandardsAdopted() ?? [];
        $extras = [
            'dpo_user_choices' => [],
            'bcm_officer_user_choices' => [],
            'approver_user_choices' => [],
            'policy_templates' => [],
            'preset_bundles_for_step' => [],
            'consistency_warnings' => [],
            // Person-Rollout (2026-05-08): Step 4 Roles uses
            // Person-pickers instead of bare User-id integers.
            'ciso_person_choices' => [],
            'isb_person_choices' => [],
            'dpo_person_choices' => [],
            'bcm_officer_person_choices' => [],
            'function_owner_person_choices' => [],
            'approval_chain_user_choices' => [],
            // Step 7 — curated review-summary view-model (replaces the
            // legacy raw-JSON dump). See buildReviewSummary() for shape.
            'review_summary' => [],
        ];

        // Step 4 — Roles: Person-pickers for governance roles +
        // function-owner slots, plus a User-picker for the
        // approval-chain (approval requires login).
        if ($step === WizardStepKeys::STEP_ROLES) {
            $allActive = $this->personRepository->findActiveByTenant($tenant);
            $extras['ciso_person_choices'] = $allActive;
            $extras['isb_person_choices'] = $allActive;
            // DPO commonly external — surface external advisors first.
            $extras['dpo_person_choices'] = $this->personRepository
                ->findRoleHoldersByTenant($tenant, 'consultant');
            $extras['bcm_officer_person_choices'] = $allActive;
            $extras['function_owner_person_choices'] = $allActive;
            $extras['approval_chain_user_choices'] = $this->userRepository
                ->findApproversInTenant($tenant);
        }

        // Step 5 — Operational Baselines: DPO + BCM-Officer pickers
        // and IndustryPresetBundle picker for one-shot apply.
        if ($step === WizardStepKeys::STEP_OPERATIONAL_BASELINES) {
            if (in_array('gdpr', $standards, true) || in_array('dora', $standards, true)) {
                $extras['dpo_user_choices'] = $this->userRepository->findByRoleInTenant('ROLE_DPO', $tenant);
            }
            if (in_array('bcm', $standards, true)) {
                $extras['bcm_officer_user_choices'] = $this->userRepository->findByRoleInTenant(
                    'ROLE_GROUP_BCM_OFFICER',
                    $tenant,
                );
            }
            $extras['preset_bundles_for_step'] = $this->presetBundleRepository->findActiveBundles();
        }

        // Step 6 — Lifecycle: per-template overrides + approver-picker.
        if ($step === WizardStepKeys::STEP_LIFECYCLE) {
            $templates = [];
            foreach ($standards as $std) {
                if (!is_string($std)) {
                    continue;
                }
                $templates = array_merge(
                    $templates,
                    $this->policyTemplateRepository->findActiveByStandard($std),
                );
            }
            $extras['policy_templates'] = $templates;
            $extras['approver_user_choices'] = $this->userRepository->findApproversInTenant($tenant);
        }

        // Step 7 — Review & Generate: surface non-blocking warnings +
        // build the curated, audit-friendly review-summary view-model
        // (Persona-ISB Wish: replace the raw `inputs_so_far` JSON dump
        // with a readable table — Auditor-Reizthema).
        if ($isTerminal && $step === WizardStepKeys::STEP_REVIEW_GENERATE) {
            $extras['consistency_warnings'] = $this->orchestrator->consistencyWarnings($run);
            $extras['review_summary'] = $this->buildReviewSummary($run);
        }

        return $extras;
    }

    /**
     * Build the curated review-summary view-model for Step 7.
     *
     * Each section corresponds to ONE preceding wizard step and contains
     * a hand-picked list of label/value rows — NEVER a raw JSON dump.
     * User/Person id-references are resolved to a `display` string of
     * the form "Full Name <email>" so auditors can read the summary
     * without cross-referencing the user table.
     *
     * Output shape:
     * ```
     * [
     *     [
     *         'step_key' => 'organisation_scope',
     *         'title_key' => 'policy_wizard.step.organisation_scope.title',
     *         'rows' => [
     *             ['label_key' => '...', 'value' => 'string', 'value_type' => 'text'|'badges'|'list'|'muted'],
     *             ...
     *         ],
     *     ],
     *     ...
     * ]
     * ```
     *
     * @return list<array{step_key: string, title_key: string, rows: list<array{label_key: string, value: mixed, value_type: string, params?: array<string, mixed>}>}>
     */
    private function buildReviewSummary(WizardRun $run): array
    {
        $inputs = $run->getInputs() ?? [];

        // Resolve every user-id-like value the summary references to a
        // `[id => "Full Name <email>"]` map in ONE round-trip so the
        // template never has to do per-row queries.
        $userIds = $this->collectReferencedUserIds($inputs);
        $userDisplay = $this->resolveUserDisplays($userIds);

        $personIds = $this->collectReferencedPersonIds($inputs);
        $personDisplay = $this->resolvePersonDisplays($personIds);

        $sections = [];

        // -- welcome / standards ----------------------------------------------
        $welcome = $inputs[WizardStepKeys::STEP_WELCOME] ?? [];
        if (is_array($welcome) && $welcome !== []) {
            $rows = [];
            $standards = is_array($welcome['standards'] ?? null) ? $welcome['standards'] : [];
            $rows[] = [
                'label_key' => 'policy_wizard.review.welcome.standards',
                'value' => $standards,
                'value_type' => 'badges',
            ];
            if (!empty($welcome['mode'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.welcome.mode',
                    'value' => 'policy_wizard.mode.' . $welcome['mode'] . '.label',
                    'value_type' => 'trans',
                ];
            }
            if (!empty($welcome['finding_reference'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.welcome.finding_reference',
                    'value' => (string) $welcome['finding_reference'],
                    'value_type' => 'text',
                ];
            }
            if (!empty($welcome['industry_preset_bundle_key'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.welcome.preset_bundle',
                    'value' => (string) $welcome['industry_preset_bundle_key'],
                    'value_type' => 'text',
                ];
            }
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_WELCOME,
                'title_key' => 'policy_wizard.step.welcome.title',
                'rows' => $rows,
            ];
        }

        // -- organisation scope ------------------------------------------------
        $org = $inputs[WizardStepKeys::STEP_ORG_SCOPE] ?? [];
        if (is_array($org) && $org !== []) {
            $rows = [];
            if (!empty($org['legal_name'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.org.legal_name',
                    'value' => (string) $org['legal_name'],
                    'value_type' => 'text',
                ];
            }
            if (!empty($org['scope_statement'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.org.scope_statement',
                    'value' => (string) $org['scope_statement'],
                    'value_type' => 'text',
                ];
            }
            if (!empty($org['primary_address'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.org.primary_address',
                    'value' => (string) $org['primary_address'],
                    'value_type' => 'text',
                ];
            }
            $siteIds = is_array($org['site_ids'] ?? null) ? $org['site_ids'] : [];
            $rows[] = [
                'label_key' => 'policy_wizard.review.org.site_count',
                'value' => count($siteIds),
                'value_type' => 'count',
            ];
            $rows[] = [
                'label_key' => 'policy_wizard.review.org.climate_change_wording',
                'value' => (bool) ($org['climate_change_wording'] ?? false),
                'value_type' => 'bool',
            ];
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_ORG_SCOPE,
                'title_key' => 'policy_wizard.step.organisation_scope.title',
                'rows' => $rows,
            ];
        }

        // -- roles -------------------------------------------------------------
        $roles = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        if (is_array($roles) && $roles !== []) {
            $rows = [];
            $rolesMap = is_array($roles['roles'] ?? null) ? $roles['roles'] : [];
            foreach ($rolesMap as $roleKey => $personId) {
                if (!is_string($roleKey) || $roleKey === '' || !is_int($personId)) {
                    continue;
                }
                $rows[] = [
                    'label_key' => 'policy_wizard.review.roles.role.' . $roleKey,
                    'value' => $personDisplay[$personId] ?? ('#' . $personId),
                    'value_type' => 'text',
                ];
            }
            $functionOwners = is_array($roles['function_owners'] ?? null) ? $roles['function_owners'] : [];
            $assignedFunctions = 0;
            foreach ($functionOwners as $personId) {
                if (is_int($personId)) {
                    $assignedFunctions++;
                }
            }
            if ($assignedFunctions > 0 || $functionOwners !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.roles.function_owners_count',
                    'value' => $assignedFunctions,
                    'value_type' => 'count',
                ];
            }
            $approvalChain = is_array($roles['approval_chain'] ?? null) ? $roles['approval_chain'] : [];
            if ($approvalChain !== []) {
                $chainNames = [];
                foreach ($approvalChain as $userId) {
                    if (!is_int($userId)) {
                        continue;
                    }
                    $chainNames[] = $userDisplay[$userId] ?? ('User #' . $userId);
                }
                $rows[] = [
                    'label_key' => 'policy_wizard.review.roles.approval_chain',
                    'value' => $chainNames,
                    'value_type' => 'list',
                ];
            }
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_ROLES,
                'title_key' => 'policy_wizard.step.roles.title',
                'rows' => $rows,
            ];
        }

        // -- risk classification ----------------------------------------------
        $risk = $inputs[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? [];
        if (is_array($risk) && $risk !== []) {
            $rows = [];
            if (isset($risk['risk_appetite_tier'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.risk.appetite_tier',
                    'value' => (int) $risk['risk_appetite_tier'],
                    'value_type' => 'text',
                ];
            }
            $dataLevels = is_array($risk['data_classification_levels'] ?? null)
                ? $risk['data_classification_levels'] : [];
            if ($dataLevels !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.risk.data_classification_levels',
                    'value' => array_values(array_filter(array_map('strval', $dataLevels))),
                    'value_type' => 'badges',
                ];
            }
            $schutzbedarf = is_array($risk['schutzbedarf_levels'] ?? null)
                ? $risk['schutzbedarf_levels'] : [];
            if ($schutzbedarf !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.risk.schutzbedarf_levels',
                    'value' => array_values(array_filter(array_map('strval', $schutzbedarf))),
                    'value_type' => 'badges',
                ];
            }
            $annex = is_array($risk['annex_a_applicability'] ?? null)
                ? $risk['annex_a_applicability'] : [];
            $applicableCount = 0;
            foreach ($annex as $applicable) {
                if ($applicable) {
                    $applicableCount++;
                }
            }
            if ($annex !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.risk.annex_applicable_count',
                    'value' => $applicableCount,
                    'value_type' => 'count_of',
                    'params' => ['%total%' => count($annex)],
                ];
            }
            if (isset($risk['review_interval_months'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.risk.review_interval_months',
                    'value' => (int) $risk['review_interval_months'],
                    'value_type' => 'months',
                ];
            }
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
                'title_key' => 'policy_wizard.step.risk_classification.title',
                'rows' => $rows,
            ];
        }

        // -- operational baselines --------------------------------------------
        $op = $inputs[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        if (is_array($op) && $op !== []) {
            $rows = [];
            $crypto = is_array($op['crypto_allowlist'] ?? null) ? $op['crypto_allowlist'] : [];
            if ($crypto !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.crypto_count',
                    'value' => count($crypto),
                    'value_type' => 'count',
                ];
            }
            if (isset($op['backup_rpo_hours'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.backup_rpo_hours',
                    'value' => (int) $op['backup_rpo_hours'],
                    'value_type' => 'hours',
                ];
            }
            $patch = is_array($op['patch_sla_hours'] ?? null) ? $op['patch_sla_hours'] : [];
            if ($patch !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.patch_sla_severities',
                    'value' => array_keys($patch),
                    'value_type' => 'badges',
                ];
            }
            $rto = is_array($op['continuity_rto_hours'] ?? null) ? $op['continuity_rto_hours'] : [];
            if ($rto !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.continuity_rto_count',
                    'value' => count($rto),
                    'value_type' => 'count',
                ];
            }
            $dora = is_array($op['dora'] ?? null) ? $op['dora'] : null;
            if ($dora !== null) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.dora_significant',
                    'value' => (bool) ($dora['is_significant'] ?? false),
                    'value_type' => 'bool',
                ];
                if (!empty($dora['entity_type'])) {
                    $rows[] = [
                        'label_key' => 'policy_wizard.review.op.dora_entity_type',
                        'value' => (string) $dora['entity_type'],
                        'value_type' => 'text',
                    ];
                }
                if (!empty($dora['competent_authority'])) {
                    $rows[] = [
                        'label_key' => 'policy_wizard.review.op.dora_competent_authority',
                        'value' => (string) $dora['competent_authority'],
                        'value_type' => 'text',
                    ];
                }
            }
            if (!empty($op['dpo_user_id']) && is_int($op['dpo_user_id'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.dpo',
                    'value' => $userDisplay[$op['dpo_user_id']] ?? ('User #' . $op['dpo_user_id']),
                    'value_type' => 'text',
                ];
            }
            if (!empty($op['bcm_officer_user_id']) && is_int($op['bcm_officer_user_id'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.op.bcm_officer',
                    'value' => $userDisplay[$op['bcm_officer_user_id']] ?? ('User #' . $op['bcm_officer_user_id']),
                    'value_type' => 'text',
                ];
            }
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
                'title_key' => 'policy_wizard.step.operational_baselines.title',
                'rows' => $rows,
            ];
        }

        // -- lifecycle ---------------------------------------------------------
        $lc = $inputs[WizardStepKeys::STEP_LIFECYCLE] ?? [];
        if (is_array($lc) && $lc !== []) {
            $rows = [];
            if (isset($lc['default_review_interval_months'])) {
                $months = (int) $lc['default_review_interval_months'];
                $rows[] = [
                    'label_key' => 'policy_wizard.review.lifecycle.default_review_interval',
                    'value' => $months,
                    'value_type' => 'months_with_label',
                ];
            }
            $perPolicy = is_array($lc['per_policy_overrides'] ?? null) ? $lc['per_policy_overrides'] : [];
            if ($perPolicy !== []) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.lifecycle.per_policy_overrides_count',
                    'value' => count($perPolicy),
                    'value_type' => 'count',
                ];
            }
            $approverPerTemplate = is_array($lc['approver_per_template'] ?? null)
                ? $lc['approver_per_template'] : [];
            if ($approverPerTemplate !== []) {
                $approverList = [];
                foreach ($approverPerTemplate as $templateKey => $userId) {
                    if (!is_string($templateKey) || !is_int($userId)) {
                        continue;
                    }
                    $approverList[] = sprintf(
                        '%s → %s',
                        $templateKey,
                        $userDisplay[$userId] ?? ('User #' . $userId),
                    );
                }
                if ($approverList !== []) {
                    $rows[] = [
                        'label_key' => 'policy_wizard.review.lifecycle.approver_per_template',
                        'value' => $approverList,
                        'value_type' => 'list',
                    ];
                }
            }
            if (!empty($lc['default_approver_user_id']) && is_int($lc['default_approver_user_id'])) {
                $rows[] = [
                    'label_key' => 'policy_wizard.review.lifecycle.default_approver',
                    'value' => $userDisplay[$lc['default_approver_user_id']]
                        ?? ('User #' . $lc['default_approver_user_id']),
                    'value_type' => 'text',
                ];
            }
            $rows[] = [
                'label_key' => 'policy_wizard.review.lifecycle.alva_hint_on_review',
                'value' => (bool) ($lc['alva_hint_on_review'] ?? true),
                'value_type' => 'bool',
            ];
            $sections[] = [
                'step_key' => WizardStepKeys::STEP_LIFECYCLE,
                'title_key' => 'policy_wizard.step.lifecycle.title',
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * Walk the wizard inputs to collect every User.id reference that the
     * review-summary will display. Returns DEDUPLICATED list of ints.
     *
     * @param array<string, mixed> $inputs
     * @return list<int>
     */
    private function collectReferencedUserIds(array $inputs): array
    {
        $ids = [];

        // RolesStep.approval_chain — list<int>
        $roles = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        if (is_array($roles)) {
            $chain = $roles['approval_chain'] ?? [];
            if (is_array($chain)) {
                foreach ($chain as $id) {
                    if (is_int($id) && $id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        // OperationalBaselinesStep.dpo_user_id + .bcm_officer_user_id
        $op = $inputs[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        if (is_array($op)) {
            foreach (['dpo_user_id', 'bcm_officer_user_id'] as $key) {
                $val = $op[$key] ?? null;
                if (is_int($val) && $val > 0) {
                    $ids[] = $val;
                }
            }
        }

        // LifecycleStep.default_approver_user_id + .approver_per_template[*]
        $lc = $inputs[WizardStepKeys::STEP_LIFECYCLE] ?? [];
        if (is_array($lc)) {
            $defApp = $lc['default_approver_user_id'] ?? null;
            if (is_int($defApp) && $defApp > 0) {
                $ids[] = $defApp;
            }
            $perTpl = $lc['approver_per_template'] ?? [];
            if (is_array($perTpl)) {
                foreach ($perTpl as $userId) {
                    if (is_int($userId) && $userId > 0) {
                        $ids[] = $userId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Walk the wizard inputs to collect every Person.id reference that
     * the review-summary will display.
     *
     * @param array<string, mixed> $inputs
     * @return list<int>
     */
    private function collectReferencedPersonIds(array $inputs): array
    {
        $ids = [];
        $roles = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        if (is_array($roles)) {
            foreach (['roles', 'function_owners'] as $bucket) {
                $map = $roles[$bucket] ?? [];
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $personId) {
                    if (is_int($personId) && $personId > 0) {
                        $ids[] = $personId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve User-ids to "Full Name <email>" display strings in ONE
     * findBy() call. Missing ids fall back to the placeholder format
     * "User #<id>" so the template can still render.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function resolveUserDisplays(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $u) {
            $name = trim($u->getFullName());
            $email = (string) ($u->getEmail() ?? '');
            $display = $name !== '' && $email !== ''
                ? sprintf('%s <%s>', $name, $email)
                : ($name !== '' ? $name : $email);
            $map[(int) $u->getId()] = $display !== '' ? $display : ('User #' . $u->getId());
        }
        return $map;
    }

    /**
     * Resolve Person-ids to display strings.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function resolvePersonDisplays(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->personRepository->findBy(['id' => $ids]) as $p) {
            $name = trim((string) $p->getFullName());
            $map[(int) $p->getId()] = $name !== '' ? $name : ('Person #' . $p->getId());
        }
        return $map;
    }

    /**
     * Junior-ISB Wish #3 — compute the per-mode step index + total so
     * the step.html.twig progress-bar can render "Schritt X von Y".
     *
     * Targeted re-runs follow {@see WizardStepKeys::targetedFlow()};
     * full + sandbox modes follow {@see WizardStepKeys::defaultFlow()}.
     * Steps not present in the resolved flow fall back to index 0 so
     * the bar still renders rather than crashing the page.
     *
     * @return array{current_step_index: int, total_steps_in_mode: int}
     */
    private function buildProgressPayload(WizardRun $run, string $step): array
    {
        $flow = $run->getMode() === WizardStepKeys::MODE_TARGETED
            ? WizardStepKeys::targetedFlow()
            : WizardStepKeys::defaultFlow();

        $index = array_search($step, $flow, true);
        $currentStepIndex = is_int($index) ? $index : 0;

        return [
            'current_step_index' => $currentStepIndex,
            'total_steps_in_mode' => count($flow),
        ];
    }

    /**
     * Load a wizard run + check the current user is allowed to operate
     * on it (tenant scope + run mode).
     */
    private function loadAuthorisedRun(int $runId): ?WizardRun
    {
        $run = $this->wizardRunRepository->find($runId);
        if (!$run instanceof WizardRun) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.run_not_found', [], 'policy_wizard'));
            return null;
        }

        $tenant = $run->getTenant();
        $attribute = match ($run->getMode()) {
            WizardStepKeys::MODE_TARGETED => PolicyWizardVoter::RUN_TARGETED,
            WizardStepKeys::MODE_SANDBOX => PolicyWizardVoter::RUN_SANDBOX,
            default => PolicyWizardVoter::RUN_FULL,
        };
        if (!$this->isGranted($attribute, $tenant)) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.access_denied', [], 'policy_wizard'));
            return null;
        }

        return $run;
    }
}
