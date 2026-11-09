<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\SsoUserApproval;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Stateless engine for OIDC code-flow authentication.
 *
 * Issues authorize URLs with PKCE+state, exchanges code for tokens,
 * verifies ID-Tokens against the IdP's JWKS, and resolves a local User
 * (or queues an approval row when JIT-with-approval is configured).
 */
final class OidcAuthenticationFlow
{
    public function __construct(
        private readonly OidcDiscoveryService $discovery,
        private readonly SsoSecretEncryption $secrets,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly LoggerInterface $logger,
        private readonly ClaimToRoleResolver $roleResolver,
        private readonly SsoEventLogger $eventLogger,
        // Token and UserInfo calls go to provider-configured URLs: always
        // through the SSRF-guarded client, never a bare Guzzle (S9).
        private readonly \App\Http\UserUrlHttpClient $userUrlClient,
        private readonly ?\App\Repository\TenantRepository $tenantRepository = null,
    ) {
    }

    /**
     * Apply the provider's claim→role mapping to the user. Only an explicitly
     * MATCHED mapping rule changes a role — when no rule fires the roles are
     * left untouched (unmapped users are never silently demoted).
     *
     * The provider maintains only the roles it can hand out itself (S14:
     * roles of its mappings, default and fallback role,
     * {@see ClaimToRoleResolver::managedRoles()}); every other role of the
     * account — top management, a persona role, a holding role granted in
     * the user administration — survives each login. Privileged roles never
     * come from claims ({@see SsoAccountLinkPolicy::mayAssignRole()}). A
     * changed role on an existing user emits the sso.role.changed HMAC audit
     * event.
     *
     * @param array<string,mixed> $claims
     */
    private function applyRoleMapping(User $user, IdentityProvider $provider, array $claims, bool $isNew): void
    {
        $result = $this->roleResolver->resolve($provider, $claims);
        if (!$result->matched) {
            return;
        }
        if (!SsoAccountLinkPolicy::mayAssignRole($provider, $result->role)) {
            $this->logger->warning('SSO: mapped role ignored (privileged or unknown)', [
                'provider' => $provider->getSlug(),
                'role' => $result->role,
            ]);
            return;
        }

        $managed = array_values(array_unique([...$this->roleResolver->managedRoles($provider), 'ROLE_USER']));
        $stored = $user->getStoredRoles();
        $oldRole = array_values(array_intersect($stored, $managed))[0] ?? 'ROLE_USER';
        $newStored = array_values(array_unique([...array_diff($stored, $managed), $result->role]));

        $sortedOld = $stored;
        $sortedNew = $newStored;
        sort($sortedOld);
        sort($sortedNew);
        if ($sortedOld === $sortedNew) {
            return;
        }

        $user->setRoles($newStored);

        if (!$isNew && $oldRole !== $result->role) {
            $this->eventLogger->logRoleChanged($provider, $user, $oldRole, $result->role);
        }
    }

    /**
     * Caller must persist state, codeVerifier and nonce in the session; the
     * callback checks state and hands codeVerifier + nonce to handleCallback().
     *
     * @return array{url: string, state: string, codeVerifier: string, nonce: string}
     */
    public function buildAuthorizationRequest(IdentityProvider $provider, string $redirectUri): array
    {
        $client = $this->buildClient($provider, $redirectUri);

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = $this->randomUrlSafe(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $client->getAuthorizationUrl([
            'state' => $state,
            'scope' => implode(' ', $provider->getScopes() ?: ['openid', 'profile', 'email']),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ]);

        return ['url' => $url, 'state' => $state, 'codeVerifier' => $verifier, 'nonce' => $nonce];
    }

    /**
     * Exchange code → tokens, verify ID-Token, map claims.
     *
     * Returns:
     *   - User           : an existing or auto-approved local user, ready to log in.
     *   - SsoUserApproval: a queued approval (caller must inform user; do NOT log them in).
     */
    public function handleCallback(
        IdentityProvider $provider,
        string $redirectUri,
        string $code,
        string $codeVerifier,
        string $nonce,
    ): User|SsoUserApproval {
        $client = $this->buildClient($provider, $redirectUri);

        try {
            $token = $client->getAccessToken('authorization_code', [
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SSO token exchange failed', ['provider' => $provider->getSlug(), 'error' => $e->getMessage()]);
            throw new AuthenticationException('Token exchange failed: ' . $e->getMessage(), 0, $e);
        }

        $claims = $this->extractClaims($provider, $client, $token, $nonce);

        return $this->resolveUserFromClaims($provider, $claims);
    }

    /**
     * Resolve the verified claims to a local user (existing binding, link by
     * email, JIT) or a queued approval.
     *
     * @param array<string,mixed> $claims
     */
    private function resolveUserFromClaims(IdentityProvider $provider, array $claims): User|SsoUserApproval
    {
        $email = $this->mapClaimToField($claims, $provider->getAttributeMap(), 'email');
        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthenticationException('IdP returned no usable email claim.');
        }
        $externalId = (string) ($claims['sub'] ?? '');
        if ($externalId === '') {
            throw new AuthenticationException('IdP returned no subject (sub) claim.');
        }

        // 1) Existing SSO user (provider+sub binding)
        $existing = $this->userRepo->findOneBy(['ssoProvider' => $provider, 'ssoExternalId' => $externalId]);
        if ($existing instanceof User) {
            $this->assertMayAuthenticateAs($provider, $existing);
            $this->updateProfile($existing, $provider, $claims);
            $this->applyRoleMapping($existing, $provider, $claims, false);
            $this->em->flush();
            $this->eventLogger->logLoginSuccess($provider, $email);
            return $existing;
        }

        // 2) Existing local user with matching email — link account
        $byEmail = $this->userRepo->findOneBy(['email' => $email]);
        if ($byEmail instanceof User) {
            // Never link a tenant-owned provider to an account outside its
            // tenant tree or to a privileged account (account takeover).
            $this->assertMayAuthenticateAs($provider, $byEmail);
            if (SsoAccountLinkPolicy::isSubjectChange($provider, $byEmail, $externalId)) {
                // Security event: same provider, new subject id for a linked account.
                $this->eventLogger->logLoginFailure($provider, $email, 'subject_changed');
            }
            SsoAccountLinkPolicy::assertMayLinkByEmail($provider, $byEmail, $this->logger, $externalId);
            // Only an e-mail the IdP verified links an existing account (S9).
            if (!SsoClaimPolicy::isEmailVerified($claims, $email)) {
                $this->logger->warning('SSO: login refused, e-mail not verified by the IdP, no link by e-mail', [
                    'provider' => $provider->getSlug(),
                    'target_user_id' => $byEmail->getId(),
                ]);
                $this->eventLogger->logLoginFailure($provider, $email, 'email_not_verified');
                SsoClaimPolicy::assertEmailVerifiedForLinking($claims, $email);
            }
            $byEmail->setSsoProvider($provider);
            $byEmail->setSsoExternalId($externalId);
            $byEmail->setAuthProvider('sso:' . $provider->getSlug());
            $this->updateProfile($byEmail, $provider, $claims);
            $this->applyRoleMapping($byEmail, $provider, $claims, false);
            $this->em->flush();
            $this->eventLogger->logLoginSuccess($provider, $email);
            return $byEmail;
        }

        // 3) JIT provisioning disabled → reject
        if (!$provider->isJitProvisioning()) {
            throw new AuthenticationException(sprintf('JIT provisioning disabled for provider "%s".', $provider->getSlug()));
        }

        // 4) JIT provisioning auto-approve → create user immediately
        if ($provider->isAutoApprove()) {
            $user = $this->createUser($provider, $email, $externalId, $claims);
            $this->em->persist($user);
            $this->em->flush();
            $this->eventLogger->logLoginSuccess($provider, $email);
            return $user;
        }

        // 5) JIT with approval → queue
        $approvalRepo = $this->em->getRepository(SsoUserApproval::class);
        $existingPending = $approvalRepo->findOneBy([
            'provider' => $provider,
            'email' => $email,
            'status' => SsoUserApproval::STATUS_PENDING,
        ]);
        if ($existingPending instanceof SsoUserApproval) {
            return $existingPending;
        }

        $approval = (new SsoUserApproval())
            ->setProvider($provider)
            ->setTenant($provider->getTenant())
            ->setEmail($email)
            ->setExternalId($externalId)
            ->setClaims($claims);
        $this->em->persist($approval);
        $this->em->flush();

        return $approval;
    }

    /**
     * Build user from an approved approval row.
     */
    public function provisionFromApproval(SsoUserApproval $approval): User
    {
        $provider = $approval->getProvider();
        if (!$provider instanceof IdentityProvider) {
                    // @intentional-assertion: SSO approval invariant — Doctrine mapping ensures provider is set before flow runs
throw new \App\Exception\BusinessRule\BusinessRuleException('Approval has no provider attached.', 'no_provider');
        }
        $user = $this->createUser($provider, (string) $approval->getEmail(), (string) $approval->getExternalId(), $approval->getClaims());
        $this->em->persist($user);

        return $user;
    }

    private function buildClient(IdentityProvider $provider, string $redirectUri): GenericProvider
    {
        $secret = $this->secrets->decrypt($provider->getClientSecretEncrypted()) ?? '';
        $authUrl = $provider->getAuthorizationEndpoint();
        $tokenUrl = $provider->getTokenEndpoint();
        $userinfoUrl = $provider->getUserinfoEndpoint();

        if ($authUrl === null || $tokenUrl === null) {
            // Lazy-discover if missing.
            $this->discovery->applyDiscoveryToProvider($provider);
            $authUrl = $provider->getAuthorizationEndpoint();
            $tokenUrl = $provider->getTokenEndpoint();
            $userinfoUrl = $provider->getUserinfoEndpoint();
            $this->em->flush();
        }
        if ($authUrl === null || $tokenUrl === null) {
            throw new \App\Exception\Io\IoException('Provider endpoints could not be resolved (configure discoveryUrl or endpoints).');
        }

        return new GenericProvider([
            'clientId' => $provider->getClientId(),
            'clientSecret' => $secret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $authUrl,
            'urlAccessToken' => $tokenUrl,
            'urlResourceOwnerDetails' => $userinfoUrl ?? $tokenUrl,
            'scopes' => implode(' ', $provider->getScopes() ?: ['openid', 'profile', 'email']),
        ], ['httpClient' => $this->userUrlClient->guzzle()]);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractClaims(IdentityProvider $provider, GenericProvider $client, AccessTokenInterface $token, string $nonce): array
    {
        $claims = [];
        $values = $token->getValues();
        if ($provider->getType() === IdentityProvider::TYPE_OIDC) {
            // An OIDC provider must answer with a signed ID token; UserInfo
            // alone (unsigned, fetched with the access token) is not enough.
            if (!isset($values['id_token']) || !is_string($values['id_token'])) {
                throw new AuthenticationException('IdP returned no ID-Token.');
            }
            $claims = $this->verifyIdToken($provider, $values['id_token'], $nonce);
        }

        // getResourceOwner() is typed to the concrete AccessToken; the
        // interface returned by getAccessToken() is narrowed here.
        if ($provider->getUserinfoEndpoint() !== null && $token instanceof AccessToken) {
            try {
                $userInfo = $client->getResourceOwner($token)->toArray();
            } catch (\Throwable $e) {
                $userInfo = [];
                $this->logger->warning('UserInfo fetch failed', ['provider' => $provider->getSlug(), 'error' => $e->getMessage()]);
            }
            // UserInfo adds claims, never changes the subject or a signed claim.
            $claims = SsoClaimPolicy::mergeUserInfo($claims, $userInfo);
        }

        if ($claims === []) {
            throw new AuthenticationException('IdP returned no claims (no ID-Token, no UserInfo).');
        }

        return $claims;
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyIdToken(IdentityProvider $provider, string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new AuthenticationException('ID-Token is not a compact JWS.');
        }
        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($headerJson === false || $payloadJson === false) {
            throw new AuthenticationException('ID-Token has malformed base64.');
        }
        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            throw new AuthenticationException('ID-Token has malformed JSON.');
        }

        $alg = $header['alg'] ?? '';
        if (!in_array($alg, ['RS256', 'RS384', 'RS512', 'PS256', 'ES256'], true)) {
            throw new AuthenticationException('Unsupported ID-Token signing algorithm: ' . $alg);
        }

        $jwks = $this->discovery->fetchJwks($provider);
        $jwkSet = $this->buildJwkSet($jwks);
        $kid = $header['kid'] ?? null;
        if (is_string($kid) && !$this->jwkSetHasKid($jwkSet, $kid)) {
            $jwks = $this->discovery->fetchJwks($provider, true);
            $jwkSet = $this->buildJwkSet($jwks);
        }

        $manager = new AlgorithmManager([new RS256(), new RS384(), new RS512(), new PS256(), new ES256()]);
        $verifier = new JWSVerifier($manager);
        $serializer = new CompactSerializer();
        $jws = $serializer->unserialize($jwt);

        $verified = false;
        foreach ($jwkSet->all() as $jwk) {
            if ($verifier->verifyWithKey($jws, $jwk, 0)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new AuthenticationException('ID-Token signature verification failed.');
        }

        // No issuer configured: take it from discovery once; still none → refused.
        if (($provider->getIssuer() ?? '') === '' && ($provider->getDiscoveryUrl() ?? '') !== '') {
            try {
                $this->discovery->applyDiscoveryToProvider($provider);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->warning('SSO: issuer discovery failed', ['provider' => $provider->getSlug(), 'error' => $e->getMessage()]);
            }
        }
        SsoClaimPolicy::assertIdToken($payload, $provider->getIssuer(), (string) $provider->getClientId(), $nonce);

        return $payload;
    }

    private function buildJwkSet(array $jwks): JWKSet
    {
        $keys = [];
        foreach ($jwks['keys'] ?? [] as $k) {
            if (is_array($k)) {
                try {
                    $keys[] = new JWK($k);
                } catch (InvalidArgumentException) {
                    // skip malformed key
                }
            }
        }
        return new JWKSet($keys);
    }

    private function jwkSetHasKid(JWKSet $set, string $kid): bool
    {
        foreach ($set->all() as $key) {
            if ($key->has('kid') && $key->get('kid') === $kid) {
                return true;
            }
        }
        return false;
    }

    private function createUser(IdentityProvider $provider, string $email, string $externalId, array $claims): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setSsoProvider($provider);
        $user->setSsoExternalId($externalId);
        $user->setAuthProvider('sso:' . $provider->getSlug());
        $user->setIsVerified(true);
        $user->setIsActive(true);
        $defaultRole = $provider->getDefaultRole() ?: 'ROLE_USER';
        $user->setRoles([SsoAccountLinkPolicy::mayAssignRole($provider, $defaultRole) ? $defaultRole : 'ROLE_USER']);
        $user->setCreatedAt(new DateTimeImmutable());
        // A global provider has no organisation: on a single-organisation installation
        // the account belongs to the sole one (otherwise it sees no organisation's data).
        $tenant = $provider->getTenant() ?? $this->tenantRepository?->findSoleTenant();
        if ($tenant !== null) {
            $user->setTenant($tenant);
        }
        $this->updateProfile($user, $provider, $claims);
        // Claim→role mapping overrides the default role when a mapping rule fires.
        $this->applyRoleMapping($user, $provider, $claims, true);

        return $user;
    }

    /**
     * Rejects the login (no linking, no profile/role update, no flush) when
     * the provider may not act for this local account.
     */
    private function assertMayAuthenticateAs(IdentityProvider $provider, User $target): void
    {
        if (SsoAccountLinkPolicy::mayAuthenticateAs($provider, $target)) {
            return;
        }
        $this->logger->warning('SSO: login rejected, provider may not act for this account', [
            'provider' => $provider->getSlug(),
            'target_user_id' => $target->getId(),
        ]);

        throw new AuthenticationException('SSO login is not permitted for this account.');
    }

    private function updateProfile(User $user, IdentityProvider $provider, array $claims): void
    {
        $map = $provider->getAttributeMap();
        $first = $this->mapClaimToField($claims, $map, 'firstName');
        $last = $this->mapClaimToField($claims, $map, 'lastName');
        if ($first !== null && $first !== '') {
            $user->setFirstName($first);
        } elseif ($user->getFirstName() === null || $user->getFirstName() === '') {
            $user->setFirstName('');
        }
        if ($last !== null && $last !== '') {
            $user->setLastName($last);
        } elseif ($user->getLastName() === null || $user->getLastName() === '') {
            $user->setLastName('');
        }

        $jobTitle = $this->mapClaimToField($claims, $map, 'jobTitle');
        if ($jobTitle !== null) {
            $user->setJobTitle($jobTitle);
        }
        $department = $this->mapClaimToField($claims, $map, 'department');
        if ($department !== null) {
            $user->setDepartment($department);
        }

        $user->setLastLoginAt(new DateTimeImmutable());
        $user->setUpdatedAt(new DateTimeImmutable());
    }

    /**
     * Resolve claim → user field. Map is { claimKey: userField }.
     * We invert to find the claim corresponding to the desired field.
     */
    private function mapClaimToField(array $claims, array $map, string $field): ?string
    {
        foreach ($map as $claimKey => $userField) {
            if ($userField === $field && isset($claims[$claimKey]) && is_scalar($claims[$claimKey])) {
                return (string) $claims[$claimKey];
            }
        }

        return null;
    }

    private function randomUrlSafe(int $length): string
    {
        $bytes = random_bytes($length);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
