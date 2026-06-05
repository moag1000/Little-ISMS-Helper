<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SupplierQuestionnaireRepository;
use App\Service\SupplierQuestionnaireService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * F23 — PUBLIC (unauthenticated) supplier-questionnaire portal.
 *
 * The ONLY surface a supplier touches: a signed token link. No login, no
 * session, no tenant data beyond the questionnaire itself. Token is compared in
 * constant time (hash_equals) against the stored value; an unknown/closed token
 * 404s. Mirrors the F43 Trust-Center disclosure-safety pattern.
 */
final class PublicSupplierQuestionnaireController extends AbstractController
{
    public function __construct(
        private readonly SupplierQuestionnaireRepository $repository,
        private readonly SupplierQuestionnaireService $service,
    ) {
    }

    #[Route('/supplier-questionnaire/{token}', name: 'public_supplier_questionnaire_show', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function show(string $token): Response
    {
        $questionnaire = $this->resolve($token);

        return $this->render('supplier_questionnaire/public_show.html.twig', [
            'questionnaire' => $questionnaire,
            'closed'        => !$questionnaire->isOpenForResponse(),
        ]);
    }

    #[Route('/supplier-questionnaire/{token}', name: 'public_supplier_questionnaire_submit', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function submit(string $token, Request $request): Response
    {
        $questionnaire = $this->resolve($token);

        if (!$this->isCsrfTokenValid('supplier_questionnaire_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var array<string, string> $answers */
        $answers = $request->request->all('answers');
        $accepted = $this->service->submitResponse($questionnaire, $answers);

        return $this->render('supplier_questionnaire/public_done.html.twig', [
            'questionnaire' => $questionnaire,
            'accepted'      => $accepted,
        ]);
    }

    private function resolve(string $token): \App\Entity\SupplierQuestionnaire
    {
        $questionnaire = $this->repository->findOneByToken($token);

        // Constant-time compare even though the lookup is by hash-shaped token —
        // defence in depth against timing oracles on the token surface.
        if ($questionnaire === null || !hash_equals($questionnaire->getPublicToken(), $token)) {
            throw $this->createNotFoundException('Questionnaire not found.');
        }

        return $questionnaire;
    }
}
