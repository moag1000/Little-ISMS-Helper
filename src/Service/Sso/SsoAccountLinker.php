<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Repository\IdentityProviderRepository;
use App\Repository\UserRepository;
use App\Security\UserAdministrationGuard;
use App\Security\Voter\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * An administrator binds an existing account to an SSO identity (provider +
 * subject) by hand, or removes that binding.
 *
 * Automatic linking by e-mail never touches a privileged account and never
 * moves an account to another provider or subject — an IdP subject change
 * "needs admin action" ({@see SsoAccountLinkPolicy}). This is that action:
 *  - the actor may edit the account (UserVoter::EDIT); a privileged account
 *    (SUPER_ADMIN, group roles) only an instance operator,
 *  - the provider is enabled and may sign this account in at all
 *    ({@see SsoAccountLinkPolicy::mayAuthenticateAs()}: global, or the
 *    account's organisation tree and never a privileged account),
 *  - one subject per provider belongs to one account only,
 *  - every change is in the audit trail of the provider.
 */
final readonly class SsoAccountLinker
{
    public const int SUBJECT_MAX_LENGTH = 255;

    public function __construct(
        private IdentityProviderRepository $providerRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private SsoEventLogger $eventLogger,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function mayManage(User $target): bool
    {
        if (!$this->authorizationChecker->isGranted(UserVoter::EDIT, $target)) {
            return false;
        }

        // Privileged accounts: instance operator only, the rule of
        // UserAdministrationGuard::canManagePrivilegedRoles().
        return UserAdministrationGuard::privilegedRolesIn($target->getRoles()) === []
            || $this->authorizationChecker->isGranted('ROLE_SUPER_ADMIN');
    }

    /**
     * Enabled providers that can sign $target in, global ones first.
     *
     * @return list<IdentityProvider>
     */
    public function linkableProviders(User $target): array
    {
        return array_values(array_filter(
            $this->providerRepository->findAllEnabledUnfiltered(),
            static fn (IdentityProvider $provider): bool => (string) $provider->getSlug() !== ''
                && SsoAccountLinkPolicy::mayAuthenticateAs($provider, $target),
        ));
    }

    /**
     * @return string|null null on success, else the translation key (domain `user`) of the refusal
     */
    public function link(User $target, int $providerId, string $subject): ?string
    {
        if (!$this->mayManage($target)) {
            return 'user.sso_link.error.not_allowed';
        }
        $subject = trim($subject);
        if ($subject === '') {
            return 'user.sso_link.error.subject_required';
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX_LENGTH) {
            return 'user.sso_link.error.subject_too_long';
        }
        $provider = null;
        foreach ($this->linkableProviders($target) as $candidate) {
            if ($candidate->getId() === $providerId) {
                $provider = $candidate;
                break;
            }
        }
        if (!$provider instanceof IdentityProvider) {
            return 'user.sso_link.error.provider_not_allowed';
        }
        $holder = $this->userRepository->findOneBy(['ssoProvider' => $provider, 'ssoExternalId' => $subject]);
        if ($holder instanceof User && $holder !== $target) {
            return 'user.sso_link.error.subject_taken';
        }

        $previousProvider = $target->getSsoProvider();
        $previousSubject = $target->getSsoExternalId();
        if ($previousProvider !== null && $previousProvider->getId() === $provider->getId() && $previousSubject === $subject) {
            return null; // unchanged
        }

        $target->setSsoProvider($provider);
        $target->setSsoExternalId($subject);
        $target->setAuthProvider('sso:' . $provider->getSlug());
        $this->entityManager->flush();

        $this->eventLogger->logAccountLinked($provider, $target, $previousProvider, $previousSubject, $subject);

        return null;
    }

    /**
     * @return string|null null on success, else the translation key (domain `user`) of the refusal
     */
    public function unlink(User $target): ?string
    {
        if (!$this->mayManage($target)) {
            return 'user.sso_link.error.not_allowed';
        }
        $provider = $target->getSsoProvider();
        if (!$provider instanceof IdentityProvider) {
            return 'user.sso_link.error.not_linked';
        }
        $subject = $target->getSsoExternalId();

        $target->setSsoProvider(null);
        $target->setSsoExternalId(null);
        if (str_starts_with((string) $target->getAuthProvider(), 'sso:')) {
            $target->setAuthProvider('local');
        }
        $this->entityManager->flush();

        $this->eventLogger->logAccountUnlinked($provider, $target, $subject);

        return null;
    }
}
