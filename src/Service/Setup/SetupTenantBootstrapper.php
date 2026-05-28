<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\ISMSContext;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\AssetSubTypeSeeder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * SetupTenantBootstrapper — extracted from DeploymentWizardController (god-class split).
 *
 * Handles the tenant-creation and initial data-seeding that happens at the end
 * of the setup wizard (step 11: complete). Specifically:
 *
 *   - saveOrganisationDataToTenant() — creates or updates the Tenant entity with
 *     wizard-collected org data, stores org-context in settings JSON, and triggers
 *     dependent seeders.
 *   - seedISMSContextFromWizard() — pre-fills the ISMSContext entity (ISO 27001
 *     Clause 4 — Kontext der Organisation) with step-6 wizard data so the Clause-4
 *     page is never blank after a fresh install.
 *
 * Neither method belongs in a controller — they are pure domain bootstrap logic.
 */
final class SetupTenantBootstrapper
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetSubTypeSeeder $assetSubTypeSeeder,
    ) {
    }

    /**
     * Save organization data to Tenant settings.
     *
     * Stores the organization context (industries, size, country) in the Tenant
     * entity so it can be modified later via Tenant settings.
     */
    public function saveOrganisationDataToTenant(SessionInterface $session): void
    {
        try {
            $tenant = $this->tenantRepository->findOneBy([]);

            if (!$tenant) {
                $tenant = new Tenant();
                $tenant->setCode('default');
                $tenant->setName($session->get('setup_organisation_name', 'Default Organization'));
                // Setup-wizard bootstraps the first tenant directly into the
                // operational `active` place; the default initial-marking
                // `draft` would otherwise block login flows for the wizard
                // user moments later.
                $tenant->setStatus(Tenant::STATUS_ACTIVE);
                $this->entityManager->persist($tenant);
            } else {
                $orgName = $session->get('setup_organisation_name');
                if ($orgName) {
                    $tenant->setName($orgName);
                }
            }

            $settings = $tenant->getSettings() ?? [];

            $settings['organisation'] = [
                'industries' => $session->get('setup_organisation_industries', ['other']),
                'employee_count' => $session->get('setup_organisation_employee_count', '1-10'),
                'country' => $session->get('setup_organisation_country', 'DE'),
                'description' => $session->get('setup_organisation_description', ''),
                'selected_modules' => $session->get('setup_selected_modules', []),
                'selected_frameworks' => $session->get('setup_selected_frameworks', []),
                'setup_completed_at' => new DateTimeImmutable()->format('c'),
            ];

            $tenant->setSettings($settings);

            $orgDescription = $session->get('setup_organisation_description');
            if ($orgDescription && !$tenant->getDescription()) {
                $tenant->setDescription($orgDescription);
            }

            $this->entityManager->flush();

            // Seed "Kontext der Organisation" (ISO 27001 Clause 4) from wizard.
            $this->seedISMSContextFromWizard($tenant, $session);

            // Seed BSI IT-Grundschutz default asset sub-types so the sub-type
            // dropdown is not empty for freshly provisioned tenants (Bug S18 B2).
            $this->assetSubTypeSeeder->applyPreset($tenant, AssetSubTypeSeeder::PRESET_BSI);

            $this->entityManager->flush();
        } catch (\Exception) {
            // Log error but don't fail setup.
            // The organization data is already saved in session and modules are configured.
        }
    }

    /**
     * Pre-fills the ISMSContext entity (Clause 4 — Kontext der Organisation)
     * with whatever the wizard already collected in step 6. Idempotent: if a
     * row for the tenant exists, its previously-set fields are not overwritten.
     */
    public function seedISMSContextFromWizard(Tenant $tenant, SessionInterface $session): void
    {
        $contextRepo = $this->entityManager->getRepository(ISMSContext::class);
        $context = $contextRepo->findOneBy(['tenant' => $tenant]);
        $isNew = false;
        if (!$context instanceof ISMSContext) {
            $context = new ISMSContext();
            $context->setTenant($tenant);
            $isNew = true;
        }

        $orgName = $session->get('setup_organisation_name', $tenant->getName() ?: 'Default Organization');
        if ($context->getOrganizationName() === null || $context->getOrganizationName() === '') {
            $context->setOrganizationName((string) $orgName);
        }

        // Map step-6 free-text description into internalIssues as a starting
        // point — user can refine in the dedicated context-edit form later.
        $description = (string) ($session->get('setup_organisation_description', '') ?? '');
        if ($description !== '' && ($context->getInternalIssues() === null || $context->getInternalIssues() === '')) {
            $context->setInternalIssues($description);
        }

        // Build a starter scope sentence from industry/country/employee-count.
        $industries = $session->get('setup_organisation_industries', []);
        $employeeCount = $session->get('setup_organisation_employee_count', '');
        $country = $session->get('setup_organisation_country', '');
        if ($context->getIsmsScope() === null || $context->getIsmsScope() === '') {
            $scopeParts = [];
            if (is_array($industries) && $industries !== []) {
                $scopeParts[] = 'Branchen: ' . implode(', ', $industries);
            }
            if ($employeeCount !== '') {
                $scopeParts[] = 'Mitarbeiter: ' . $employeeCount;
            }
            if ($country !== '') {
                $scopeParts[] = 'Sitz: ' . $country;
            }
            if ($scopeParts !== []) {
                $context->setIsmsScope(implode(' · ', $scopeParts));
            }
        }

        if ($isNew) {
            $this->entityManager->persist($context);
        }
    }
}
