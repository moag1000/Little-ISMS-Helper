<?php

namespace App\Controller;

use Exception;
use App\Entity\MfaToken;
use App\Entity\User;
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

class MfaTokenController extends AbstractController
{
    public function __construct(
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MfaService $mfaService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger
    ) {}
    #[Route('/admin/mfa', name: 'admin_mfa_index')]
    #[IsGranted('MFA_VIEW')]
    public function index(): Response
    {
        $mfaTokens = $this->mfaTokenRepository->findAll();

        // Statistics
        $activeCount = count(array_filter($mfaTokens, fn(MfaToken $mfaToken): bool => $mfaToken->isActive()));
        $totpCount = count(array_filter($mfaTokens, fn(MfaToken $mfaToken): bool => $mfaToken->getTokenType() === 'totp'));
        $webauthnCount = count(array_filter($mfaTokens, fn(MfaToken $mfaToken): bool => $mfaToken->getTokenType() === 'webauthn'));

        // Group tokens by user
        $tokensByUser = [];
        foreach ($mfaTokens as $mfaToken) {
            $userId = $mfaToken->getUser()->getId();
            if (!isset($tokensByUser[$userId])) {
                $tokensByUser[$userId] = [
                    'user' => $mfaToken->getUser(),
                    'tokens' => [],
                ];
            }
            $tokensByUser[$userId]['tokens'][] = $mfaToken;
        }

        return $this->render('mfa_token/index.html.twig', [
            'mfa_tokens' => $mfaTokens,
            'tokens_by_user' => $tokensByUser,
            'active_count' => $activeCount,
            'totp_count' => $totpCount,
            'webauthn_count' => $webauthnCount,
        ]);
    }
    #[Route('/admin/mfa/user/{id}/setup-totp', name: 'admin_mfa_setup_totp', requirements: ['id' => '\d+'])]
    #[IsGranted('MFA_SETUP')]
    public function setupTotp(User $user, Request $request): Response
    {
        $deviceName = $request->query->get('device_name', 'Authenticator App');

        // Generate TOTP secret
        $mfaToken = $this->mfaService->generateTotpSecret($user, $deviceName);

        // Generate QR code
        $qrCode = $this->mfaService->generateQrCode($mfaToken);

        // Get backup codes (these are shown only once)
        $backupCodes = $mfaToken->temporaryBackupCodes ?? [];

        $this->logger->info('TOTP setup initiated', [
            'admin_user' => $this->getUser()?->getUserIdentifier(),
            'target_user' => $user->getEmail(),
        ]);

        $this->auditLogger->logCustom(
            'mfa_totp_setup_initiated',
            'MfaToken',
            $mfaToken->getId(),
            null,
            ['user_email' => $user->getEmail()],
            sprintf('TOTP setup initiated for user %s by admin', $user->getEmail())
        );

        return $this->render('mfa_token/setup_totp.html.twig', [
            'mfa_token' => $mfaToken,
            'user' => $user,
            'qr_code' => $qrCode,
            'backup_codes' => $backupCodes,
            'secret' => $mfaToken->getSecret(),
        ]);
    }
    #[Route('/admin/mfa/{id}/verify', name: 'admin_mfa_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('MFA_MANAGE')]
    public function verify(MfaToken $mfaToken, Request $request): JsonResponse
    {
        $code = $request->request->get('code');

        if (!$code) {
            return new JsonResponse(['success' => false, 'message' => 'Code is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $isValid = $this->mfaService->verifyTotp($mfaToken, $code, isSetup: true);

            if ($isValid) {
                $this->logger->info('MFA token verified and activated', [
                    'admin_user' => $this->getUser()?->getUserIdentifier(),
                    'token_id' => $mfaToken->getId(),
                    'user' => $mfaToken->getUser()->getEmail(),
                ]);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'MFA token successfully verified and activated',
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid verification code',
            ], Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            $this->logger->error('MFA verification failed', [
                'error' => $e->getMessage(),
                'token_id' => $mfaToken->getId(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    #[Route('/admin/mfa/{id}/regenerate-backup-codes', name: 'admin_mfa_regenerate_backup', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('MFA_MANAGE')]
    public function regenerateBackupCodes(MfaToken $mfaToken, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('regenerate_backup_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('admin_mfa_show', ['id' => $mfaToken->getId()]);
        }

        $backupCodes = $this->mfaService->regenerateBackupCodes($mfaToken);

        $this->addFlash('success', 'Backup codes regenerated successfully');

        $this->logger->info('Backup codes regenerated', [
            'admin_user' => $this->getUser()?->getUserIdentifier(),
            'user' => $mfaToken->getUser()->getEmail(),
        ]);

        return $this->render('mfa_token/backup_codes.html.twig', [
            'mfa_token' => $mfaToken,
            'backup_codes' => $backupCodes,
        ]);
    }
    #[Route('/admin/mfa/{id}', name: 'admin_mfa_show', requirements: ['id' => '\d+'])]
    #[IsGranted('MFA_VIEW')]
    public function show(MfaToken $mfaToken): Response
    {
        $remainingBackupCodes = count($mfaToken->getBackupCodes() ?? []);

        return $this->render('mfa_token/show.html.twig', [
            'mfa_token' => $mfaToken,
            'remaining_backup_codes' => $remainingBackupCodes,
        ]);
    }
    #[Route('/admin/mfa/{id}/disable', name: 'admin_mfa_disable', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('MFA_MANAGE')]
    public function disable(MfaToken $mfaToken, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('disable_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('admin_mfa_show', ['id' => $mfaToken->getId()]);
        }

        $user = $mfaToken->getUser();
        $this->mfaService->disableMfaToken($mfaToken);

        $this->logger->warning('MFA token disabled by admin', [
            'admin_user' => $this->getUser()?->getUserIdentifier(),
            'target_user' => $user->getEmail(),
            'token_type' => $mfaToken->getTokenType(),
        ]);

        $this->addFlash('success', 'MFA token disabled successfully');

        return $this->redirectToRoute('admin_mfa_index');
    }
    #[Route('/admin/mfa/{id}/delete', name: 'admin_mfa_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('MFA_DELETE')]
    public function delete(MfaToken $mfaToken, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token');
            return $this->redirectToRoute('admin_mfa_index');
        }

        $mfaToken->getUser()->getId();
        $userEmail = $mfaToken->getUser()->getEmail();
        $tokenType = $mfaToken->getTokenType();
        $tokenId = $mfaToken->getId();

        $this->entityManager->remove($mfaToken);
        $this->entityManager->flush();

        $this->logger->warning('MFA token deleted by admin', [
            'admin_user' => $this->getUser()?->getUserIdentifier(),
            'target_user' => $userEmail,
            'token_type' => $tokenType,
        ]);

        $this->auditLogger->logCustom(
            'mfa_token_deleted',
            'MfaToken',
            $tokenId,
            ['user_email' => $userEmail, 'token_type' => $tokenType],
            null,
            sprintf('MFA token deleted for user %s by admin', $userEmail)
        );

        $this->addFlash('success', 'MFA token deleted successfully');

        return $this->redirectToRoute('admin_mfa_index');
    }
}
