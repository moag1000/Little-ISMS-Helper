<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Risk;
use App\Risk\RiskMatrixThresholds;
use App\Security\FieldVisibilityResolver;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One line a decision-maker understands without the matrix (UI wave D):
 * "Hoch (16) · ca. 120.000 €/Jahr · Asset: Webshop".
 *
 * Built only from fields the risk already has: residual band and score, the
 * expected annual loss (module risk_quant, only for roles allowed to see
 * monetary risk fields) and the affected asset.
 */
class RiskBusinessSummary
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    public function line(Risk $risk): string
    {
        $score = $risk->getResidualRiskLevel();
        $parts = [sprintf(
            '%s (%d)',
            $this->translator->trans('risk.level.' . RiskMatrixThresholds::classify($score, $risk->getTenant()), [], 'risk'),
            $score,
        )];

        $ale = $risk->getAnnualLossExpectancy();
        if ($ale !== null && $this->showsMoney()) {
            $parts[] = $this->translator->trans('risk.business.ale_per_year', [
                '%amount%' => number_format($ale, 0, ...(str_starts_with($this->translator->getLocale(), 'de') ? [',', '.'] : ['.', ','])),
            ], 'risk');
        }

        $asset = $risk->getAsset()?->getName();
        if ($asset !== null && $asset !== '') {
            $parts[] = $this->translator->trans('risk.business.asset', ['%asset%' => $asset], 'risk');
        }

        return implode(' · ', $parts);
    }

    private function showsMoney(): bool
    {
        $role = FieldVisibilityResolver::getFieldRoleMap()['Risk']['ale'] ?? 'ROLE_MANAGER';

        return $this->modules->isModuleActive('risk_quant') && $this->security->isGranted($role);
    }
}
