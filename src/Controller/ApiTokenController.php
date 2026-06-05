<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F6 — self-service read-only API token management for the logged-in user.
 */
#[IsGranted('ROLE_USER')]
final class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenRepository $repository,
        private readonly ApiTokenManager $manager,
    ) {
    }

    #[Route('/profile/api-tokens', name: 'app_api_tokens', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();

        return $this->render('api_token/index.html.twig', [
            'tokens'    => $this->repository->findBy(['user' => $user], ['createdAt' => 'DESC']),
            'new_token' => null,
        ]);
    }

    #[Route('/profile/api-tokens/create', name: 'app_api_tokens_create', methods: ['POST'])]
    #[IsCsrfTokenValid('api_token_create')]
    public function create(Request $request): Response
    {
        $user = $this->currentUser();
        $label = trim((string) $request->request->get('label')) ?: 'API token';
        $expiresRaw = $request->request->get('expires_days');
        $expiresDays = is_numeric($expiresRaw) ? (int) $expiresRaw : null;

        $plain = $this->manager->mint($user, $label, $expiresDays);

        // Render the list with the freshly-minted plaintext shown ONCE.
        return $this->render('api_token/index.html.twig', [
            'tokens'    => $this->repository->findBy(['user' => $user], ['createdAt' => 'DESC']),
            'new_token' => $plain,
        ]);
    }

    #[Route('/profile/api-tokens/{id}/revoke', name: 'app_api_tokens_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('api_token_revoke')]
    public function revoke(int $id, Request $request): Response
    {
        $user = $this->currentUser();
        $token = $this->repository->find($id);
        if (!$token instanceof ApiToken || $token->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Token not found.');
        }

        $this->manager->revoke($token);
        $this->addFlash('success', 'api_token.flash.revoked');

        return $this->redirectToRoute('app_api_tokens', ['_locale' => $request->getLocale()]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
