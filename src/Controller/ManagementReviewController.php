<?php

namespace App\Controller;

use DateTimeInterface;
use DateTimeImmutable;
use App\Entity\ManagementReview;
use App\Form\ManagementReviewType;
use App\Repository\ManagementReviewRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ManagementReviewController extends AbstractController
{
    public function __construct(
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/management-review/', name: 'app_management_review_index')]
    public function index(): Response
    {
        $reviews = $this->managementReviewRepository->findAll();

        // Calculate statistics
        $statistics = [
            'total' => count($reviews),
            'planned' => count($this->managementReviewRepository->findBy(['status' => 'planned'])),
            'completed' => count($this->managementReviewRepository->findBy(['status' => 'completed'])),
            'this_year' => count(array_filter($reviews, fn(ManagementReview $review): bool => $review->getReviewDate() instanceof DateTimeInterface &&
                   $review->getReviewDate()->format('Y') === date('Y'))),
        ];

        return $this->render('management_review/index.html.twig', [
            'reviews' => $reviews,
            'statistics' => $statistics,
        ]);
    }
    #[Route('/management-review/new', name: 'app_management_review_new')]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $managementReview = new ManagementReview();
        $managementReview->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ManagementReviewType::class, $managementReview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managementReview->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($managementReview);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.created'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $managementReview->getId()]);
        }

        return $this->render('management_review/new.html.twig', [
            'review' => $managementReview,
            'form' => $form,
        ]);
    }
    #[Route('/management-review/{id}', name: 'app_management_review_show', requirements: ['id' => '\d+'])]
    public function show(ManagementReview $managementReview): Response
    {
        return $this->render('management_review/show.html.twig', [
            'review' => $managementReview,
        ]);
    }
    #[Route('/management-review/{id}/edit', name: 'app_management_review_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ManagementReview $managementReview): Response
    {
        $form = $this->createForm(ManagementReviewType::class, $managementReview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managementReview->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.updated'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $managementReview->getId()]);
        }

        return $this->render('management_review/edit.html.twig', [
            'review' => $managementReview,
            'form' => $form,
        ]);
    }
    #[Route('/management-review/{id}/delete', name: 'app_management_review_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ManagementReview $managementReview): Response
    {
        if ($this->isCsrfTokenValid('delete'.$managementReview->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($managementReview);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.deleted'));
        }

        return $this->redirectToRoute('app_management_review_index');
    }
}
