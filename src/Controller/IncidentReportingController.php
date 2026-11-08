<?php

declare(strict_types=1);
// @em-write-allowed: one assessment or report timestamp per action; audit log and SLA sync run in Doctrine listeners.

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Incident;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReportingPlanner $planner,
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

    #[Route('/incident/{id}/reporting/{field}/mark', name: 'app_incident_reporting_mark', requirements: ['id' => '\d+', 'field' => '[a-zA-Z]+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid(new Expression('"incident_reporting_" ~ args["incident"].getId()'))]
    public function markReported(Incident $incident, string $field): RedirectResponse
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
            match ($field) {
                'earlyWarningReportedAt' => $incident->setEarlyWarningReportedAt($now),
                'detailedNotificationReportedAt' => $incident->setDetailedNotificationReportedAt($now),
                'finalReportSubmittedAt' => $incident->setFinalReportSubmittedAt($now),
                'initialReportSubmittedAt' => $incident->setInitialReportSubmittedAt($now),
                'intermediateReportSubmittedAt' => $incident->setIntermediateReportSubmittedAt($now),
                'doraFinalReportSubmittedAt' => $incident->setDoraFinalReportSubmittedAt($now),
                'nis2InterimReportedAt' => $incident->setNis2InterimReportedAt($now),
                default => throw new NotFoundHttpException('No such reporting duty on this incident.'),
            };
            $this->entityManager->flush();
        }
        $this->addFlash('success', 'incident.flash.marked_reported');

        return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId(), '_fragment' => 'nis2-reporting']);
    }
}
