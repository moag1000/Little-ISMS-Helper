<?php

declare(strict_types=1);
// @em-write-allowed: one assessment or report timestamp per action; audit log and SLA sync run in Doctrine listeners.

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Incident;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\AuditLogger;
use App\Service\Incident\ReportingPlanner;
use App\Util\InputDate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reporting duties of an incident (NIS-2 Art. 23, DORA Art. 19):
 *  - the organisation's assessment whether the incident is reportable
 *    (NIS-2 leaves that judgement to the entity; the tool only pre-checks),
 *  - "mark as reported" for each report, which closes its SLA clock.
 * The changes are ordinary entity updates: the audit log and the SLA
 * monitor sync pick them up.
 */
#[RequiresModule('nis2_dora')]
final class IncidentReportingController extends AbstractController
{
    /** A given time this close to the server's now is clock skew, not back-dating. */
    public const int BACKDATE_TOLERANCE_SECONDS = 900;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReportingPlanner $planner,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/incident/{id}/reporting/assess', name: 'app_incident_reporting_assess', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"incident_reporting_" ~ args["incident"].getId()'))]
    public function assess(Request $request, Incident $incident): RedirectResponse
    {
        $incident->setNotificationRequired($request->request->getBoolean('reportable'));
        $this->entityManager->flush();
        $this->addFlash('success', $incident->isNotificationRequired()
            ? 'incident.flash.assessed_reportable'
            : 'incident.flash.assessed_not_reportable');

        return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
    }

    /**
     * NIS-2 Art. 23 (4) (c): record that the CSIRT/authority asked for an
     * intermediate report, with the date it names. From then on the report is
     * a duty with a deadline like the others.
     */
    #[Route('/incident/{id}/reporting/interim-request', name: 'app_incident_reporting_interim', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"incident_reporting_" ~ args["incident"].getId()'))]
    public function requestInterim(Request $request, Incident $incident): RedirectResponse
    {
        if (!$incident->requiresNis2Reporting()) {
            throw new NotFoundHttpException('The incident has no NIS-2 reporting duty.');
        }
        $due = InputDate::parse($request->request->get('due_at'));
        $incident->setNis2InterimRequestedAt($incident->getNis2InterimRequestedAt() ?? new DateTimeImmutable());
        $incident->setNis2InterimDueAt($due !== null ? DateTimeImmutable::createFromMutable($due) : null);
        $this->entityManager->flush();
        $this->addFlash('success', 'incident.flash.interim_requested');

        return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
    }

    /**
     * S12: the only way a report time gets onto the incident (the form shows it
     * read-only). Without a time it is the server's now. A time that lies more than
     * BACKDATE_TOLERANCE_SECONDS before now is a back-dating (S34/R1): it needs the
     * reason (portal reference of the report), which is audit-logged with the actor
     * and the server time, because the deadline check counts this value as evidence.
     */
    #[Route('/incident/{id}/reporting/{field}/mark', name: 'app_incident_reporting_mark', requirements: ['id' => '\d+', 'field' => '[a-zA-Z]+'], methods: ['POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'incident')]
    #[IsCsrfTokenValid(new Expression('"incident_reporting_" ~ args["incident"].getId()'))]
    public function markReported(Request $request, Incident $incident, string $field): RedirectResponse
    {
        $duty = null;
        foreach ($this->planner->forIncident($incident) as $candidate) {
            if ($candidate->field === $field) {
                $duty = $candidate;
            }
        }
        if ($duty === null) {
            throw new NotFoundHttpException('No such reporting duty on this incident.');
        }

        if ($duty->reportedAt === null) {
            $now = new DateTimeImmutable();
            $given = InputDate::parse($request->request->get('reported_at'));
            $at = $given !== null ? DateTimeImmutable::createFromMutable($given) : $now;
            $detected = $incident->getDetectedAt();
            if ($at > $now || ($detected !== null && $at < $detected)) {
                $this->addFlash('danger', 'incident.flash.reported_at_invalid');

                return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
            }
            $backdatedBy = $now->getTimestamp() - $at->getTimestamp();
            $reason = null;
            if ($backdatedBy > self::BACKDATE_TOLERANCE_SECONDS) {
                $reason = trim((string) $request->request->get('backdate_reason'));
                if ($reason === '') {
                    $this->addFlash('danger', 'incident.flash.reported_at_reason_required');

                    return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
                }
                $reason = mb_substr($reason, 0, 1000);
            }
            match ($field) {
                'earlyWarningReportedAt' => $incident->setEarlyWarningReportedAt($at),
                'detailedNotificationReportedAt' => $incident->setDetailedNotificationReportedAt($at),
                'finalReportSubmittedAt' => $incident->setFinalReportSubmittedAt($at),
                'initialReportSubmittedAt' => $incident->setInitialReportSubmittedAt($at),
                'intermediateReportSubmittedAt' => $incident->setIntermediateReportSubmittedAt($at),
                'doraFinalReportSubmittedAt' => $incident->setDoraFinalReportSubmittedAt($at),
                'nis2InterimReportedAt' => $incident->setNis2InterimReportedAt($at),
                default => throw new NotFoundHttpException('No such reporting duty on this incident.'),
            };
            $this->entityManager->flush();
            if ($reason !== null) {
                $this->auditLogger->logCustom(
                    'incident.report_backdated',
                    'Incident',
                    $incident->getId(),
                    null,
                    ['field' => $field, 'reported_at' => $at->format(DATE_ATOM), 'recorded_at' => $now->format(DATE_ATOM), 'reason' => $reason],
                    'Report time set before the server time (portal reference: ' . $reason . ')',
                );
            }
        }
        $this->addFlash('success', 'incident.flash.marked_reported');

        return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
    }
}
