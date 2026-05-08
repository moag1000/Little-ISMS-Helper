<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W4-D / DORA Art. 28.3 + ITS (EU) 2024/2956 — confirms the tenant
 * maintains a Register of Information for ICT third-party providers.
 *
 * The check considers a register maintained when at least one
 * {@see \App\Entity\Supplier} row in the tenant carries an
 * `ictCriticality` flagged as `important` or `critical`. A non-empty
 * register is the minimum evidence; a richer rule (e.g. requiring an
 * LEI code, NACE code, country-of-head-office for every row) is
 * delegated to the dedicated DORA register exporter checks.
 *
 * Reference: `docs/plans/policy-wizard/03-dora-input.md` §3 (ICT
 * Third-Party Risk) + Commission Implementing Regulation (EU)
 * 2024/2956 templates.
 */
final class DoraThirdPartyRegisterMaintainedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_third_party_register_maintained';
    private const STANDARD = 'dora';

    /** @var list<string> ICT criticality values that count toward the register. */
    private const ICT_CRITICALITIES = ['important', 'critical'];

    public function __construct(
        private readonly SupplierRepository $supplierRepository,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        $count = (int) $this->supplierRepository->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.ictCriticality IN (:criticalities)')
            ->setParameter('tenant', $tenant)
            ->setParameter('criticalities', self::ICT_CRITICALITIES)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['ict_third_party_count' => $count],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['ict_third_party_count' => 0],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_supplier_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
