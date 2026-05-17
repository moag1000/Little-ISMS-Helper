<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Tenant;
use App\Repository\SupplierRepository;

/**
 * BAFA LkSG annual-report CSV export.
 *
 * Renders the supplier-side due-diligence inventory required for
 * § 10 LkSG annual reporting: human-rights / environmental risk scores,
 * complaint mechanism, prevention measures, latest analysis date.
 * Tenants in scope (≥ 1000 employees, expansion to ≥ 250 expected)
 * download this CSV, augment it with internal evidence, and submit to
 * BAFA. Auditors can pull the same export as evidence.
 */
final class LksgAnnualReportExporter
{
    public function __construct(
        private readonly SupplierRepository $supplierRepository,
    ) {
    }

    public function export(Tenant $tenant, ?string $minimumRiskCategory = null): string
    {
        $suppliers = $this->supplierRepository->findLksgRelevantSuppliers($tenant, $minimumRiskCategory);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open in-memory stream for CSV export.');
        }

        fputcsv($stream, [
            'supplier_id',
            'name',
            'country_of_head_office',
            'service_provided',
            'lksg_risk_category',
            'lksg_human_rights_risk_score',
            'lksg_environmental_risk_score',
            'lksg_aggregate_risk_score',
            'lksg_risk_analysis_date',
            'lksg_complaint_mechanism',
            'lksg_prevention_measures',
            'last_security_assessment',
            'next_assessment_date',
            'has_subcontractors',
        ], ',', '"', '\\');

        foreach ($suppliers as $supplier) {
            fputcsv($stream, [
                (string) ($supplier->getId() ?? ''),
                (string) ($supplier->getName() ?? ''),
                (string) ($supplier->getCountryOfHeadOffice() ?? ''),
                (string) ($supplier->getServiceProvided() ?? ''),
                (string) ($supplier->getLksgRiskCategory() ?? ''),
                $supplier->getLksgHumanRightsRiskScore() === null
                    ? ''
                    : (string) $supplier->getLksgHumanRightsRiskScore(),
                $supplier->getLksgEnvironmentalRiskScore() === null
                    ? ''
                    : (string) $supplier->getLksgEnvironmentalRiskScore(),
                $supplier->getLksgAggregateRiskScore() === null
                    ? ''
                    : (string) $supplier->getLksgAggregateRiskScore(),
                $supplier->getLksgRiskAnalysisDate()?->format('Y-m-d') ?? '',
                $this->normalise($supplier->getLksgComplaintMechanism()),
                $this->normalise($supplier->getLksgPreventionMeasures()),
                $supplier->getLastSecurityAssessment()?->format('Y-m-d') ?? '',
                $supplier->getNextAssessmentDate()?->format('Y-m-d') ?? '',
                $supplier->hasSubcontractors() ? '1' : '0',
            ], ',', '"', '\\');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF" . $csv; // UTF-8 BOM for Excel
    }

    private function normalise(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
