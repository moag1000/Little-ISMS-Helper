<?php

namespace App\Controller;

use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/context')]
class ContextController extends AbstractController
{
    public function __construct(
        private ISMSContextRepository $contextRepository,
        private ISMSObjectiveRepository $objectiveRepository
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
}
