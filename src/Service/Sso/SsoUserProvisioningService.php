<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Fte\FteRecorderService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * JIT (Just-In-Time) user provisioning for SSO logins.
 *
 * Wave 2: ClaimToRoleResolver is consulted first to apply IdentityProviderRoleMapping rules.
 * Falls back to defaultFallbackRole when no mapping matches.
 * Emits ACTION_SSO_JIT_PROVISIONED via SsoEventLogger on new user creation.
 */
final class SsoUserProvisioningService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ClaimToRoleResolver $claimResolver,
        private readonly SsoEventLogger $ssoEventLogger,
        private readonly FteRecorderService $fteRecorder,
    ) {
    }

    /**
     * Resolve or create a User for the given IdP + claims.
     *
     * Returns the User if login should proceed, or null if provisioning
     * was queued for approval (handled by OidcAuthenticationFlow for approval queue).
     *
     * @param array<string,mixed> $claims
     */
    public function provision(IdentityProvider $provider, array $claims): User
    {
        $email = $this->resolveClaim($claims, $provider->getAttributeMap(), 'email');
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthenticationException('JIT provisioning: IdP returned no usable email claim.');
        }
        $externalId = (string) ($claims['sub'] ?? '');
        if ($externalId === '') {
            throw new AuthenticationException('JIT provisioning: IdP returned no subject (sub) claim.');
        }

        // 1) Already linked by provider+sub
        $existing = $this->userRepo->findOneBy(['ssoProvider' => $provider, 'ssoExternalId' => $externalId]);
        if ($existing instanceof User) {
            $this->syncProfile($existing, $provider, $claims);
            $this->em->flush();
            $this->logger->debug('SSO JIT: returned existing linked user', ['email' => $email]);
            return $existing;
        }

        // 2) Link by email
        $byEmail = $this->userRepo->findOneBy(['email' => $email]);
        if ($byEmail instanceof User) {
            $byEmail->setSsoProvider($provider);
            $byEmail->setSsoExternalId($externalId);
            $byEmail->setAuthProvider('sso:' . $provider->getSlug());
            $this->syncProfile($byEmail, $provider, $claims);
            $this->em->flush();
            $this->logger->info('SSO JIT: linked existing user by email', ['email' => $email]);
            return $byEmail;
        }

        // 3) Create new user via JIT
        if (!$provider->isJitProvisioning()) {
            throw new AuthenticationException(sprintf('JIT provisioning disabled for IdP "%s".', $provider->getSlug()));
        }

        $user = $this->createUser($provider, $email, $externalId, $claims);
        $this->em->persist($user);
        $this->em->flush();

        $this->ssoEventLogger->logJitProvisioned($provider, $user, $email);
        $this->fteRecorder->recordSsoJit($user); // F11 FTE-Tracking
        $this->logger->info('SSO JIT: provisioned new user', ['email' => $email, 'provider' => $provider->getSlug()]);

        return $user;
    }

    private function createUser(IdentityProvider $provider, string $email, string $externalId, array $claims): User
    {
        $resolverResult = $this->claimResolver->resolve($provider, $claims);
        $role           = $resolverResult->role;

        $user = new User();
        $user->setEmail($email);
        $user->setSsoProvider($provider);
        $user->setSsoExternalId($externalId);
        $user->setAuthProvider('sso:' . $provider->getSlug());
        $user->setIsVerified(true);
        $user->setIsActive(true);
        $user->setRoles([$role]);
        $user->setCreatedAt(new DateTimeImmutable());
        if ($provider->getTenant() !== null) {
            $user->setTenant($provider->getTenant());
        }
        $this->syncProfile($user, $provider, $claims);

        return $user;
    }

    private function syncProfile(User $user, IdentityProvider $provider, array $claims): void
    {
        $map = $provider->getAttributeMap();
        $first = $this->resolveClaim($claims, $map, 'firstName');
        $last = $this->resolveClaim($claims, $map, 'lastName');
        if ($first !== null && $first !== '') {
            $user->setFirstName($first);
        }
        if ($last !== null && $last !== '') {
            $user->setLastName($last);
        }
        $jobTitle = $this->resolveClaim($claims, $map, 'jobTitle');
        if ($jobTitle !== null) {
            $user->setJobTitle($jobTitle);
        }
        $department = $this->resolveClaim($claims, $map, 'department');
        if ($department !== null) {
            $user->setDepartment($department);
        }
        $user->setLastLoginAt(new DateTimeImmutable());
        $user->setUpdatedAt(new DateTimeImmutable());
    }

    /**
     * Resolve claim → user field using attribute map (inverted).
     * Map format: { claimKey: userField }
     */
    private function resolveClaim(array $claims, array $map, string $field): ?string
    {
        foreach ($map as $claimKey => $userField) {
            if ($userField === $field && isset($claims[$claimKey]) && is_scalar($claims[$claimKey])) {
                return (string) $claims[$claimKey];
            }
        }
        return null;
    }
}
