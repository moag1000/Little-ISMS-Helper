<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\EventSubscriber\SsoEnforcementSubscriber;
use App\EventSubscriber\TenantSsoEnforcementSubscriber;
use App\Repository\IdentityProviderRepository;
use App\Security\Voter\UserVoter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Accounts that SSO enforcement (Tenant::ssoEnforced or a provider with
 * domainBindingMode = enforce) applies to but that keep the password login,
 * because no enabled provider can sign them in (anti-lockout fail-open).
 * Shown on the SSO admin page so "SSO enforced" is not read as a full
 * guarantee in an audit. Uses the subscribers' own decision, and lists only
 * accounts the viewer may VIEW.
 */
class SsoEnforcementExemptions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantSsoEnforcementSubscriber $tenantEnforcement,
        private readonly SsoEnforcementSubscriber $domainEnforcement,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TenantContext $tenantContext,
        private readonly IdentityProviderRepository $providerRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    public function findVisible(): array
    {
        if (!$this->isAnyEnforcementConfigured()) {
            return [];
        }

        $queryBuilder = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->orderBy('u.email', 'ASC');
        if (!$this->authorizationChecker->isGranted('ROLE_SUPER_ADMIN')) {
            $tenants = $this->tenantContext->getAccessibleTenants();
            if ($tenants === []) {
                return [];
            }
            $queryBuilder->andWhere('u.tenant IN (:tenants)')->setParameter('tenants', $tenants);
        }

        $exempt = [];
        // The enabled providers of each organisation are loaded once per page view,
        // not twice per account (both subscribers would query them per user).
        $providersByTenant = [];
        /** @var User $user */
        foreach ($queryBuilder->getQuery()->getResult() as $user) {
            $tenant = $user->getTenant();
            $key = $tenant?->getId() ?? 0;
            $providersByTenant[$key] ??= $this->providerRepository->findEnabledForTenant($tenant);
            if ($this->isExempt($user, $providersByTenant[$key])
                && $this->authorizationChecker->isGranted(UserVoter::VIEW, $user)
            ) {
                $exempt[] = $user;
            }
        }

        return $exempt;
    }

    /**
     * Enforced (by tenant flag or domain) but neither subscriber redirects —
     * both run on the same POST, the first redirect wins.
     *
     * @param list<IdentityProvider>|null $enabledProviders the user's organisation's enabled providers, if already loaded
     */
    public function isExempt(User $user, ?array $enabledProviders = null): bool
    {
        $byTenant = $this->tenantEnforcement->enforcementTarget($user, $enabledProviders);
        $byDomain = $this->domainEnforcement->enforcementTarget($user, (string) $user->getEmail(), $enabledProviders);

        return ($byTenant === false || $byDomain === false)
            && !$byTenant instanceof IdentityProvider
            && !$byDomain instanceof IdentityProvider;
    }

    private function isAnyEnforcementConfigured(): bool
    {
        $enforcedTenants = (int) $this->entityManager->getRepository(Tenant::class)->count(['ssoEnforced' => true]);
        $enforcingProviders = (int) $this->entityManager->getRepository(IdentityProvider::class)->count([
            'domainBindingMode' => IdentityProvider::DOMAIN_MODE_ENFORCE,
            'enabled' => true,
        ]);

        return $enforcedTenants > 0 || $enforcingProviders > 0;
    }
}
