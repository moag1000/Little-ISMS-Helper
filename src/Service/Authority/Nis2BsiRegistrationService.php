<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F29 — Business logic for NIS-2 BSI-Portal Yearly Re-Registration.
 *
 * Provides:
 *  - getOrCreateProfile(): auto-derives defaults from Tenant fields.
 *  - validate(): field-level Pflicht-check, returns array<field, errorMessage>.
 *  - exportToJson(): BSI-Portal-spec-compliant JSON string.
 *  - markReported(): records BSI-portal confirmation + bumps nextDueAt by 1 year.
 *
 * The service does NOT integrate with the BSI portal directly —
 * the JSON export is downloaded locally and submitted by the responsible
 * person via the BSI portal web UI (KRITIS-Dachgesetz / BSIG § 33).
 *
 * Module gate: nis2_dora (enforced in controller, not here)
 */
final class Nis2BsiRegistrationService
{
    public function __construct(
        private readonly Nis2RegistrationProfileRepository $profileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns the existing registration profile for the tenant, or creates
     * a new one pre-populated with defaults from Tenant entity fields.
     */
    public function getOrCreateProfile(Tenant $tenant): Nis2RegistrationProfile
    {
        $profile = $this->profileRepository->findForTenant($tenant);

        if ($profile !== null) {
            return $profile;
        }

        $profile = new Nis2RegistrationProfile();
        $profile->setTenant($tenant);

        // Auto-derive defaults from Tenant where available
        if ($tenant->getLegalName() !== null && $tenant->getLegalName() !== '') {
            $profile->setOrganizationLegalName($tenant->getLegalName());
        }

        if ($tenant->getLegalForm() !== null && $tenant->getLegalForm() !== '') {
            $profile->setOrganizationLegalForm($tenant->getLegalForm());
        }

        if ($tenant->getNis2Sector() !== null && $tenant->getNis2Sector() !== '') {
            $profile->setNis2Sector($tenant->getNis2Sector());
        }

        if ($tenant->getNaceCode() !== null && $tenant->getNaceCode() !== '') {
            $profile->setNaceCodes([$tenant->getNaceCode()]);
        }

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * Validates all mandatory BSI-Portal fields.
     *
     * Returns an array indexed by field name with human-readable error messages.
     * An empty array means the profile is complete and ready for export.
     *
     * @return array<string, string>
     */
    public function validate(Nis2RegistrationProfile $profile): array
    {
        $errors = [];

        if (trim($profile->getOrganizationLegalName()) === '') {
            $errors['organizationLegalName'] = 'eu_authorities.nis2_registration.error.legal_name_required';
        }

        if (trim($profile->getOrganizationLegalForm()) === '') {
            $errors['organizationLegalForm'] = 'eu_authorities.nis2_registration.error.legal_form_required';
        }

        if (trim($profile->getCommercialRegisterCity()) === '') {
            $errors['commercialRegisterCity'] = 'eu_authorities.nis2_registration.error.register_city_required';
        }

        if (trim($profile->getCommercialRegisterNumber()) === '') {
            $errors['commercialRegisterNumber'] = 'eu_authorities.nis2_registration.error.register_number_required';
        }

        if ($profile->getNaceCodes() === []) {
            $errors['naceCodes'] = 'eu_authorities.nis2_registration.error.nace_codes_required';
        }

        if (trim($profile->getNis2Sector()) === '') {
            $errors['nis2Sector'] = 'eu_authorities.nis2_registration.error.sector_required';
        }

        if (!in_array($profile->getNis2EntityCategory(), Nis2RegistrationProfile::VALID_CATEGORIES, true)) {
            $errors['nis2EntityCategory'] = 'eu_authorities.nis2_registration.error.category_invalid';
        }

        if ($profile->getAffectedHeadcount() <= 0) {
            $errors['affectedHeadcount'] = 'eu_authorities.nis2_registration.error.headcount_required';
        }

        if (trim($profile->getIctDependencyDescription()) === '') {
            $errors['ictDependencyDescription'] = 'eu_authorities.nis2_registration.error.ict_description_required';
        }

        if ($profile->getIncidentReportingContact() === null) {
            $errors['incidentReportingContact'] = 'eu_authorities.nis2_registration.error.incident_contact_required';
        }

        if ($profile->getSecurityResponsibleContact() === null) {
            $errors['securityResponsibleContact'] = 'eu_authorities.nis2_registration.error.security_contact_required';
        }

        return $errors;
    }

    /**
     * Exports the registration profile as a BSI-Portal-spec-compliant JSON string.
     *
     * The JSON schema follows the BSI Meldung-Portal API (BSIG § 33),
     * published by BSI in January 2026 for essential and important facilities.
     *
     * @throws LogicException if required contacts are not set (validate() must pass first)
     */
    public function exportToJson(Nis2RegistrationProfile $profile): string
    {
        if ($profile->getIncidentReportingContact() === null) {
                        // @intentional-assertion: caller must populate incidentReportingContact before exportToJson()
throw new \LogicException('Cannot export profile: incidentReportingContact is required but not set.');
        }

        if ($profile->getSecurityResponsibleContact() === null) {
                        // @intentional-assertion: caller must populate securityResponsibleContact before exportToJson()
throw new \LogicException('Cannot export profile: securityResponsibleContact is required but not set.');
        }

        $data = [
            'schemaVersion' => '1.0',
            'exportedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'registrationType' => 'nis2_yearly_reregistration',
            'organization' => [
                'legalName' => $profile->getOrganizationLegalName(),
                'legalForm' => $profile->getOrganizationLegalForm(),
                'commercialRegister' => [
                    'city' => $profile->getCommercialRegisterCity(),
                    'number' => $profile->getCommercialRegisterNumber(),
                ],
                'vatId' => $profile->getVatId(),
                'naceCodes' => $profile->getNaceCodes(),
                'affectedHeadcount' => $profile->getAffectedHeadcount(),
                'affectedAnnualTurnoverEur' => $profile->getAffectedAnnualTurnoverEur() !== null
                    ? (float) $profile->getAffectedAnnualTurnoverEur()
                    : null,
            ],
            'nis2Classification' => [
                'sector' => $profile->getNis2Sector(),
                'entityCategory' => $profile->getNis2EntityCategory(),
                'ictDependencyDescription' => $profile->getIctDependencyDescription(),
            ],
            'contacts' => [
                'incidentReporting' => [
                    'name' => $profile->getIncidentReportingContact()->getFullName(),
                    'email' => $profile->getIncidentReportingContact()->getEmail(),
                ],
                'securityResponsible' => [
                    'name' => $profile->getSecurityResponsibleContact()->getFullName(),
                    'email' => $profile->getSecurityResponsibleContact()->getEmail(),
                ],
                'backupSecurity' => $profile->getBackupSecurityContact() !== null ? [
                    'name' => $profile->getBackupSecurityContact()->getFullName(),
                    'email' => $profile->getBackupSecurityContact()->getEmail(),
                ] : null,
            ],
            'registrationHistory' => [
                'lastReportedAt' => $profile->getLastReportedAt()?->format(DateTimeImmutable::ATOM),
                'portalConfirmationNumber' => $profile->getPortalConfirmationNumber(),
                'nextDueAt' => $profile->getNextDueAt()->format(DateTimeImmutable::ATOM),
            ],
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Marks the profile as successfully reported to the BSI portal.
     *
     * Sets lastReportedAt to now, stores the portal confirmation number,
     * and advances nextDueAt by exactly 1 year.
     */
    public function markReported(Nis2RegistrationProfile $profile, string $portalConfirmation): void
    {
        $now = new DateTimeImmutable();
        $profile->setLastReportedAt($now);
        $profile->setPortalConfirmationNumber($portalConfirmation);
        $profile->setNextDueAt($now->modify('+1 year'));

        $this->entityManager->flush();
    }
}
