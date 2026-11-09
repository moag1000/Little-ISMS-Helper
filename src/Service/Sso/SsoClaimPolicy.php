<?php

declare(strict_types=1);

namespace App\Service\Sso;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * The one rule set for what an SSO login has to prove before its claims are
 * used (S9). Every SSO authenticator — env Azure OAuth, env Azure SAML and
 * the IdentityProvider records (generic OIDC / OAuth2) — goes through it;
 * `tests/Security/Sso/SsoClaimContractTest` checks that per authenticator and
 * Gate 93 (`check_sso_claim_policy.py`) that no authenticator skips it.
 *
 *  - ID token: issuer configured and equal, `exp` present and not past,
 *    `iat`/`nbf` not in the future, audience contains the client id (`azp`
 *    when several audiences), `nonce` present and equal to the one sent.
 *  - UserInfo may add claims but never change the subject, and never
 *    overrides a claim the signed ID token already carries.
 *  - Azure (env): the directory (`tid`) must be one the operator configured —
 *    a multi-tenant app registration would otherwise let any Entra directory
 *    sign in with a freely set `mail`.
 *  - Link an existing account by e-mail only with a verified e-mail
 *    (`email_verified`, Entra `xms_edov`); new accounts are unaffected.
 */
final class SsoClaimPolicy
{
    /** Tolerated clock difference between IdP and this server, in seconds. */
    public const int CLOCK_SKEW = 60;

    /** Entra placeholders that do not name one directory. */
    private const array AZURE_MULTI_TENANT_ALIASES = ['', 'common', 'organizations', 'consumers'];

    public const string AZURE_TENANT_REFUSED_MESSAGE = 'This Microsoft directory is not allowed to sign in here.';

    private function __construct()
    {
    }

    /**
     * Claims of a signature-verified OIDC ID token.
     *
     * @param array<string, mixed> $payload
     * @throws AuthenticationException
     */
    public static function assertIdToken(
        array $payload,
        ?string $expectedIssuer,
        string $clientId,
        ?string $expectedNonce,
        ?int $now = null,
    ): void {
        $now ??= time();

        if ($expectedIssuer === null || $expectedIssuer === '') {
            throw new AuthenticationException('SSO provider has no issuer configured.');
        }
        if (!is_string($payload['iss'] ?? null) || $payload['iss'] !== $expectedIssuer) {
            throw new AuthenticationException('ID-Token issuer mismatch.');
        }

        if (!is_int($payload['exp'] ?? null) && !is_float($payload['exp'] ?? null)) {
            throw new AuthenticationException('ID-Token has no expiry (exp).');
        }
        if ((int) $payload['exp'] + self::CLOCK_SKEW < $now) {
            throw new AuthenticationException('ID-Token has expired.');
        }
        foreach (['iat', 'nbf'] as $claim) {
            if (isset($payload[$claim]) && is_numeric($payload[$claim]) && (int) $payload[$claim] - self::CLOCK_SKEW > $now) {
                throw new AuthenticationException(sprintf('ID-Token %s is in the future.', $claim));
            }
        }

        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if ($clientId === '' || !in_array($clientId, $audiences, true)) {
            throw new AuthenticationException('ID-Token audience does not include client_id.');
        }
        if (count($audiences) > 1 && isset($payload['azp']) && $payload['azp'] !== $clientId) {
            throw new AuthenticationException('ID-Token authorized party (azp) mismatch.');
        }

        $nonce = $payload['nonce'] ?? null;
        if ($expectedNonce === null || $expectedNonce === '' || !is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new AuthenticationException('ID-Token nonce mismatch.');
        }

        if (!is_string($payload['sub'] ?? null) || $payload['sub'] === '') {
            throw new AuthenticationException('ID-Token has no subject (sub).');
        }
    }

    /**
     * Claims of the ID token plus UserInfo. UserInfo is fetched with the
     * access token and is not signed: it may add claims, but the subject must
     * be the ID token's (OIDC Core 5.3.2) and every claim the ID token has
     * stays as signed.
     *
     * @param array<string, mixed> $idTokenClaims empty for plain OAuth2 providers
     * @param array<string, mixed> $userInfo
     * @return array<string, mixed>
     * @throws AuthenticationException
     */
    public static function mergeUserInfo(array $idTokenClaims, array $userInfo): array
    {
        if ($idTokenClaims === []) {
            return $userInfo;
        }
        if (array_key_exists('sub', $userInfo) && (string) $userInfo['sub'] !== (string) ($idTokenClaims['sub'] ?? '')) {
            throw new AuthenticationException('UserInfo subject does not match the ID-Token.');
        }

        return $idTokenClaims + $userInfo;
    }

    /**
     * Has the IdP vouched for the e-mail address? `email_verified` (OIDC) or
     * `xms_edov` (Entra: e-mail domain verified by the directory).
     *
     * The flag vouches for the `email` claim only. When the account is
     * matched through another (mapped) claim, pass that address as
     * $matchedEmail: it counts as verified only when it is the same address
     * as `email` (S14) — a verified `email` must not vouch for an unverified
     * `upn` or custom attribute.
     *
     * @param array<string, mixed> $claims
     */
    public static function isEmailVerified(array $claims, ?string $matchedEmail = null): bool
    {
        if ($matchedEmail !== null
            && strcasecmp(trim($matchedEmail), trim(is_scalar($claims['email'] ?? null) ? (string) $claims['email'] : '')) !== 0) {
            return false;
        }
        foreach (['email_verified', 'xms_edov'] as $claim) {
            $value = $claims[$claim] ?? null;
            if ($value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Linking an existing local account by e-mail needs an e-mail the IdP
     * verified — otherwise whoever controls a directory entry's `mail` takes
     * over the account with that address.
     *
     * @param array<string, mixed> $claims
     * @throws CustomUserMessageAuthenticationException
     */
    public static function assertEmailVerifiedForLinking(array $claims, ?string $matchedEmail = null): void
    {
        if (!self::isEmailVerified($claims, $matchedEmail)) {
            throw new CustomUserMessageAuthenticationException(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE);
        }
    }

    /**
     * Entra directories allowed to sign in through the env-configured Azure
     * logins: AZURE_ALLOWED_TENANT_IDS (comma-separated) if set, otherwise
     * AZURE_TENANT_ID when it names one directory. "common" /
     * "organizations" / empty allow none — fail closed.
     *
     * @return list<string> lower-cased directory ids
     */
    public static function allowedAzureTenants(?string $configuredTenant, ?string $allowList): array
    {
        $ids = [];
        foreach (explode(',', (string) $allowList) as $id) {
            $id = strtolower(trim($id));
            if (!in_array($id, self::AZURE_MULTI_TENANT_ALIASES, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            $tenant = strtolower(trim((string) $configuredTenant));
            if (!in_array($tenant, self::AZURE_MULTI_TENANT_ALIASES, true)) {
                $ids[] = $tenant;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $allowedTenants from {@see allowedAzureTenants()}
     * @throws CustomUserMessageAuthenticationException
     */
    public static function assertAzureTenant(mixed $tid, array $allowedTenants): void
    {
        if (!is_string($tid) || $tid === '' || !in_array(strtolower($tid), $allowedTenants, true)) {
            throw new CustomUserMessageAuthenticationException(self::AZURE_TENANT_REFUSED_MESSAGE);
        }
    }
}
