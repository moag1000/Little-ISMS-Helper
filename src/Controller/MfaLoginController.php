<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\LandingRoute;
use App\Security\SafeRedirectTarget;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Repository\MfaTokenRepository;
use App\Service\AuditLogger;
use App\Entity\User;
use App\Security\Mfa\MfaSessionState;
use App\Service\MfaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * MFA Login Challenge Controller for NIS2 Compliance (Art. 21.2.b)
 *
 * Handles the second factor authentication after username/password login.
 */
class MfaLoginController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly MfaService $mfaService,
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuditLogger $auditLogger,
        private readonly TranslatorInterface $translator,
        private readonly LandingRoute $landingRoute,
        private readonly MfaSessionState $mfaState,
        private readonly RememberMeHandlerInterface $rememberMeHandler,
    ) {
    }

    protected function getFlashDomain(): string
    {
        return 'mfa';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    #[Route('/mfa-challenge', name: 'app_mfa_challenge', methods: ['GET'])]
    public function challenge(Request $request): Response
    {
        // Ensure user is authenticated but hasn't completed MFA yet
        $session = $request->getSession();
        $user = $this->pendingUser($request);

        if (!$user instanceof User) {
            // No MFA required or already verified
            return $this->redirectToRoute($this->landingRoute->routeFor($this->getUser()), ['_locale' => $request->getLocale()]);
        }

        // Get active MFA tokens
        $mfaTokens = $this->mfaTokenRepository->findActiveByUser($user);

        if ($mfaTokens === []) {
            // User has no active MFA tokens anymore - mark as verified and continue
            $this->completeSecondFactor($request, $user);

            return $this->redirectToRoute($this->landingRoute->routeFor($this->getUser()), ['_locale' => $request->getLocale()]);
        }

        return $this->render('security/mfa_challenge.html.twig', [
            'user' => $user,
            'mfaTokens' => $mfaTokens,
            'error' => $session instanceof FlashBagAwareSessionInterface
                ? ($session->getFlashBag()->get('mfa_error')[0] ?? null)
                : null,
        ]);
    }

    #[Route('/mfa-verify', name: 'app_mfa_verify', methods: ['POST'])]
    public function verify(Request $request): Response|RedirectResponse
    {
        $session = $request->getSession();
        $user = $this->pendingUser($request);

        if (!$user instanceof User) {
            return $this->redirectToRoute($this->landingRoute->routeFor($this->getUser()), ['_locale' => $request->getLocale()]);
        }
        $userId = $user->getId();

        if (!$this->isCsrfTokenValid('mfa_verify', (string) $request->request->get('_token'))) {
            $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.invalid_token', [], 'mfa'));
            return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
        }

        // Get the submitted code and token ID
        $code = trim($request->request->get('code', ''));
        $tokenId = (int) $request->request->get('token_id');

        if ($code === '' || $code === '0') {
            $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.code_required', [], 'mfa'));
            return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
        }

        // Find the MFA token
        $mfaToken = $this->mfaTokenRepository->find($tokenId);

        if (!$mfaToken || $mfaToken->getUser()->getId() !== $user->getId() || !$mfaToken->isActive()) {
            $this->auditLogger->logCustom('mfa_verification_failed', 'User', $userId, [
                'reason' => 'Invalid or inactive token',
                'ip' => $request->getClientIp(),
            ]);

            $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.invalid_token', [], 'mfa'));
            return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
        }

        // Verify the code (TOTP or Backup Code)
        $verified = false;

        if ($mfaToken->getTokenType() === 'totp') {
            $verified = $this->mfaService->verifyTotp($mfaToken, $code, false);
        }

        // If TOTP fails, try backup code
        if (!$verified) {
            $verified = $this->mfaService->verifyBackupCode($mfaToken, $code);
        }

        if ($verified) {
            // MFA verification successful
            $this->completeSecondFactor($request, $user);

            $this->auditLogger->logCustom('mfa_verification_success', 'User', $userId, [
                'token_type' => $mfaToken->getTokenType(),
                'device' => $mfaToken->getDeviceName(),
                'ip' => $request->getClientIp(),
            ]);

            $this->flashSuccess('mfa.challenge.success');

            // Redirect to original target or dashboard
            $targetPath = $session->get('_security.main.target_path');

            if ($targetPath) {
                $session->remove('_security.main.target_path');
                // Re-validate: the stored target may come from a crafted re-auth link.
                if (SafeRedirectTarget::isSafe((string) $targetPath, $request)) {
                    return new RedirectResponse($targetPath);
                }
            }

            return $this->redirectToRoute($this->landingRoute->routeFor($this->getUser()), ['_locale' => $request->getLocale()]);
        }

        // Verification failed
        $this->auditLogger->logCustom('mfa_verification_failed', 'User', $userId, [
            'token_type' => $mfaToken->getTokenType(),
            'device' => $mfaToken->getDeviceName(),
            'ip' => $request->getClientIp(),
        ]);

        $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.invalid_code', [], 'mfa'));
        return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
    }

    /**
     * The logged-in person whose second factor is outstanding — never a user id from elsewhere.
     */
    private function pendingUser(Request $request): ?User
    {
        $subject = MfaSessionState::subjectOf($this->tokenStorage->getToken());
        if (!$subject instanceof User || !$this->mfaState->isPending($request->getSession(), $subject)) {
            return null;
        }

        return $subject;
    }

    /** Second factor given: the login is complete, "stay logged in" takes effect only now. */
    private function completeSecondFactor(Request $request, User $user): void
    {
        if ($this->mfaState->markVerified($request->getSession(), $user)) {
            $this->rememberMeHandler->createRememberMeCookie($user);
        }
    }
}
