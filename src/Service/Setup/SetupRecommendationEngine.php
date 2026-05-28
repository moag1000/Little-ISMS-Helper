<?php

declare(strict_types=1);

namespace App\Service\Setup;

/**
 * SetupRecommendationEngine — extracted from DeploymentWizardController (god-class split).
 *
 * Encapsulates all framework- and module-recommendation logic that was previously
 * carried as private methods on the wizard controller.  None of these methods
 * have HTTP concerns; they operate purely on plain PHP arrays derived from
 * wizard-session data.
 *
 * Public API:
 *   - getRecommendedFrameworks(industry, employeeCount, country) → string[]
 *   - getRecommendedModules(industry, employeeCount) → string[]
 *   - getRecommendedFrameworksForIndustries(industries[], employeeCount, country) → string[]
 *   - getRecommendedModulesForIndustries(industries[], employeeCount) → string[]
 *   - applyGenericStarterBaseline() → string  (SMB-2 starter baseline helper)
 */
final class SetupRecommendationEngine
{
    public function __construct(
        private readonly \App\Repository\IndustryBaselineRepository $industryBaselineRepository,
        private readonly \App\Service\IndustryBaselineApplier $industryBaselineApplier,
        private readonly \App\Repository\TenantRepository $tenantRepository,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        private readonly \Symfony\Component\HttpKernel\KernelInterface $kernel,
    ) {
    }

    /**
     * Get recommended compliance frameworks based on industry, size, and location.
     *
     * @param string $industry       Organisation industry key
     * @param string $employeeCount  Employee count range (1-10, 11-50, 51-250, 251-1000, 1001+)
     * @param string $country        Country code (DE, AT, CH, etc.)
     * @return string[]              List of recommended framework codes
     */
    public function getRecommendedFrameworks(string $industry, string $employeeCount, string $country): array
    {
        $recommendations = ['ISO27001']; // Always recommend ISO 27001

        $isNis2Size = in_array($employeeCount, ['51-250', '251-1000', '1001+'], true);
        $isLargeOrg = in_array($employeeCount, ['251-1000', '1001+'], true);

        $isDACH = in_array($country, ['DE', 'AT', 'CH'], true);
        $isGermany = $country === 'DE';
        $isEU = in_array($country, ['DE', 'AT', 'BE', 'DK', 'FI', 'FR', 'IT', 'LU', 'NL', 'PL', 'ES', 'SE', 'CZ', 'EU_OTHER'], true);

        // Use ISO 27701 for DACH region (covers GDPR), otherwise recommend GDPR
        $privacyFramework = $isDACH ? 'ISO27701' : 'GDPR';

        switch ($industry) {
            case 'automotive':
                $recommendations[] = 'TISAX';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'financial_services':
                $recommendations[] = 'DORA';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'healthcare':
                $recommendations[] = $privacyFramework;
                if ($isGermany) {
                    $recommendations[] = 'KRITIS-HEALTH';
                }
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'pharmaceutical':
                $recommendations[] = 'GXP';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'digital_health':
                if ($isGermany) {
                    $recommendations[] = 'DIGAV';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'energy':
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'KRITIS';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'telecommunications':
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'TKG-2024';
                    $recommendations[] = 'KRITIS';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'cloud_services':
                if ($isGermany) {
                    $recommendations[] = 'BSI-C5';
                }
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'public_sector':
                if ($isGermany) {
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'critical_infrastructure':
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'KRITIS';
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'it_services':

            case 'manufacturing':
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'retail':
                $recommendations[] = $privacyFramework;
                if ($isLargeOrg) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'education':
                $recommendations[] = $privacyFramework;
                if ($isGermany) {
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                break;

            default:
                $recommendations[] = $privacyFramework;
                if ($isNis2Size && $isEU) {
                    $recommendations[] = 'NIS2';
                }
                break;
        }

        return array_unique($recommendations);
    }

    /**
     * Get recommended modules based on industry and company size.
     *
     * @param string $industry      Organisation industry key
     * @param string $employeeCount Employee count range
     * @return string[]             List of recommended module keys
     */
    public function getRecommendedModules(string $industry, string $employeeCount): array
    {
        $recommendations = [];

        $isLargeOrg = in_array($employeeCount, ['251-1000', '1001+'], true);
        $isMediumOrg = in_array($employeeCount, ['51-250', '251-1000', '1001+'], true);
        $isSmallOrg = in_array($employeeCount, ['1-10', '11-50'], true);

        // Core modules recommended for all
        $recommendations[] = 'asset_management';
        $recommendations[] = 'risk_management';
        $recommendations[] = 'controls';

        switch ($industry) {
            case 'automotive':
                $recommendations[] = 'compliance';
                $recommendations[] = 'document_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'training';
                }
                break;

            case 'financial_services':

            case 'energy':
            case 'telecommunications':
            case 'critical_infrastructure':
                $recommendations[] = 'bcm';
                $recommendations[] = 'incident_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                break;

            case 'healthcare':
                $recommendations[] = 'incident_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'training';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'pharmaceutical':
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                $recommendations[] = 'training';
                $recommendations[] = 'document_management';
                break;

            case 'digital_health':
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                break;

            case 'cloud_services':
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'public_sector':
                $recommendations[] = 'audit_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'document_management';
                break;

            case 'it_services':
                $recommendations[] = 'incident_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'manufacturing':
                $recommendations[] = 'bcm';
                if ($isMediumOrg) {
                    $recommendations[] = 'incident_management';
                }
                break;

            default:
                if ($isMediumOrg) {
                    $recommendations[] = 'incident_management';
                }
                break;
        }

        if ($isLargeOrg) {
            $recommendations[] = 'training';
            $recommendations[] = 'audit_management';
        }

        // SMB-5: Small orgs (1-50 employees) don't need BCM, multi-framework
        // compliance, or audit logging initially — keep it lean.
        if ($isSmallOrg) {
            $recommendations = array_values(array_diff($recommendations, [
                'bcm',
                'compliance',
                'audit_logging',
            ]));
        }

        return array_unique($recommendations);
    }

    /**
     * Get recommended compliance frameworks for multiple industries (corporate structures).
     *
     * @param string[] $industries   List of industry codes
     * @param string   $employeeCount
     * @param string   $country
     * @return string[]
     */
    public function getRecommendedFrameworksForIndustries(array $industries, string $employeeCount, string $country): array
    {
        $allRecommendations = [];

        foreach ($industries as $industry) {
            $allRecommendations = array_merge(
                $allRecommendations,
                $this->getRecommendedFrameworks($industry, $employeeCount, $country)
            );
        }

        return array_unique($allRecommendations);
    }

    /**
     * Get recommended modules for multiple industries (corporate structures).
     *
     * @param string[] $industries   List of industry codes
     * @param string   $employeeCount
     * @return string[]
     */
    public function getRecommendedModulesForIndustries(array $industries, string $employeeCount): array
    {
        $allRecommendations = [];

        foreach ($industries as $industry) {
            $allRecommendations = array_merge(
                $allRecommendations,
                $this->getRecommendedModules($industry, $employeeCount)
            );
        }

        return array_unique($allRecommendations);
    }

    /**
     * SMB-2: Apply the Generic Starter baseline (BL-GENERIC-v1) to the current tenant.
     *
     * Ensures the baseline entity is loaded first. If the baseline or tenant
     * cannot be resolved, returns a human-readable skip message instead of
     * throwing — the base-data import should not fail because of this.
     */
    public function applyGenericStarterBaseline(): string
    {
        try {
            $baseline = $this->industryBaselineRepository->findByCode('BL-GENERIC-v1');
            if ($baseline === null) {
                $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->kernel);
                $app->setAutoExit(false);
                $app->run(
                    new \Symfony\Component\Console\Input\ArrayInput(['command' => 'app:load-industry-baselines']),
                    new \Symfony\Component\Console\Output\BufferedOutput(),
                );
                // Re-query after seeding — clear identity map so Doctrine sees the new row
                $this->entityManager->clear();
                $baseline = $this->industryBaselineRepository->findByCode('BL-GENERIC-v1');
            }

            if ($baseline === null) {
                return 'Baseline BL-GENERIC-v1 nicht gefunden';
            }

            $tenant = $this->tenantRepository->findOneBy([]);
            if ($tenant === null) {
                return 'Kein Tenant vorhanden';
            }

            /** @var \App\Entity\User|null $user */
            $user = $this->security->getUser();

            $result = $this->industryBaselineApplier->apply($baseline, $tenant, $user);

            if ($result['already_applied']) {
                return 'Generic Starter bereits angewendet';
            }

            return sprintf(
                'Generic Starter: %d Risiken, %d Assets, %d Controls',
                $result['risks_created'],
                $result['assets_created'],
                $result['controls_marked_applicable'],
            );
        } catch (\Throwable $e) {
            return 'Baseline-Fehler: ' . $e->getMessage();
        }
    }
}
