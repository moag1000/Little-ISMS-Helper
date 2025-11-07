<?php

namespace App\Controller;

use App\Entity\ISMSContext;
use App\Form\ISMSContextType;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/context')]
class ContextController extends AbstractController
{
    public function __construct(
        private ISMSContextRepository $contextRepository,
        private ISMSObjectiveRepository $objectiveRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_context_index')]
    public function index(): Response
    {
        $context = $this->contextRepository->getCurrentContext();
        $objectives = $this->objectiveRepository->findActive();

        return $this->render('context/index.html.twig', [
            'context' => $context,
            'objectives' => $objectives,
        ]);
    }

    #[Route('/edit', name: 'app_context_edit')]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request): Response
    {
        $context = $this->contextRepository->getCurrentContext();

        if (!$context) {
            $context = new ISMSContext();
            $context->setOrganizationName('');
            $this->entityManager->persist($context);
        }

        $form = $this->createForm(ISMSContextType::class, $context);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $context->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            $this->addFlash('success', 'ISMS Context updated successfully.');
            return $this->redirectToRoute('app_context_index');
        }

        return $this->render('context/edit.html.twig', [
            'context' => $context,
            'form' => $form,
        ]);
    }
}
