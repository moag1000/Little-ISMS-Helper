<?php

namespace App\Controller;

use App\Entity\MfaToken;
use App\Form\MfaTokenType;
use App\Repository\MfaTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/mfa-token')]
#[IsGranted('ROLE_ADMIN')]  // MFA management requires admin privileges
class MfaTokenController extends AbstractController
{
    public function __construct(
        private MfaTokenRepository $mfaTokenRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_mfa_token_index')]
    public function index(): Response
    {
        $mfaTokens = $this->mfaTokenRepository->findAll();

        // Statistics
        $activeCount = count(array_filter($mfaTokens, fn($t) => $t->isActive()));
        $totpCount = count(array_filter($mfaTokens, fn($t) => $t->getTokenType() === 'totp'));
        $webauthnCount = count(array_filter($mfaTokens, fn($t) => $t->getTokenType() === 'webauthn'));

        return $this->render('mfa_token/index.html.twig', [
            'mfa_tokens' => $mfaTokens,
            'active_count' => $activeCount,
            'totp_count' => $totpCount,
            'webauthn_count' => $webauthnCount,
        ]);
    }

    #[Route('/new', name: 'app_mfa_token_new')]
    public function new(Request $request): Response
    {
        $mfaToken = new MfaToken();
        $form = $this->createForm(MfaTokenType::class, $mfaToken);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($mfaToken);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('mfa_token.success.created'));
            return $this->redirectToRoute('app_mfa_token_show', ['id' => $mfaToken->getId()]);
        }

        return $this->render('mfa_token/new.html.twig', [
            'mfa_token' => $mfaToken,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_mfa_token_show', requirements: ['id' => '\d+'])]
    public function show(MfaToken $mfaToken): Response
    {
        return $this->render('mfa_token/show.html.twig', [
            'mfa_token' => $mfaToken,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_mfa_token_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, MfaToken $mfaToken): Response
    {
        $form = $this->createForm(MfaTokenType::class, $mfaToken);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('mfa_token.success.updated'));
            return $this->redirectToRoute('app_mfa_token_show', ['id' => $mfaToken->getId()]);
        }

        return $this->render('mfa_token/edit.html.twig', [
            'mfa_token' => $mfaToken,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_mfa_token_delete', methods: ['POST'])]
    public function delete(Request $request, MfaToken $mfaToken): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mfaToken->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($mfaToken);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('mfa_token.success.deleted'));
        }

        return $this->redirectToRoute('app_mfa_token_index');
    }
}
