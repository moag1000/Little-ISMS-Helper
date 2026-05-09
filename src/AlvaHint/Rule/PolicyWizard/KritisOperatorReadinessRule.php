<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;

/**
 * Policy-Wizard W5-E3 — Tier-1 KRITIS-Operator readiness hint.
 *
 * Fires on a Tenant flagged as KRITIS-Operator (tenant-policy-setting
 * `org.is_kritis_operator` = true) when no approved/published
 * IT-Sicherheitsleitlinie generated from a BSI-flagged template
 * (`standard='bsi'` AND `topic='it_security_policy'`) exists yet.
 *
 * Regulatory anchor: BSIG § 8a (Pflicht zur Umsetzung des Stands der
 * Technik) plus KRITIS-Dachgesetz — operators of critical infrastructure
 * must demonstrate a documented, leadership-approved IT-Security policy.
 * Lack of such evidence is an audit-blocking gap; therefore Tier 1 and
 * non-dismissible (Tier-1 invariant in {@see AlvaHint}).
 *
 * Architecture: `docs/plans/policy-wizard/05-architecture.md` §7.4
 * (KRITIS-spezifische Risiken) and `02-bsi-input.md` §1 + §5.
 */
final class KritisOperatorReadinessRule extends AbstractAlvaHintRule
{
    /** Bump when the rule's threshold or condition changes. */
    public const VERSION = 1;

    public const SETTING_KEY_KRITIS = 'org.is_kritis_operator';
    private const STANDARD = 'bsi';
    private const TOPIC = 'it_security_policy';

    public function __construct(
        private readonly TenantPolicySettingRepository $settingRepository,
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    public function key(): string
    {
        return 'policy_wizard.kritis_operator_readiness';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    /**
     * Hint surfaces only when both the Policy-Wizard and the BSI
     * IT-Grundschutz module are active — the CTA points to the BSI
     * wizard and would lead nowhere otherwise.
     *
     * @return array<int, string>
     */
    public function requiredModules(): array
    {
        return ['policy_wizard', 'bsi_grundschutz'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Tenant) {
            return false;
        }
        if (!$this->isKritisOperator($entity)) {
            return false;
        }
        return !$this->hasApprovedBsiItSecurityPolicy($entity);
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Tenant);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'alva_hint.kritis_operator_readiness.title',
            bodyTranslationKey: 'alva_hint.kritis_operator_readiness.body',
            bodyTranslationParams: [
                '%tenant_name%' => (string) ($entity->getName() ?? ''),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Tenant',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'alva_hint.kritis_operator_readiness.cta_label',
            actionRoute: 'app_compliance_wizard_start',
            actionRouteParams: ['wizard' => 'bsi_grundschutz'],
            requiredRoles: ['ROLE_ADMIN', 'ROLE_GROUP_CISO'],
            mood: 'thinking',
            version: self::VERSION,
        );
    }

    private function isKritisOperator(Tenant $tenant): bool
    {
        $setting = $this->settingRepository->findOneByTenantAndKey(
            $tenant,
            self::SETTING_KEY_KRITIS,
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * True when the tenant owns an approved or published, non-archived
     * Document generated from a BSI IT-Security-Policy template.
     */
    private function hasApprovedBsiItSecurityPolicy(Tenant $tenant): bool
    {
        $count = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['approved', 'published'])
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
