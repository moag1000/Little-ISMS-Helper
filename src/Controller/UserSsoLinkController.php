<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Sso\SsoAccountLinker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Binding an existing account to an SSO identity by hand (user detail page).
 * The rules live in {@see SsoAccountLinker}.
 */
#[IsGranted('ROLE_ADMIN')]
final class UserSsoLinkController extends AbstractController
{
    public function __construct(
        private readonly SsoAccountLinker $linker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/users/{id}/sso-link', name: 'user_management_sso_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"user_sso_link_" ~ args["user"].getId()'))]
    public function link(User $user, Request $request): Response
    {
        $error = $this->linker->link(
            $user,
            $request->request->getInt('provider'),
            (string) $request->request->get('subject', ''),
        );

        return $this->done($user, $error, 'user.sso_link.flash.linked');
    }

    #[Route('/admin/users/{id}/sso-unlink', name: 'user_management_sso_unlink', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"user_sso_unlink_" ~ args["user"].getId()'))]
    public function unlink(User $user): Response
    {
        return $this->done($user, $this->linker->unlink($user), 'user.sso_link.flash.unlinked');
    }

    private function done(User $user, ?string $error, string $successKey): Response
    {
        $this->addFlash(
            $error === null ? 'success' : 'error',
            $this->translator->trans($error ?? $successKey, [], 'user'),
        );

        return $this->redirectToRoute('user_management_show', ['id' => $user->getId(), '_fragment' => 'sso-link']);
    }
}
