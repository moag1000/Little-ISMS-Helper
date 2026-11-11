<?php

declare(strict_types=1);

namespace App\Security\GateFixture;

use App\Service\Sso\SsoAccountLinkPolicy;
use App\Service\Sso\SsoClaimPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;

/**
 * Gate 93 fixture: the test runs the rule with a claim map for these classes. Every `expect:` line is a mutation
 * the rule must report.
 */
abstract class FixtureAuthenticator extends AbstractAuthenticator
{
}

/** Checks the directory itself. */
final class GoodAuthenticator extends FixtureAuthenticator
{
    public function check(mixed $tid, ?string $configured): void
    {
        SsoClaimPolicy::assertAzureTenant($tid, SsoClaimPolicy::allowedAzureTenants($configured, null));
    }
}

/**
 * T3: AzureOAuthAuthenticator with every policy call removed stayed green, because the policy's name stood in a
 * docblock {@see SsoClaimPolicy::assertAzureTenant()} of an imported class.
 */
final class CommentOnlyAuthenticator extends FixtureAuthenticator // expect: gate93.policyNotCalled, gate93.policyNotCalled
{
    public function check(mixed $tid): void
    {
        // SsoClaimPolicy::assertAzureTenant($tid, SsoClaimPolicy::allowedAzureTenants(null, null));
        SsoAccountLinkPolicy::class;
    }
}

final class RogueAuthenticator extends FixtureAuthenticator // expect: gate93.unknownAuthenticator
{
}

final class FixtureFlow
{
    public function handleCallback(): void
    {
        SsoClaimPolicy::mergeUserInfo([], []);
    }
}

final class FlowAuthenticator extends FixtureAuthenticator
{
    public function __construct(private readonly FixtureFlow $flow)
    {
    }

    public function run(): void
    {
        $this->flow->handleCallback();
    }
}

final class FlowSkippedAuthenticator extends FixtureAuthenticator // expect: gate93.flowNotCalled
{
    public function __construct(private readonly FixtureFlow $flow)
    {
    }

    public function run(): FixtureFlow
    {
        return $this->flow;
    }
}

final class SamlEndpoint
{
    public function __construct(private bool $allowEmailLinking = TRUE) // expect: gate93.emailLinkingDefault
    {
    }

    public function acs(object $auth, Request $request, ?string $requestId): string
    {
        $auth->processResponse(); // expect: gate93.processResponse
        $auth->processResponse(null); // expect: gate93.processResponse
        $auth->processResponse($requestId);

        return $request->getScheme() . '://' . $request->getHttpHost() // expect: gate93.hostHeader
            . $request->getSchemeAndHttpHost(); // expect: gate93.hostHeader
    }

    /** T4 S3: the Host header by other ways. */
    public function otherHosts(Request $request, \Symfony\Component\Routing\Generator\UrlGeneratorInterface $router): string
    {
        return $_SERVER['HTTP_HOST'] // expect: gate93.hostHeader
            . $request->headers->get('Host') // expect: gate93.hostHeader
            . $request->getBaseUrl() // expect: gate93.hostHeader
            . $router->generate('sso_acs', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL) // expect: gate93.hostHeader
            . $router->generate('sso_acs')
            . $request->headers->get('Accept');
    }

    /** T4 S5: a request id that is only ever null. */
    public function nullThroughVariable(object $auth): void
    {
        $nothing = null;
        $auth->processResponse($nothing); // expect: gate93.processResponse
    }
}

/** T4 S4: a renamed linking switch. */
final class OidcLinkSettings
{
    public function __construct(
        public bool $linkByEmail = true, // expect: gate93.emailLinkingDefault
        public bool $autoLink = false,
    ) {
    }
}

/** T4 S1: the bool check stands alone, its result decides nothing. */
final class BareCheckAuthenticator extends FixtureAuthenticator // expect: gate93.policyNotCalled
{
    /** @param array<string, mixed> $claims */
    public function check(array $claims): void
    {
        SsoClaimPolicy::isEmailVerified($claims);
    }
}

/** T4 S1: the check sits in dead code. */
final class DeadCheckAuthenticator extends FixtureAuthenticator // expect: gate93.policyNotCalled
{
    /** @param array<string, mixed> $claims */
    public function check(array $claims): bool
    {
        if (false) {
            return SsoClaimPolicy::isEmailVerified($claims);
        }

        return false;
    }
}

final class UsedCheckAuthenticator extends FixtureAuthenticator
{
    /** @param array<string, mixed> $claims */
    public function check(array $claims): bool
    {
        if (!SsoClaimPolicy::isEmailVerified($claims)) {
            return false;
        }

        return true;
    }
}

/** T4 S2: an authenticator (outside App\Service\Sso) merges ID token and UserInfo by hand. */
final class MergingAuthenticator extends FixtureAuthenticator
{
    /**
     * @param array<string, mixed> $idToken
     * @param array<string, mixed> $userInfo
     * @return array<string, mixed>
     */
    public function claims(array $idToken, array $userInfo): array
    {
        return array_merge($idToken, $userInfo); // expect: gate93.claimMerge
    }
}
