<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W4-D / DORA Art. 28.8 — confirms that every ICT-critical supplier
 * has a documented exit strategy.
 *
 * The {@see \App\Entity\Supplier} entity already carries the DORA
 * fields `hasExitStrategy` (bool) and `exitStrategyDocument` (link to
 * a {@see \App\Entity\Document}). This check enforces the Art. 28.8
 * obligation: every supplier flagged `ictCriticality = critical`
 * MUST have `hasExitStrategy = true` AND an attached document.
 *
 * `important` ICT-third-parties are excluded from the strict gate at
 * this layer because Art. 28.8 explicitly anchors only on
 * "supporting critical or important functions"; the wizard surfaces
 * `important` as a soft warning via the dedicated policy template
 * (DoraExtensionCatalogue → `supplier_exit`).
 */
final class DoraExitStrategyDocumentedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_exit_strategy_documented';
    private const STANDARD = 'dora';
    private const ICT_CRITICAL = 'critical';

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

        $criticalSuppliers = $this->supplierRepository->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.ictCriticality = :crit')
            ->setParameter('tenant', $tenant)
            ->setParameter('crit', self::ICT_CRITICAL)
            ->getQuery()
            ->getResult();

        $total = count($criticalSuppliers);
        if ($total === 0) {
            // Nothing to gate — Art. 28.8 trivially satisfied.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['critical_ict_suppliers' => 0],
            );
        }

        $missing = [];
        foreach ($criticalSuppliers as $supplier) {
            if (!$supplier->hasExitStrategy() || $supplier->getExitStrategyDocument() === null) {
                $missing[] = [
                    'supplier_id' => $supplier->getId(),
                    'name' => $supplier->getName(),
                    'has_flag' => $supplier->hasExitStrategy(),
                    'has_document' => $supplier->getExitStrategyDocument() !== null,
                ];
            }
        }

        $documented = $total - count($missing);
        if ($missing === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'critical_ict_suppliers' => $total,
                    'with_exit_strategy' => $documented,
                ],
            );
        }

        $score = round(($documented / $total) * 100, 1);
        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'critical_ict_suppliers' => $total,
                'with_exit_strategy' => $documented,
                'missing_count' => count($missing),
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_supplier_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($missing, 0, 5),
            ],
        );
    }
}
