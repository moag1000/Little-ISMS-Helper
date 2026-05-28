<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Repository\IndustryBaselineRepository;
use App\Repository\TenantRepository;
use App\Service\IndustryBaselineApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Applies the Generic Starter baseline (BL-GENERIC-v1) to the current tenant.
 *
 * Extracted from {@see SetupRecommendationEngine} so that the recommendation
 * engine remains a pure computation service (no Security / Kernel dependencies).
 *
 * Used by {@see \App\Controller\DeploymentWizardController} during the
 * base-data import step (SMB-2).
 */
final class SetupBaselineApplier
{
    public function __construct(
        private readonly IndustryBaselineRepository $industryBaselineRepository,
        private readonly IndustryBaselineApplier $industryBaselineApplier,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly KernelInterface $kernel,
    ) {
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
