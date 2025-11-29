<?php

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use App\Entity\ISMSObjective;
use App\Form\ISMSObjectiveType;
use App\Repository\ISMSObjectiveRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ISMSObjectiveController extends AbstractController
{
    public function __construct(
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/objective/', name: 'app_objective_index')]
    public function index(): Response
    {
        $objectives = $this->ismsObjectiveRepository->findAll();
        $active = $this->ismsObjectiveRepository->findActive();

        $statistics = [
            'total' => count($objectives),
            'active' => count($active),
            'achieved' => count($this->ismsObjectiveRepository->findBy(['status' => 'achieved'])),
            'delayed' => count(array_filter($objectives, fn(ISMSObjective $obj): bool => $obj->getStatus() === 'in_progress' &&
                   $obj->getTargetDate() < new DateTime() &&
                   !$obj->getAchievedDate())),
        ];

        return $this->render('objective/index.html.twig', [
            'objectives' => $objectives,
            'statistics' => $statistics,
        ]);
    }
    #[Route('/objective/new', name: 'app_objective_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $ismsObjective = new ISMSObjective();
        $ismsObjective->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ISMSObjectiveType::class, $ismsObjective);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ismsObjective->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->persist($ismsObjective);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('objective.success.created'));
            return $this->redirectToRoute('app_objective_show', ['id' => $ismsObjective->getId()]);
        }

        return $this->render('objective/new.html.twig', [
            'objective' => $ismsObjective,
            'form' => $form,
        ]);
    }
    #[Route('/objective/{id}', name: 'app_objective_show', requirements: ['id' => '\d+'])]
    public function show(ISMSObjective $ismsObjective): Response
    {
        return $this->render('objective/show.html.twig', [
            'objective' => $ismsObjective,
        ]);
    }
    #[Route('/objective/{id}/edit', name: 'app_objective_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ISMSObjective $ismsObjective): Response
    {
        $form = $this->createForm(ISMSObjectiveType::class, $ismsObjective);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ismsObjective->setUpdatedAt(new DateTimeImmutable());

            // Automatically set achieved date when status changes to achieved
            if ($ismsObjective->getStatus() === 'achieved' && !$ismsObjective->getAchievedDate()) {
                $ismsObjective->setAchievedDate(new DateTime());
            }

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('objective.success.updated'));
            return $this->redirectToRoute('app_objective_show', ['id' => $ismsObjective->getId()]);
        }

        return $this->render('objective/edit.html.twig', [
            'objective' => $ismsObjective,
            'form' => $form,
        ]);
    }
    #[Route('/objective/{id}/delete', name: 'app_objective_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ISMSObjective $ismsObjective): Response
    {
        if ($this->isCsrfTokenValid('delete'.$ismsObjective->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($ismsObjective);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('objective.success.deleted'));
        }

        return $this->redirectToRoute('app_objective_index');
    }
}
