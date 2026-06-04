<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Consent;
use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Repository\ConsentRepository;
use App\Repository\DataSubjectRequestRepository;
use DateTimeImmutable;

/**
 * Builds the structured, machine-readable personal-data export a data subject
 * is entitled to under GDPR Art. 15 (right of access) and Art. 20 (data
 * portability).
 *
 * The previous bulkExport() only dumped request-administration metadata — it
 * never produced the subject's actual data. This service aggregates the
 * personal data this ISMS tool genuinely holds about the subject (their consent
 * records and their data-subject requests) and ships a checklist of external
 * sources a controller must still collect by hand, because the tool is not the
 * system of record for operational PII. Honest scope beats a false "complete"
 * claim.
 */
final class PersonalDataExportService
{
    /**
     * External systems the controller must check manually — the tool cannot
     * reach them, so the Art. 15 response is only complete once these are swept.
     */
    private const array MANUAL_SOURCES = [
        'crm' => 'Customer-relationship / sales system',
        'erp_hr' => 'ERP / HR / payroll system (employee data, BDSG § 26)',
        'mail' => 'E-mail mailboxes and archives',
        'ticketing' => 'Support / ticketing system',
        'marketing' => 'Marketing / newsletter platform',
        'backups' => 'Backup and disaster-recovery snapshots',
        'paper' => 'Physical / paper records',
        'processors' => 'Sub-processors (Art. 28) holding data on the controller’s behalf',
    ];

    public function __construct(
        private readonly ConsentRepository $consentRepository,
        private readonly DataSubjectRequestRepository $dataSubjectRequestRepository,
    ) {
    }

    /**
     * @return array<string, mixed> structured export payload
     */
    public function buildExport(DataSubjectRequest $request): array
    {
        $tenant = $request->getTenant();
        $email = $request->getDataSubjectEmail();
        $name = $request->getDataSubjectName();
        $identifier = $request->getDataSubjectIdentifier();

        return [
            'export_metadata' => [
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'gdpr_basis' => ['Art. 15 (access)', 'Art. 20 (portability)'],
                'source_request_id' => $request->getId(),
                'format' => 'application/json (structured, machine-readable)',
            ],
            'data_subject' => [
                'name' => $name,
                'email' => $email,
                'identifier' => $identifier,
            ],
            'records_held_in_tool' => [
                'consents' => array_map(
                    $this->mapConsent(...),
                    $this->collectConsents($tenant, $identifier, $email),
                ),
                'data_subject_requests' => array_map(
                    $this->mapRequest(...),
                    $this->collectRequests($tenant, $email, $name),
                ),
            ],
            'manual_sources_checklist' => self::MANUAL_SOURCES,
            'notice' => 'This export contains the personal data held about the data subject within the ISMS tool. '
                . 'Operational personal data stored in other systems must be collected from the sources listed under '
                . 'manual_sources_checklist before the Art. 15 response is considered complete.',
        ];
    }

    public function toJson(array $export): string
    {
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<Consent>
     */
    private function collectConsents(?Tenant $tenant, ?string $identifier, ?string $email): array
    {
        $byId = [];
        foreach ([$identifier, $email] as $key) {
            if ($key === null || $key === '') {
                continue;
            }
            foreach ($this->consentRepository->findByDataSubject($key, $tenant) as $consent) {
                $byId[$consent->getId()] = $consent;
            }
        }

        return array_values($byId);
    }

    /**
     * @return list<DataSubjectRequest>
     */
    private function collectRequests(?Tenant $tenant, ?string $email, ?string $name): array
    {
        $byId = [];
        foreach ([['dataSubjectEmail', $email], ['dataSubjectName', $name]] as [$field, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $criteria = [$field => $value];
            if ($tenant instanceof Tenant) {
                $criteria['tenant'] = $tenant;
            }
            foreach ($this->dataSubjectRequestRepository->findBy($criteria) as $found) {
                $byId[$found->getId()] = $found;
            }
        }

        return array_values($byId);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapConsent(Consent $consent): array
    {
        return [
            'id' => $consent->getId(),
            'purposes' => $consent->getPurposes(),
            'status' => $consent->getStatus(),
            'granted_at' => $consent->getGrantedAt()?->format(DATE_ATOM),
            'withdrawn_at' => $consent->getWithdrawnAt()?->format(DATE_ATOM),
            'consent_text' => $consent->getConsentText(),
            'channel' => $consent->getConsentChannel(),
            'method' => $consent->getConsentMethod(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRequest(DataSubjectRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'type' => $request->getRequestType(),
            'gdpr_article' => $request->getGdprArticle(),
            'status' => $request->getStatus(),
            'received_at' => $request->getReceivedAt()?->format(DATE_ATOM),
            'completed_at' => $request->getCompletedAt()?->format(DATE_ATOM),
            'response_description' => $request->getResponseDescription(),
        ];
    }
}
