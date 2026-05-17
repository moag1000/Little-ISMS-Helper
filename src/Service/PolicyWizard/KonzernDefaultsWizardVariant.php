<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Exception\Tenant\TenantOrphanException;
use App\Repository\TenantPolicySettingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard W3-B — Konzern-Defaults wizard variant.
 *
 * Sister-class to {@see WizardOrchestrator}: the Konzern-CISO uses the
 * same seven-step flow but at parent-tenant level. After Step 7 review
 * the variant does NOT render Documents — instead it persists each
 * collected input as a `TenantPolicySetting` row on the parent tenant
 * and asks {@see KonzernPushDownService} to propagate the change down
 * the holding tree.
 *
 * Architecture: `docs/plans/policy-wizard/05-architecture.md` §7.4.
 *
 * The variant does NOT replace WizardOrchestrator — controllers use
 * {@see start()} to bootstrap a flagged run, then drive the run through
 * the normal orchestrator step machinery, and finally call
 * {@see commitDefaults()} to commit + push down. This keeps the step
 * UI / validators in lock-step between Tochter wizards and Konzern
 * defaults.
 */
final class KonzernDefaultsWizardVariant
{
    /**
     * Mapping of (step-key, input-field) → TenantPolicySetting.key.
     * Mirrors {@see HierarchyOverrideValidator::SETTING_MAP} so
     * conflicts surface the same way regardless of who runs the wizard.
     *
     * @var list<array{step: string, input_field: string, setting_key: string}>
     */
    private const SETTING_MAP = [
        [
            'step' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            'input_field' => 'risk_appetite_tier',
            'setting_key' => 'risk.appetite_tier',
        ],
        [
            'step' => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            'input_field' => 'review_interval_months',
            'setting_key' => 'policy.review_interval_months',
        ],
        [
            'step' => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            'input_field' => 'backup_rpo_hours',
            'setting_key' => 'backup.rpo_hours',
        ],
        [
            'step' => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            'input_field' => 'crypto_minimum_key_length',
            'setting_key' => 'crypto.minimum_key_length',
        ],
        [
            'step' => WizardStepKeys::STEP_LIFECYCLE,
            'input_field' => 'default_review_interval_months',
            'setting_key' => 'policy.review_interval_months',
        ],
    ];

    public const INPUTS_FLAG_KEY = 'konzern_defaults';

    public function __construct(
        private readonly WizardOrchestrator $orchestrator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantPolicySettingRepository $settingRepository,
        private readonly KonzernPushDownService $pushDownService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bootstrap a Konzern-Defaults wizard run. Mirrors
     * {@see WizardOrchestrator::start()} but flags `inputs.konzern_defaults=true`
     * so the wizard UI knows it is collecting parent-tenant baselines.
     *
     * @param list<string>|null $standards Initial standards selection.
     */
    public function start(
        Tenant $konzern,
        User $user,
        ?array $standards = null,
    ): WizardRun {
        $run = $this->orchestrator->start(
            tenant: $konzern,
            user: $user,
            standards: $standards,
            mode: WizardStepKeys::MODE_FULL,
            findingRef: null,
        );

        $inputs = $run->getInputs() ?? [];
        $inputs[self::INPUTS_FLAG_KEY] = true;
        $run->setInputs($inputs);
        $this->entityManager->flush();

        $this->logger->info('PolicyWizard Konzern-Defaults run started', [
            'wizard_run_id' => $run->getId(),
            'konzern_id' => $konzern->getId(),
            'user_id' => $user->getId(),
        ]);

        return $run;
    }

    /**
     * Returns true when the run was started via {@see start()} (i.e. the
     * inputs.konzern_defaults flag is set).
     */
    public function isKonzernDefaultsRun(WizardRun $run): bool
    {
        $inputs = $run->getInputs() ?? [];
        return ($inputs[self::INPUTS_FLAG_KEY] ?? false) === true;
    }

    /**
     * Commit the run's collected inputs as TenantPolicySetting rows on
     * the parent tenant + push down to descendants.
     *
     * Replaces the document-generation path normally invoked by
     * {@see WizardOrchestrator::complete()}. Returns the per-key
     * propagation result for the controller to render on the result page.
     *
     * @return array{
     *   wizard_run: WizardRun,
     *   committed_keys: list<string>,
     *   propagation: array<string, array{
     *     affected_subsidiaries: list<int>,
     *     alva_hints_emitted: int,
     *   }>,
     * }
     */
    public function commitDefaults(WizardRun $run, User $user): array
    {
        if (!$this->isKonzernDefaultsRun($run)) {
            throw new \LogicException('WizardRun is not a Konzern-Defaults run.');
        }
        $konzern = $run->getTenant();
        if (!$konzern instanceof Tenant) {
            throw new TenantOrphanException(null, 'WizardRun has no tenant — cannot commit Konzern defaults.');
        }

        $inputs = $run->getInputs() ?? [];
        $committed = [];
        $propagation = [];

        foreach (self::SETTING_MAP as $rule) {
            $stepSlot = $inputs[$rule['step']] ?? null;
            if (!is_array($stepSlot)) {
                continue;
            }
            if (!array_key_exists($rule['input_field'], $stepSlot)) {
                continue;
            }
            $newValue = $stepSlot[$rule['input_field']];
            if ($newValue === null) {
                continue;
            }

            $this->upsertSetting($konzern, $rule['setting_key'], $newValue, $user);
            $committed[] = $rule['setting_key'];

            $propagation[$rule['setting_key']] = $this->pushDownService->propagate(
                $konzern,
                $rule['setting_key'],
                $newValue,
            );
        }

        $run->setStatus(WizardStepKeys::STATUS_COMPLETED);
        $run->setCompletedAt(new DateTimeImmutable());
        $run->setGeneratedDocumentIds([]);
        $this->entityManager->flush();

        $this->logger->info('PolicyWizard Konzern-Defaults run committed', [
            'wizard_run_id' => $run->getId(),
            'konzern_id' => $konzern->getId(),
            'committed_keys' => $committed,
        ]);

        return [
            'wizard_run' => $run,
            'committed_keys' => $committed,
            'propagation' => $propagation,
        ];
    }

    private function upsertSetting(Tenant $tenant, string $key, mixed $value, User $user): void
    {
        $setting = $this->settingRepository->findOneByTenantAndKey($tenant, $key);
        if (!$setting instanceof TenantPolicySetting) {
            $setting = new TenantPolicySetting();
            $setting->setTenant($tenant);
            $setting->setKey($key);
            $this->entityManager->persist($setting);
        }
        $setting->setValue($value);
        $setting->setUpdatedAt(new DateTimeImmutable());
        $setting->setUpdatedByUser($user);
    }
}
