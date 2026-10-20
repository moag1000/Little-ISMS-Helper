<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a pre-filled ManagementReview (ISO 27001 Cl. 9.3) from the
 * management-review report: the report data is turned into the narrative
 * texts of the §9.3 input fields, the review is persisted and audit-logged.
 *
 * Extracted from ManagementReportService, which only aggregates report data.
 */
final class ManagementReviewAutoCollector
{
    public function __construct(
        private readonly ManagementReportService $managementReportService,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * V3 B4 / EF-1: Auto-Collect Management-Review aus existing §9.3 sources.
     *
     * Aggregiert ISO 27001 §9.3 Inputs (Risk-Status, Audit-Results, NC-Status,
     * Performance-KPIs, Treatment-Plan-Effectiveness, Improvement-Opportunities)
     * und persistiert eine vorbefuellte ManagementReview-Entity.
     *
     * Der CISO/Compliance-Manager kann diese danach noch editieren und finalisieren —
     * spart aber den Initial-Aggregations-Aufwand komplett.
     *
     * @param Tenant            $tenant         Tenant-Context fuer das neue Review
     * @param \DateTimeInterface $referenceDate Stichtag (typisch: Quartal-Ende oder Jahres-Ende)
     * @param string            $locale         Sprache fuer Status-Beschreibungen
     */
    public function createManagementReviewFromReport(
        Tenant $tenant,
        \DateTimeInterface $referenceDate,
        string $locale = 'de'
    ): ManagementReview {
        $report = $this->managementReportService->getManagementReviewReport($locale);

        $review = new ManagementReview();
        $review->setTenant($tenant);
        $review->setTitle(sprintf(
            '%s — %s',
            $this->translator->trans('management_review.auto_collect.title_prefix', [], 'management_review'),
            $referenceDate->format('Y-m-d')
        ));
        $review->setReviewDate($referenceDate instanceof \DateTime ? $referenceDate : new \DateTime($referenceDate->format('Y-m-d')));
        $review->setStatus('planned'); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist entity; 'planned' is the management_review_lifecycle initial_marking, replacing legacy 'draft' value which was never a valid management_review status)
        $review->setCreatedAt(new DateTimeImmutable());

        // §9.3 (a) — Status of actions from previous management reviews
        // (left blank initially; user fills based on previous-review tracking)

        // §9.3 (b) — Changes in external/internal issues relevant to ISMS
        $review->setChangesRelevantToISMS($this->buildContextChangesNarrative($locale));

        // §9.3 (c) — Feedback on ISMS performance
        // §9.3 (c.1) — Nonconformities & corrective actions
        $review->setNonConformitiesStatus($this->buildNcStatusNarrative($report, $locale));
        $review->setCorrectiveActionsStatus($this->buildCorrectiveActionsNarrative($report, $locale));
        // §9.3 (c.2) — Monitoring & measurement results
        $review->setPerformanceEvaluation($this->buildPerformanceNarrative($report, $locale));
        // §9.3 (c.3) — Audit results
        $review->setAuditResults($this->buildAuditResultsNarrative($report, $locale));
        // §9.3 (c.4) — Fulfilment of information security objectives
        // (mapped via objectives review later)

        // §9.3 (d) — Feedback from interested parties
        // (left blank — user fills based on interested-parties roster)

        // §9.3 (e) — Risks and opportunities
        $review->setRisksReview($this->buildRiskNarrative($report, $locale));

        // §9.3 (f) — Opportunities for improvement
        $review->setOpportunitiesForImprovement($this->buildImprovementOpportunitiesNarrative($report, $locale));

        // §9.3 outputs — decisions & action items (initially empty; filled in meeting)
        $review->setDecisions('');
        $review->setActionItems('');

        $this->entityManager->persist($review);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'ManagementReview',
            $review->getId(),
            [
                'title' => $review->getTitle(),
                'reviewDate' => $review->getReviewDate()?->format('Y-m-d'),
                'autoCollected' => true,
            ],
            'Management-Review auto-generated from §9.3 sources at ' . $referenceDate->format('Y-m-d')
        );

        return $review;
    }

    private function buildNcStatusNarrative(array $report, string $locale): string
    {
        $audit = $report['audit_data'];
        $totalFindings = $audit['total_findings'] ?? 0;
        $openFindings = $audit['open_findings'] ?? 0;
        $closedFindings = $totalFindings - $openFindings;

        if ($locale === 'de') {
            return sprintf(
                "Nonkonformitäten-Status (Stand: %s)\n\nGesamt: %d Findings\nOffen: %d\nGeschlossen: %d\n\nDetailaufschlüsselung siehe Audit-Findings-Modul.",
                date('Y-m-d'),
                $totalFindings,
                $openFindings,
                $closedFindings
            );
        }
        return sprintf(
            "Non-conformities status (as of %s)\n\nTotal: %d findings\nOpen: %d\nClosed: %d\n\nDetailed breakdown in Audit Findings module.",
            date('Y-m-d'),
            $totalFindings,
            $openFindings,
            $closedFindings
        );
    }

    private function buildCorrectiveActionsNarrative(array $report, string $locale): string
    {
        $treatment = $report['treatment_data'];
        if ($locale === 'de') {
            return sprintf(
                "Korrekturmaßnahmen-Status\n\nGesamt: %d Treatment-Pläne\nAktiv: %d\nÜberfällig: %d",
                $treatment['total'],
                $treatment['active'],
                $treatment['overdue']
            );
        }
        return sprintf(
            "Corrective actions status\n\nTotal: %d treatment plans\nActive: %d\nOverdue: %d",
            $treatment['total'],
            $treatment['active'],
            $treatment['overdue']
        );
    }

    private function buildPerformanceNarrative(array $report, string $locale): string
    {
        $kpis = $report['kpi_summary'] ?? [];
        $lines = [];
        foreach ($kpis as $kpi) {
            $lines[] = sprintf('- %s: %s [%s]', $kpi['label'], $kpi['value'], $kpi['status_label']);
        }
        $body = implode("\n", $lines);

        if ($locale === 'de') {
            return "Leistungsbeurteilung — KPI-Snapshot:\n\n" . $body;
        }
        return "Performance evaluation — KPI snapshot:\n\n" . $body;
    }

    private function buildAuditResultsNarrative(array $report, string $locale): string
    {
        $audit = $report['audit_data'];
        $completed = $audit['completed_audits'] ?? 0;
        $upcoming = $audit['upcoming_audits'] ?? 0;

        if ($locale === 'de') {
            return sprintf(
                "Audit-Ergebnisse\n\nDurchgeführte Audits: %d\nGeplante Audits: %d\n\nAusführliche Berichte im Audit-Modul.",
                $completed,
                $upcoming
            );
        }
        return sprintf(
            "Audit results\n\nCompleted audits: %d\nPlanned audits: %d\n\nDetailed reports in Audits module.",
            $completed,
            $upcoming
        );
    }

    private function buildRiskNarrative(array $report, string $locale): string
    {
        $risk = $report['risk_data'];
        if ($locale === 'de') {
            return sprintf(
                "Risikolage\n\nGesamt: %d Risiken\nKritisch: %d\nHoch: %d\nMittel: %d\nNiedrig: %d",
                $risk['total_risks'] ?? 0,
                $risk['critical_count'] ?? 0,
                $risk['high_count'] ?? 0,
                $risk['medium_count'] ?? 0,
                $risk['low_count'] ?? 0
            );
        }
        return sprintf(
            "Risk landscape\n\nTotal: %d risks\nCritical: %d\nHigh: %d\nMedium: %d\nLow: %d",
            $risk['total_risks'] ?? 0,
            $risk['critical_count'] ?? 0,
            $risk['high_count'] ?? 0,
            $risk['medium_count'] ?? 0,
            $risk['low_count'] ?? 0
        );
    }

    private function buildContextChangesNarrative(string $locale): string
    {
        if ($locale === 'de') {
            return "Kontext-Änderungen seit letztem Review\n\n[Vom CISO/Compliance-Manager auszufüllen — relevante Änderungen extern (Recht, Markt, Lieferkette) und intern (Org-Struktur, IT-Landschaft, Prozesse, Personen).]";
        }
        return "Context changes since last review\n\n[To be filled by CISO/Compliance Manager — relevant changes external (legal, market, supply chain) and internal (org structure, IT landscape, processes, people).]";
    }

    private function buildImprovementOpportunitiesNarrative(array $report, string $locale): string
    {
        $treatment = $report['treatment_data'];
        $kpis = $report['kpi_summary'] ?? [];
        $criticalKpis = array_filter($kpis, fn($k): bool => ($k['status'] ?? '') === 'critical');

        if ($locale === 'de') {
            $body = "Verbesserungspotenziale (auto-detektiert)\n\n";
            if ($treatment['overdue'] > 0) {
                $body .= sprintf("- %d überfällige Treatment-Pläne — Eskalations-Bedarf prüfen\n", $treatment['overdue']);
            }
            foreach ($criticalKpis as $kpi) {
                $body .= sprintf("- KPI '%s' im kritischen Bereich (%s)\n", $kpi['label'], $kpi['value']);
            }
            $body .= "\n[Manuell ergänzen: weitere Verbesserungschancen aus Mitarbeiter-Feedback, Audits, Vorfällen.]";
            return $body;
        }
        $body = "Improvement opportunities (auto-detected)\n\n";
        if ($treatment['overdue'] > 0) {
            $body .= sprintf("- %d overdue treatment plans — escalation needed\n", $treatment['overdue']);
        }
        foreach ($criticalKpis as $kpi) {
            $body .= sprintf("- KPI '%s' in critical range (%s)\n", $kpi['label'], $kpi['value']);
        }
        $body .= "\n[Manually extend: additional opportunities from staff feedback, audits, incidents.]";
        return $body;
    }
}
