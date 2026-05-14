<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\Tenant;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use App\Repository\ProcessingActivityRepository;
use DateTimeImmutable;

/**
 * F36 — EU-Behörden-Reporting-Hub: aggregates reporting obligations across all authority modules.
 *
 * Sources:
 *  - F25 VVT/BfDI (DSGVO Art. 30) — always "available" (on-demand only)
 *  - F26 Behörden-Templates (BSI/BfDI/LfDI) — always "available"
 *  - F29 NIS-2 BSI-Portal registration — status from Nis2RegistrationProfile.nextDueAt
 *  - F30 DORA RoI XBRL — status from DoraRegisterOfInformation current-year record
 *
 * Status values: current | due_soon | overdue | available | not_configured
 *
 * Module gate: eu_authority_reporting (enforced in controller, not here)
 */
class AuthorityHubService
{
    public function __construct(
        private readonly Nis2RegistrationProfileRepository $nis2Repository,
        private readonly DoraRegisterOfInformationRepository $doraRoiRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
    ) {
    }

    /**
     * Returns an array of AuthorityObligation maps for the given tenant.
     *
     * Each obligation is an associative array:
     * {
     *   authority: string    — authority key (vvt_bfdi | bsi_nis2 | bfdi_templates | dora_roi)
     *   label: string        — human-readable authority name (translation key)
     *   type: string         — obligation type (on_demand | yearly | triggered)
     *   status: string       — current | due_soon | overdue | available | not_configured
     *   nextDueAt: ?DateTimeImmutable
     *   lastSubmittedAt: ?DateTimeImmutable
     *   exportUrl: string    — route name for the export/submission page
     *   exportUrlParams: array
     *   module: string       — required module key
     *   country: string      — primary country (DE | AT | CH | EU)
     * }
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReportingObligationsForTenant(Tenant $tenant): array
    {
        $obligations = [];

        // ─── F25: VVT-BfDI Export (DSGVO Art. 30) ────────────────────────────
        $hasPas = count($this->processingActivityRepository->findByTenant($tenant)) > 0;
        $obligations[] = [
            'authority'       => 'vvt_bfdi',
            'label'           => 'eu_authorities.hub.obligation.vvt_bfdi',
            'type'            => 'on_demand',
            'status'          => $hasPas ? 'available' : 'not_configured',
            'nextDueAt'       => null,
            'lastSubmittedAt' => null,
            'exportUrl'       => 'app_vvt_export_xlsx',
            'exportUrlParams' => [],
            'module'          => 'privacy',
            'country'         => 'DE',
        ];

        // ─── F26: Behörden-Templates (BSI/BfDI/LfDI) ─────────────────────────
        $obligations[] = [
            'authority'       => 'bfdi_templates',
            'label'           => 'eu_authorities.hub.obligation.bfdi_templates',
            'type'            => 'on_demand',
            'status'          => 'available',
            'nextDueAt'       => null,
            'lastSubmittedAt' => null,
            'exportUrl'       => 'app_authority_notification_index',
            'exportUrlParams' => [],
            'module'          => 'privacy',
            'country'         => 'DE',
        ];

        // ─── F29: NIS-2 BSI-Portal Registration ──────────────────────────────
        $nis2Profile = $this->nis2Repository->findForTenant($tenant);
        if ($nis2Profile !== null) {
            $nis2Status = match (true) {
                $nis2Profile->isOverdue()   => 'overdue',
                $nis2Profile->isDueSoon()   => 'due_soon',
                default                     => 'current',
            };
            $obligations[] = [
                'authority'       => 'bsi_nis2',
                'label'           => 'eu_authorities.hub.obligation.bsi_nis2',
                'type'            => 'yearly',
                'status'          => $nis2Status,
                'nextDueAt'       => $nis2Profile->getNextDueAt(),
                'lastSubmittedAt' => $nis2Profile->getLastReportedAt(),
                'exportUrl'       => 'nis2_registration_index',
                'exportUrlParams' => [],
                'module'          => 'nis2_dora',
                'country'         => 'DE',
            ];
        } else {
            $obligations[] = [
                'authority'       => 'bsi_nis2',
                'label'           => 'eu_authorities.hub.obligation.bsi_nis2',
                'type'            => 'yearly',
                'status'          => 'not_configured',
                'nextDueAt'       => null,
                'lastSubmittedAt' => null,
                'exportUrl'       => 'nis2_registration_index',
                'exportUrlParams' => [],
                'module'          => 'nis2_dora',
                'country'         => 'DE',
            ];
        }

        // ─── F30: DORA RoI XBRL ───────────────────────────────────────────────
        $doraRecord = $this->doraRoiRepository->findCurrentYearForTenant($tenant);
        $doraStatus = $this->computeDoraStatus($doraRecord);
        $obligations[] = [
            'authority'       => 'dora_roi',
            'label'           => 'eu_authorities.hub.obligation.dora_roi',
            'type'            => 'yearly',
            'status'          => $doraStatus,
            'nextDueAt'       => $this->computeDoraNextDue(),
            'lastSubmittedAt' => $doraRecord?->getSubmittedAt(),
            'exportUrl'       => 'dora_roi_index',
            'exportUrlParams' => [],
            'module'          => 'nis2_dora',
            'country'         => 'EU',
        ];

        return $obligations;
    }

    /**
     * Aggregates status counts across all obligations for a given tenant.
     *
     * @return array{current: int, due_soon: int, overdue: int, available: int, not_configured: int}
     */
    public function getStatusSummary(Tenant $tenant): array
    {
        $obligations = $this->getReportingObligationsForTenant($tenant);

        $summary = [
            'current'        => 0,
            'due_soon'       => 0,
            'overdue'        => 0,
            'available'      => 0,
            'not_configured' => 0,
        ];

        foreach ($obligations as $obligation) {
            $status = $obligation['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * Returns true if any obligation for the tenant has status "overdue".
     */
    public function hasOverdueObligation(Tenant $tenant): bool
    {
        $summary = $this->getStatusSummary($tenant);
        return $summary['overdue'] > 0;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function computeDoraStatus(?\App\Entity\Authority\DoraRegisterOfInformation $record): string
    {
        $currentYear = (int) (new DateTimeImmutable())->format('Y');
        $yearDeadline = new DateTimeImmutable($currentYear . '-12-31');
        $dueSoonThreshold = new DateTimeImmutable('+60 days');

        if ($record !== null && $record->isSubmitted()) {
            return 'current';
        }

        // DORA RoI is due by year-end; "due soon" within 60 days of Dec 31
        $now = new DateTimeImmutable();
        if ($now > $yearDeadline) {
            return 'overdue';
        }

        if ($dueSoonThreshold >= $yearDeadline) {
            return 'due_soon';
        }

        return 'available';
    }

    private function computeDoraNextDue(): DateTimeImmutable
    {
        $currentYear = (int) (new DateTimeImmutable())->format('Y');
        $deadline = new DateTimeImmutable($currentYear . '-12-31');
        $now = new DateTimeImmutable();

        if ($now > $deadline) {
            // Past year-end — next due is end of next year
            return new DateTimeImmutable(($currentYear + 1) . '-12-31');
        }

        return $deadline;
    }
}
