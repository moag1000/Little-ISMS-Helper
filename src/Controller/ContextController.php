<?php

namespace App\Controller;

use App\Entity\ISMSContext;
use App\Form\ISMSContextType;
use App\Service\ISMSContextService;
use App\Service\ISMSObjectiveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/context')]
class ContextController extends AbstractController
{
    public function __construct(
        private ISMSContextService $contextService,
        private ISMSObjectiveService $objectiveService,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_context_index')]
    public function index(): Response
    {
        $context = $this->contextService->getCurrentContext();
        $statistics = $this->objectiveService->getStatistics();

        return $this->render('context/index.html.twig', [
            'context' => $context,
            'completeness' => $this->contextService->calculateCompleteness($context),
            'isReviewDue' => $this->contextService->isReviewDue($context),
            'daysUntilReview' => $this->contextService->getDaysUntilReview($context),
            'statistics' => $statistics,
        ]);
    }

    #[Route('/edit', name: 'app_context_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request): Response
    {
        $context = $this->contextService->getCurrentContext();

        $form = $this->createForm(ISMSContextType::class, $context);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $validationErrors = $this->contextService->validateContext($context);

            if (!empty($validationErrors)) {
                foreach ($validationErrors as $error) {
                    $this->addFlash('warning', $error);
                }
            }

            $this->contextService->saveContext($context);

            $this->addFlash('success', $this->translator->trans('context.success.updated'));
            return $this->redirectToRoute('app_context_index');
        }

        return $this->render('context/edit.html.twig', [
            'context' => $context,
            'form' => $form,
        ]);
    }
}
