<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\MfaToken;
use App\Entity\User;
use App\Repository\MfaTokenRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Security\Voter\UserVoter;
use App\Service\AuditLogger;
use App\Service\MfaService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI for MFA-Token management (TOTP / WebAuthn / Backup codes).
 *
 * Role-Scope: class-level {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT}
 *. Tenant admins manage MFA tokens for
 * their own users; SUPER_ADMIN passes through transparently. Existing
 * method-level `MFA_VIEW`/`MFA_SETUP`/`MFA_MANAGE`/`MFA_DELETE` permission
 * attributes stay authoritative for fine-grained access.
 *
 * Security hotfix (admin scope): MfaToken has no tenant column, so every
 * action is additionally bound to the token owner via {@see UserVoter::EDIT}
 * (actor tenant tree, never a SUPER_ADMIN target for non-SUPER actors).
 * Token-id addressed actions answer 404 when out of scope (no oracle).
 * Since S14 the voter applies the role ceiling, so holding-role accounts are
 * only reset by a super admin.
 *
 * Admins only reset (disable / delete) a second factor. They never enrol a
 * TOTP or regenerate backup codes for somebody else: whoever sees the secret
 * owns the factor. The person enrols again under Profile > MFA.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class MfaTokenController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MfaService $mfaService,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'mfa';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }
    #[Route('/admin/mfa', name: 'admin_mfa_index', methods: ['GET'])]
    #[IsGranted('MFA_VIEW')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $mfaTokens = $this->mfaTokenRepository->findAll();
        } else {
            $actor = $this->getUser();
            $mfaTokens = array_values(array_filter(
                $this->mfaTokenRepository->findForTenantsOrUser(
                    $this->tenantContext->getAccessibleTenants(),
                    $actor instanceof User ? $actor : null,
                ),
                fn(MfaToken $mfaToken): bool => $this->isGranted(UserVoter::EDIT, $mfaToken->getUser()),
            ));
        }

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
    #[Route('/admin/mfa/{id}', name: 'admin_mfa_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('MFA_VIEW')]
    public function show(MfaToken $mfaToken): Response
    {
        $this->assertTokenInScope($mfaToken);

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
        $this->assertTokenInScope($mfaToken);

        if (!$this->isCsrfTokenValid('disable_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->flashError('mfa.token.error.invalid_csrf');
            return $this->redirectToRoute('admin_mfa_show', ['id' => $mfaToken->getId()]);
        }

        $user = $mfaToken->getUser();
        $this->mfaService->disableMfaToken($mfaToken);

        $this->logger->warning('MFA token disabled by admin', [
            'admin_user' => $this->getUser()?->getUserIdentifier(),
            'target_user' => $user->getEmail(),
            'token_type' => $mfaToken->getTokenType(),
        ]);

        $this->flashSuccess('mfa.token.success.disabled');

        return $this->redirectToRoute('admin_mfa_index');
    }
    #[Route('/admin/mfa/{id}/delete', name: 'admin_mfa_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('MFA_DELETE')]
    public function delete(MfaToken $mfaToken, Request $request): Response
    {
        $this->assertTokenInScope($mfaToken);

        if (!$this->isCsrfTokenValid('delete_' . $mfaToken->getId(), $request->request->get('_token'))) {
            $this->flashError('mfa.token.error.invalid_csrf');
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

        $this->flashSuccess('mfa.token.success.deleted');

        return $this->redirectToRoute('admin_mfa_index');
    }

    /**
     * 404 (not 403) for tokens whose owner the actor may not administer, so
     * token ids of other tenants / SUPER_ADMINs are not confirmed to exist.
     */
    private function assertTokenInScope(MfaToken $mfaToken): void
    {
        $owner = $mfaToken->getUser();
        if (!$owner instanceof User || !$this->isGranted(UserVoter::EDIT, $owner)) {
            throw $this->createNotFoundException();
        }
    }
}
