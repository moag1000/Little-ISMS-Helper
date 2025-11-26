<?php

namespace App\Controller;

use App\Entity\ManagementReview;
use App\Form\ManagementReviewType;
use App\Repository\ManagementReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/management-review')]
class ManagementReviewController extends AbstractController
{
    public function __construct(
        private ManagementReviewRepository $reviewRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_management_review_index')]
    public function index(): Response
    {
        $reviews = $this->reviewRepository->findAll();

        // Calculate statistics
        $statistics = [
            'total' => count($reviews),
            'planned' => count($this->reviewRepository->findBy(['status' => 'planned'])),
            'completed' => count($this->reviewRepository->findBy(['status' => 'completed'])),
            'this_year' => count(array_filter($reviews, function($review) {
                return $review->getReviewDate() &&
                       $review->getReviewDate()->format('Y') === date('Y');
            })),
        ];

        return $this->render('management_review/index.html.twig', [
            'reviews' => $reviews,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/new', name: 'app_management_review_new')]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $review = new ManagementReview();
        $form = $this->createForm(ManagementReviewType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $review->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($review);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.created'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $review->getId()]);
        }

        return $this->render('management_review/new.html.twig', [
            'review' => $review,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_management_review_show', requirements: ['id' => '\d+'])]
    public function show(ManagementReview $review): Response
    {
        return $this->render('management_review/show.html.twig', [
            'review' => $review,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_management_review_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ManagementReview $review): Response
    {
        $form = $this->createForm(ManagementReviewType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $review->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.updated'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $review->getId()]);
        }

        return $this->render('management_review/edit.html.twig', [
            'review' => $review,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_management_review_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ManagementReview $review): Response
    {
        if ($this->isCsrfTokenValid('delete'.$review->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($review);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.deleted'));
        }

        return $this->redirectToRoute('app_management_review_index');
    }
}
