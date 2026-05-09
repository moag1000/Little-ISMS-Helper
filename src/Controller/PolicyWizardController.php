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

        return $this->render('policy_wizard/result.html.twig', [
            'run' => $run,
            'document_ids' => $result['document_ids'] ?? [],
            'sandbox_preview' => $result['sandbox_preview'] ?? null,
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

        // Step 7 — Review & Generate: surface non-blocking warnings.
        if ($isTerminal && $step === WizardStepKeys::STEP_REVIEW_GENERATE) {
            $extras['consistency_warnings'] = $this->orchestrator->consistencyWarnings($run);
        }

        return $extras;
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
