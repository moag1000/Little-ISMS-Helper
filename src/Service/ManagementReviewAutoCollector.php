<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\CorrectiveActionStatus;
use App\Repository\CorrectiveActionRepository;
use App\Repository\ManagementReviewRepository;
use App\Service\ManagementReview\ReviewInputNarratives;
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
        private readonly CorrectiveActionRepository $correctiveActionRepository,
        private readonly ManagementReviewRepository $reviewRepository,
        private readonly ReviewInputNarratives $narratives,
    ) {
    }

    /**
     * Auto-Collect Management-Review aus existing §9.3 sources.
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
        // Explicit tenant: audit figures must not depend on the request's tenant filter.
        $report = $this->managementReportService->getManagementReviewReport($locale, $tenant);

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

        // ISO/IEC 27001:2022 9.3.2 a–g, each from the records since the previous review.
        $previous = $this->reviewRepository->findPreviousForTenant($tenant, $referenceDate);
        $since = $previous?->getReviewDate();

        // (a) Status of actions from previous management reviews
        $review->setPreviousReviewActions($this->narratives->previousActions($previous, $locale));
        // (b) Changes in external and internal issues relevant to the ISMS
        $review->setChangesRelevantToISMS($this->narratives->contextChanges($tenant, $since, $locale));
        // (c) Changes in needs and expectations of interested parties
        $review->setInterestedPartyNeedsChanges($this->narratives->interestedPartyChanges($tenant, $since, $locale));
        // (d 1) Nonconformities and corrective actions
        $review->setNonConformitiesStatus($this->buildNcStatusNarrative($report, $locale));
        $review->setCorrectiveActionsStatus($this->buildCorrectiveActionsNarrative($tenant, $locale));
        // (d 2) Monitoring and measurement results, incidents
        $review->setPerformanceEvaluation($this->buildPerformanceNarrative($report, $locale));
        $review->setIncidentsReview($this->narratives->incidents($tenant, $since, $locale));
        // (d 3) Audit results
        $review->setAuditResults($this->buildAuditResultsNarrative($report, $locale));
        // (d 4) Fulfilment of information security objectives
        $review->setObjectivesReview($this->narratives->objectives($tenant, $locale));
        // (e) Feedback from interested parties
        $review->setFeedbackFromInterestedParties($this->narratives->interestedPartyFeedback($tenant, $since, $locale));
        // (f) Results of risk assessment and status of risk treatment plan
        $review->setRisksReview($this->buildRiskNarrative($report, $locale));
        $review->setRiskTreatmentEffectiveness($this->buildTreatmentNarrative($report, $locale));
        // (g) Opportunities for continual improvement
        $review->setOpportunitiesForImprovement($this->buildImprovementOpportunitiesNarrative($report, $locale));
        // 9.3 asks for planned intervals — the next one a year on.
        $review->setNextReviewDate(DateTimeImmutable::createFromInterface($referenceDate)->modify('+12 months'));

        // 9.3.3 outputs (decisions, resulting tasks) are taken in the meeting.

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

    /**
     * 9.3.2 d.1: the corrective actions (CAPA, Cl. 10.2) of this
     * organisation — not the risk treatment plans, which belong to (f).
     */
    private function buildCorrectiveActionsNarrative(Tenant $tenant, string $locale): string
    {
        $all = $this->correctiveActionRepository->findBy(['tenant' => $tenant]);
        $open = array_filter($all, static fn($ca): bool => in_array(
            $ca->getStatus(),
            [CorrectiveActionStatus::Planned->value, CorrectiveActionStatus::InProgress->value],
            true,
        ));
        $ineffective = array_filter(
            $all,
            static fn($ca): bool => $ca->getStatus() === CorrectiveActionStatus::VerifiedIneffective->value,
        );
        $overdue = count($this->correctiveActionRepository->findOverdue($tenant));

        if ($locale === 'de') {
            return sprintf(
                "Korrekturmaßnahmen-Status\n\nGesamt: %d Korrekturmaßnahmen\nOffen: %d\nÜberfällig: %d\n"
                . 'Als unwirksam bewertet: %d',
                count($all),
                count($open),
                $overdue,
                count($ineffective)
            );
        }
        return sprintf(
            "Corrective actions status\n\nTotal: %d corrective actions\nOpen: %d\nOverdue: %d\n"
            . 'Verified ineffective: %d',
            count($all),
            count($open),
            $overdue,
            count($ineffective)
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
        // Same figures as the audit KPI (AuditProgramFigures): this calendar year.
        $completed = $audit['completed_audits'] ?? 0;
        $planned = $audit['planned_audits'] ?? 0;

        if ($locale === 'de') {
            return sprintf(
                "Audit-Ergebnisse\n\nAbgeschlossene Audits (dieses Jahr): %d\nGeplante Audits (dieses Jahr): %d\n\n"
                . "Ausführliche Berichte im Audit-Modul.",
                $completed,
                $planned
            );
        }
        return sprintf(
            "Audit results\n\nCompleted audits (this year): %d\nPlanned audits (this year): %d\n\n"
            . "Detailed reports in Audits module.",
            $completed,
            $planned
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
            return $body;
        }
        $body = "Improvement opportunities (auto-detected)\n\n";
        if ($treatment['overdue'] > 0) {
            $body .= sprintf("- %d overdue treatment plans — escalation needed\n", $treatment['overdue']);
        }
        foreach ($criticalKpis as $kpi) {
            $body .= sprintf("- KPI '%s' in critical range (%s)\n", $kpi['label'], $kpi['value']);
        }
        return $body;
    }

    /** 9.3.2 f — status of the risk treatment plans. */
    private function buildTreatmentNarrative(array $report, string $locale): string
    {
        $treatment = $report['treatment_data'] ?? [];

        return $this->translator->trans('management_review.input.f.treatment', [
            '%total%' => (int) ($treatment['total'] ?? 0),
            '%active%' => (int) ($treatment['active'] ?? 0),
            '%overdue%' => (int) ($treatment['overdue'] ?? 0),
        ], 'management_review', $locale);
    }
}
