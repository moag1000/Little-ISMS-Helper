<?php

namespace App\Controller;

use App\Entity\Incident;
use App\Form\IncidentType;
use App\Repository\IncidentRepository;
use App\Service\EmailNotificationService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/incident')]
class IncidentController extends AbstractController
{
    public function __construct(
        private IncidentRepository $incidentRepository,
        private EntityManagerInterface $entityManager,
        private EmailNotificationService $emailService,
        private UserRepository $userRepository,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_incident_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $openIncidents = $this->incidentRepository->findOpenIncidents();
        $allIncidents = $this->incidentRepository->findAll();
        $categoryStats = $this->incidentRepository->countByCategory();

        return $this->render('incident/index.html.twig', [
            'openIncidents' => $openIncidents,
            'allIncidents' => $allIncidents,
            'categoryStats' => $categoryStats,
        ]);
    }

    #[Route('/new', name: 'app_incident_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $incident = new Incident();
        $form = $this->createForm(IncidentType::class, $incident);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($incident);
            $this->entityManager->flush();

            // Send notification for high/critical severity incidents
            if (in_array($incident->getSeverity(), ['high', 'critical'])) {
                $admins = $this->userRepository->findByRole('ROLE_ADMIN');
                $this->emailService->sendIncidentNotification($incident, $admins);
            }

            $this->addFlash('success', $this->translator->trans('incident.success.reported'));
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        return $this->render('incident/new.html.twig', [
            'incident' => $incident,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_incident_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Incident $incident): Response
    {
        return $this->render('incident/show.html.twig', [
            'incident' => $incident,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_incident_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Incident $incident): Response
    {
        $originalStatus = $incident->getStatus();
        $form = $this->createForm(IncidentType::class, $incident);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // Send notification if status changed
            if ($originalStatus !== $incident->getStatus()) {
                $admins = $this->userRepository->findByRole('ROLE_ADMIN');
                $changeDescription = "Status changed from {$originalStatus} to {$incident->getStatus()}";
                $this->emailService->sendIncidentUpdateNotification($incident, $admins, $changeDescription);
            }

            $this->addFlash('success', $this->translator->trans('incident.success.updated'));
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        return $this->render('incident/edit.html.twig', [
            'incident' => $incident,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_incident_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Incident $incident): Response
    {
        if ($this->isCsrfTokenValid('delete'.$incident->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($incident);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('incident.success.deleted'));
        }

        return $this->redirectToRoute('app_incident_index');
    }
}
