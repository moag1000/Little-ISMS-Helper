<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\AccessReviewCampaign;
use App\Entity\AccessReviewItem;
use App\Entity\Tenant;
use App\Repository\AccessReviewCampaignRepository;
use App\Repository\AccessReviewItemRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Access-Review Workbook generator (export type: 'access-review').
 *
 * Produces a two-tab XLSX for auditor handover:
 *
 *   Cover   — Tenant info, campaign metadata, summary statistics, provenance stamp
 *   Results — One row per AccessReviewItem:
 *             Campaign | Subject Name | Subject Email | Reviewed Role |
 *             Decision | Decided By | Decided At | Comment
 *
 * Decision cells are colour-coded:
 *   approved  → green   (RISK_GREEN from WorkbookStyleHelper)
 *   revoked   → red     (RISK_RED)
 *   escalated → yellow  (RISK_YELLOW)
 *   pending   → none
 *
 * Used by AccessReviewController::export() for ISO 27001 A.5.18
 * chain-of-custody auditor handover.
 *
 * Tagged automatically via _instanceof AuditWorkbookGeneratorInterface.
 * Registered export type: 'access-review'.
 */
final class AccessReviewWorkbookGenerator implements AuditWorkbookGeneratorInterface
{
    public function __construct(
        private readonly AccessReviewCampaignRepository $campaignRepository,
        private readonly AccessReviewItemRepository     $itemRepository,
    ) {}

    public function supportsExportType(string $exportType): bool
    {
        return $exportType === 'access-review';
    }

    /**
     * @param array<string, mixed> $options  Required key: 'campaign_id' (int)
     */
    public function generate(Tenant $tenant, array $options = []): Spreadsheet
    {
        $campaignId = isset($options['campaign_id']) ? (int) $options['campaign_id'] : null;

        $campaign = $campaignId !== null
            ? $this->campaignRepository->findOneForTenant($campaignId, $tenant)
            : null;

        if ($campaign === null) {
            throw new \InvalidArgumentException(
                'AccessReviewWorkbookGenerator requires a valid "campaign_id" option scoped to the tenant.'
            );
        }

        $items = $this->itemRepository->findByCampaign($campaign);

        $spreadsheet = new Spreadsheet();
        WorkbookStyleHelper::setDocumentProperties(
            $spreadsheet,
            (string) $tenant->getName(),
            'access-review',
        );

        $this->buildCoverSheet($spreadsheet, $tenant, $campaign, $items);
        $this->buildResultsSheet($spreadsheet, $campaign, $items);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param AccessReviewItem[] $items
     */
    private function buildCoverSheet(
        Spreadsheet $spreadsheet,
        Tenant $tenant,
        AccessReviewCampaign $campaign,
        array $items,
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');

        // Build summary counts from the loaded items
        $approved  = 0;
        $revoked   = 0;
        $escalated = 0;
        $pending   = 0;

        foreach ($items as $item) {
            match ($item->getDecision()) {
                AccessReviewItem::DECISION_APPROVED  => $approved++,
                AccessReviewItem::DECISION_REVOKED   => $revoked++,
                AccessReviewItem::DECISION_ESCALATED => $escalated++,
                default                              => $pending++,
            };
        }

        WorkbookStyleHelper::writeCoverSheet($sheet, 'Access Review Export', [
            'Organisation'    => (string) $tenant->getName(),
            'Campaign'        => (string) $campaign->getName(),
            'Scope'           => $campaign->getScope(),
            'Status'          => $campaign->getStatus(),
            'Due Date'        => $campaign->getDueDate()?->format('Y-m-d') ?? '–',
            'Closed At'       => $campaign->getClosedAt()?->format('Y-m-d H:i:s') ?? '–',
            'Total Items'     => (string) count($items),
            'Approved'        => (string) $approved,
            'Revoked'         => (string) $revoked,
            'Escalated'       => (string) $escalated,
            'Pending'         => (string) $pending,
            'Standard'        => 'ISO/IEC 27001:2022 A.5.18 / A.8.2 · NIS2 Art. 21(2)(e)',
            'Generated at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            'Classification'  => 'CONFIDENTIAL — Audit Use Only',
            'Generator'       => WorkbookStyleHelper::GENERATOR_VERSION,
        ]);
    }

    /**
     * @param AccessReviewItem[] $items
     */
    private function buildResultsSheet(
        Spreadsheet $spreadsheet,
        AccessReviewCampaign $campaign,
        array $items,
    ): void {
        $sheet = $spreadsheet->createSheet();

        // Excel sheet titles are max 31 chars
        $sheetTitle = mb_substr((string) $campaign->getName(), 0, 28) . '...';
        if (mb_strlen((string) $campaign->getName()) <= 31) {
            $sheetTitle = (string) $campaign->getName();
        }
        // Sanitise: Excel does not allow / \ ? * [ ] : in sheet names
        $sheetTitle = preg_replace('/[\/\\\\?*\[\]:]/', '-', $sheetTitle) ?? 'Results';
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));

        $headers = [
            'Campaign',
            'Subject Name',
            'Subject Email',
            'Reviewed Role',
            'Decision',
            'Decided By',
            'Decided At',
            'Comment',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($items as $item) {
            $sheet->setCellValue('A' . $row, $campaign->getName() ?? '');
            $sheet->setCellValue('B' . $row, $item->getSubjectUser()?->getFullName() ?? '');
            $sheet->setCellValue('C' . $row, $item->getSubjectUser()?->getEmail() ?? '');
            $sheet->setCellValue('D' . $row, $item->getReviewedRole() ?? '');
            $sheet->setCellValue('E' . $row, $item->getDecision());
            $sheet->setCellValue('F' . $row, $item->getDecidedBy()?->getEmail() ?? '');
            $sheet->setCellValue('G' . $row, $item->getDecidedAt()?->format('Y-m-d H:i:s') ?? '');
            $sheet->setCellValue('H' . $row, $item->getComment() ?? '');

            // Colour-code decision column E per WorkbookStyleHelper palette
            $decisionColor = match ($item->getDecision()) {
                AccessReviewItem::DECISION_APPROVED  => WorkbookStyleHelper::RISK_GREEN,
                AccessReviewItem::DECISION_REVOKED   => WorkbookStyleHelper::RISK_RED,
                AccessReviewItem::DECISION_ESCALATED => WorkbookStyleHelper::RISK_YELLOW,
                default                              => null,
            };

            if ($decisionColor !== null) {
                $sheet->getStyle('E' . $row)->applyFromArray([
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF' . $decisionColor],
                    ],
                ]);
            }

            $row++;
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }
}
