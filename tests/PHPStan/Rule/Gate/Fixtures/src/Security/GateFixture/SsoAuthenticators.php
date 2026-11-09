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
}
