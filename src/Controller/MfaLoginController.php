<?php

namespace App\Controller;

use App\Repository\MfaTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\MfaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * MFA Login Challenge Controller for NIS2 Compliance (Art. 21.2.b)
 *
 * Handles the second factor authentication after username/password login.
 */
class MfaLoginController extends AbstractController
{
    public function __construct(
        private readonly MfaService $mfaService,
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/mfa-challenge', name: 'app_mfa_challenge')]
    public function challenge(Request $request): Response
    {
        // Ensure user is authenticated but hasn't completed MFA yet
        $session = $request->getSession();

        if (!$session->get('_security.mfa_required')) {
            // No MFA required or already verified
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        $userId = $session->get('_security.mfa_user_id');
        $user = $this->userRepository->find($userId);

        if (!$user) {
            throw new AccessDeniedException('Invalid MFA session');
        }

        // Get active MFA tokens
        $mfaTokens = $this->mfaTokenRepository->findActiveByUser($user);

        if ($mfaTokens === []) {
            // User has no active MFA tokens anymore - mark as verified and continue
            $session->set('_security.mfa_verified', true);
            $session->remove('_security.mfa_required');
            $session->remove('_security.mfa_user_id');

            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        return $this->render('security/mfa_challenge.html.twig', [
            'user' => $user,
            'mfaTokens' => $mfaTokens,
            'error' => $session->getFlashBag()->get('mfa_error')[0] ?? null,
        ]);
    }

    #[Route('/mfa-verify', name: 'app_mfa_verify', methods: ['POST'])]
    public function verify(Request $request): Response|RedirectResponse
    {
        $session = $request->getSession();

        if (!$session->get('_security.mfa_required')) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        $userId = $session->get('_security.mfa_user_id');
        $user = $this->userRepository->find($userId);

        if (!$user) {
            throw new AccessDeniedException('Invalid MFA session');
        }

        // Get the submitted code and token ID
        $code = trim($request->request->get('code', ''));
        $tokenId = (int) $request->request->get('token_id');

        if ($code === '' || $code === '0') {
            $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.code_required'));
            return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
        }

        // Find the MFA token
        $mfaToken = $this->mfaTokenRepository->find($tokenId);

        if (!$mfaToken || $mfaToken->getUser()->getId() !== $user->getId() || !$mfaToken->isActive()) {
            $this->auditLogger->logCustom('mfa_verification_failed', 'User', $userId, [
                'reason' => 'Invalid or inactive token',
                'ip' => $request->getClientIp(),
            ]);

            $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.invalid_token'));
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
            $session->set('_security.mfa_verified', true);
            $session->remove('_security.mfa_required');
            $session->remove('_security.mfa_user_id');

            $this->auditLogger->logCustom('mfa_verification_success', 'User', $userId, [
                'token_type' => $mfaToken->getTokenType(),
                'device' => $mfaToken->getDeviceName(),
                'ip' => $request->getClientIp(),
            ]);

            $this->addFlash('success', $this->translator->trans('mfa.challenge.success'));

            // Redirect to original target or dashboard
            $targetPath = $session->get('_security.main.target_path');

            if ($targetPath) {
                $session->remove('_security.main.target_path');
                return new RedirectResponse($targetPath);
            }

            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        // Verification failed
        $this->auditLogger->logCustom('mfa_verification_failed', 'User', $userId, [
            'token_type' => $mfaToken->getTokenType(),
            'device' => $mfaToken->getDeviceName(),
            'ip' => $request->getClientIp(),
        ]);

        $this->addFlash('mfa_error', $this->translator->trans('mfa.challenge.error.invalid_code'));
        return $this->redirectToRoute('app_mfa_challenge', ['_locale' => $request->getLocale()]);
    }
}
