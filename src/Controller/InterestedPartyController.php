<?php

namespace App\Controller;

use App\Entity\InterestedParty;
use App\Form\InterestedPartyType;
use App\Repository\InterestedPartyRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/interested-party')]
class InterestedPartyController extends AbstractController
{
    public function __construct(
        private InterestedPartyRepository $interestedPartyRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_interested_party_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $interestedParties = $this->interestedPartyRepository->findAll();
        $overdueCommunications = $this->interestedPartyRepository->findOverdueCommunications();
        $highImportance = $this->interestedPartyRepository->findHighImportance();

        return $this->render('interested_party/index.html.twig', [
            'interested_parties' => $interestedParties,
            'overdue_communications' => $overdueCommunications,
            'high_importance' => $highImportance,
        ]);
    }

    #[Route('/new', name: 'app_interested_party_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $interestedParty = new InterestedParty();
        $interestedParty->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(InterestedPartyType::class, $interestedParty);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($interestedParty);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('interested_party.success.created'));
            return $this->redirectToRoute('app_interested_party_show', ['id' => $interestedParty->getId()]);
        }

        return $this->render('interested_party/new.html.twig', [
            'interested_party' => $interestedParty,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_interested_party_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(InterestedParty $interestedParty): Response
    {
        return $this->render('interested_party/show.html.twig', [
            'interested_party' => $interestedParty,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_interested_party_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, InterestedParty $interestedParty): Response
    {
        $form = $this->createForm(InterestedPartyType::class, $interestedParty);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $interestedParty->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('interested_party.success.updated'));
            return $this->redirectToRoute('app_interested_party_show', ['id' => $interestedParty->getId()]);
        }

        return $this->render('interested_party/edit.html.twig', [
            'interested_party' => $interestedParty,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_interested_party_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, InterestedParty $interestedParty): Response
    {
        if ($this->isCsrfTokenValid('delete'.$interestedParty->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($interestedParty);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('interested_party.success.deleted'));
        }

        return $this->redirectToRoute('app_interested_party_index');
    }
}
