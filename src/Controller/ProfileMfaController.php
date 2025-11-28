<?php

namespace App\Controller;

use Exception;
use App\Entity\MfaToken;
use App\Repository\MfaTokenRepository;
use App\Service\AuditLogger;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User-facing MFA self-service controller
 * Allows regular users to manage their own MFA tokens without admin privileges
 */
#[IsGranted('ROLE_USER')]
class ProfileMfaController extends AbstractController
{
    public function __construct(
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MfaService $mfaService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator
    ) {}

    #[Route('/profile/mfa', name: 'app_profile_mfa_index')]
    public function index(): Response
    {
        $user = $this->getUser();
        $mfaTokens = $this->mfaTokenRepository->findBy(['user' => $user], ['createdAt' => 'DESC']);

        // Calculate MFA statistics
        $activeTokens = array_filter($mfaTokens, fn(MfaToken $mfaToken): bool => $mfaToken->isActive());
        $tokensByType = [];
        foreach ($mfaTokens as $mfaToken) {
            $type = $mfaToken->getTokenType();
            if (!isset($tokensByType[$type])) {
                $tokensByType[$type] = 0;
            }
            $tokensByType[$type]++;
        }

        return $this->render('profile/mfa/index.html.twig', [
            'user' => $user,
            'mfa_tokens' => $mfaTokens,
            'active_tokens_count' => count($activeTokens),
            'tokens_by_type' => $tokensByType,
            'mfa_enabled' => count($activeTokens) > 0,
        ]);
    }

    #[Route('/profile/mfa/setup-totp', name: 'app_profile_mfa_setup_totp')]
    public function setupTotp(Request $request): Response
    {
        $user = $this->getUser();
        $deviceName = $request->query->get('device_name', $this->translator->trans('mfa.default_device_name', [], 'mfa'));

        // Generate TOTP secret
        $mfaToken = $this->mfaService->generateTotpSecret($user, $deviceName);

        // Generate QR code
        $qrCode = $this->mfaService->generateQrCode($mfaToken);

        // Get backup codes (these are shown only once)
        $backupCodes = $mfaToken->temporaryBackupCodes ?? [];

        $this->logger->info('User initiated TOTP setup', [
            'user' => $user->getUserIdentifier(),
            'device_name' => $deviceName,
        ]);

        $this->auditLogger->logCustom(
            'mfa_totp_setup_initiated',
            'MfaToken',
            $mfaToken->getId(),
            null,
            ['user_email' => $user->getEmail(), 'device_name' => $deviceName],
            sprintf('User %s initiated TOTP setup', $user->getEmail())
        );

        return $this->render('profile/mfa/setup_totp.html.twig', [
            'mfa_token' => $mfaToken,
            'user' => $user,
            'qr_code' => $qrCode,
            'backup_codes' => $backupCodes,
            'secret' => $mfaToken->getSecret(),
        ]);
    }

    #[Route('/profile/mfa/{id}/verify', name: 'app_profile_mfa_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function verify(MfaToken $mfaToken, Request $request): JsonResponse
    {
        // Verify user owns this token
        if ($mfaToken->getUser()->getId() !== $this->getUser()->getId()) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('mfa.error.access_denied', [], 'mfa')
            ], Response::HTTP_FORBIDDEN);
        }

        $code = $request->request->get('code');

        if (!$code) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('mfa.error.code_required', [], 'mfa')
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $isValid = $this->mfaService->verifyTotp($mfaToken, $code, isSetup: true);

            if ($isValid) {
                $this->logger->info('User verified and activated MFA token', [
                    'user' => $this->getUser()->getUserIdentifier(),
                    'token_id' => $mfaToken->getId(),
                ]);

                return new JsonResponse([
                    'success' => true,
                    'message' => $this->translator->trans('mfa.success.verified', [], 'mfa'),
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('mfa.error.invalid_code', [], 'mfa'),
            ], Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            $this->logger->error('MFA verification failed', [
                'error' => $e->getMessage(),
                'token_id' => $mfaToken->getId(),
                'user' => $this->getUser()->getUserIdentifier(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => $this->translator->trans('mfa.error.verification_failed', [], 'mfa'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/profile/mfa/{id}/regenerate-backup-codes', name: 'app_profile_mfa_regenerate_backup', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function regenerateBackupCodes(MfaToken $mfaToken, Request $request): Response
    {
        // Verify user owns this token
        if ($mfaToken->getUser()->getId() !== $this->getUser()->getId()) {
            $this->addFlash('danger', $this->translator->trans('mfa.error.access_denied', [], 'mfa'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        if (!$this->isCsrfTokenValid('regenerate_backup_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('common.error.invalid_csrf', [], 'messages'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        $backupCodes = $this->mfaService->regenerateBackupCodes($mfaToken);

        $this->addFlash('success', $this->translator->trans('mfa.success.backup_codes_regenerated', [], 'mfa'));

        $this->logger->info('User regenerated backup codes', [
            'user' => $this->getUser()->getUserIdentifier(),
            'token_id' => $mfaToken->getId(),
        ]);

        return $this->render('profile/mfa/backup_codes.html.twig', [
            'mfa_token' => $mfaToken,
            'backup_codes' => $backupCodes,
        ]);
    }

    #[Route('/profile/mfa/{id}', name: 'app_profile_mfa_show', requirements: ['id' => '\d+'])]
    public function show(MfaToken $mfaToken): Response
    {
        // Verify user owns this token
        if ($mfaToken->getUser()->getId() !== $this->getUser()->getId()) {
            $this->addFlash('danger', $this->translator->trans('mfa.error.access_denied', [], 'mfa'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        $remainingBackupCodes = count($mfaToken->getBackupCodes() ?? []);

        return $this->render('profile/mfa/show.html.twig', [
            'mfa_token' => $mfaToken,
            'remaining_backup_codes' => $remainingBackupCodes,
        ]);
    }

    #[Route('/profile/mfa/{id}/disable', name: 'app_profile_mfa_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function disable(MfaToken $mfaToken, Request $request): Response
    {
        // Verify user owns this token
        if ($mfaToken->getUser()->getId() !== $this->getUser()->getId()) {
            $this->addFlash('danger', $this->translator->trans('mfa.error.access_denied', [], 'mfa'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        if (!$this->isCsrfTokenValid('disable_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('common.error.invalid_csrf', [], 'messages'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        $this->mfaService->disableMfaToken($mfaToken);

        $this->logger->info('User disabled MFA token', [
            'user' => $this->getUser()->getUserIdentifier(),
            'token_type' => $mfaToken->getTokenType(),
        ]);

        $this->addFlash('success', $this->translator->trans('mfa.success.disabled', [], 'mfa'));

        return $this->redirectToRoute('app_profile_mfa_index');
    }

    #[Route('/profile/mfa/{id}/delete', name: 'app_profile_mfa_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(MfaToken $mfaToken, Request $request): Response
    {
        // Verify user owns this token
        if ($mfaToken->getUser()->getId() !== $this->getUser()->getId()) {
            $this->addFlash('danger', $this->translator->trans('mfa.error.access_denied', [], 'mfa'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        if (!$this->isCsrfTokenValid('delete_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('common.error.invalid_csrf', [], 'messages'));
            return $this->redirectToRoute('app_profile_mfa_index');
        }

        $tokenType = $mfaToken->getTokenType();
        $tokenId = $mfaToken->getId();

        $this->entityManager->remove($mfaToken);
        $this->entityManager->flush();

        $this->logger->info('User deleted MFA token', [
            'user' => $this->getUser()->getUserIdentifier(),
            'token_type' => $tokenType,
        ]);

        $this->auditLogger->logCustom(
            'mfa_token_deleted',
            'MfaToken',
            $tokenId,
            ['token_type' => $tokenType],
            null,
            sprintf('User %s deleted their MFA token', $this->getUser()->getUserIdentifier())
        );

        $this->addFlash('success', $this->translator->trans('mfa.success.deleted', [], 'mfa'));

        return $this->redirectToRoute('app_profile_mfa_index');
    }
}
