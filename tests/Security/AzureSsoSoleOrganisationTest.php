<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Security\AzureOAuthAuthenticator;
use App\Security\AzureSamlAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use TheNetworg\OAuth2\Client\Token\AccessToken;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

/**
 * Accounts created on first Azure login (OAuth + SAML) must belong to the
 * sole organisation of a single-organisation installation — exactly like the
 * generic OIDC flow and SsoUserProvisioningService. Without an organisation
 * the tenant filter shows the new account no data at all.
 */
final class AzureSsoSoleOrganisationTest extends TestCase
{
    #[Test]
    public function oauthFirstLoginAssignsTheSoleOrganisation(): void
    {
        $sole = new Tenant();
        $user = $this->oauthLogin($this->tenantRepository($sole), null);

        self::assertSame($sole, $user->getTenant());
    }

    #[Test]
    public function oauthFirstLoginLeavesTenantEmptyOnMultiOrganisationInstalls(): void
    {
        $user = $this->oauthLogin($this->tenantRepository(null), null);

        self::assertNull($user->getTenant());
    }

    #[Test]
    public function oauthLoginDoesNotReassignAnExistingAccount(): void
    {
        $own = new Tenant();
        $existing = (new User())->setEmail('jane@example.com');
        $existing->setTenant($own);

        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::never())->method('findSoleTenant');

        $user = $this->oauthLogin($tenants, $existing);

        self::assertSame($own, $user->getTenant());
    }

    #[Test]
    public function samlFirstLoginAssignsTheSoleOrganisation(): void
    {
        $sole = new Tenant();
        $user = $this->samlLogin($this->tenantRepository($sole));

        self::assertSame($sole, $user->getTenant());
    }

    #[Test]
    public function samlFirstLoginLeavesTenantEmptyOnMultiOrganisationInstalls(): void
    {
        $user = $this->samlLogin($this->tenantRepository(null));

        self::assertNull($user->getTenant());
    }

    private function tenantRepository(?Tenant $sole): TenantRepository
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findSoleTenant')->willReturn($sole);

        return $tenants;
    }

    private function users(?User $existing): UserRepository
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByAzureObjectId')->willReturn($existing);
        $users->method('findOneBy')->willReturn($existing);

        return $users;
    }

    private function oauthLogin(TenantRepository $tenants, ?User $existing): User
    {
        $token = new AccessToken(['access_token' => 'token-123'], $this->createStub(Azure::class));
        $client = $this->createStub(OAuth2ClientInterface::class);
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new AzureResourceOwner([
            'oid' => 'object-1',
            'tid' => 'azure-tenant-1',
            'email' => 'jane@example.com',
            'given_name' => 'Jane',
            'family_name' => 'Doe',
        ], $token));

        $registry = $this->createStub(ClientRegistry::class);
        $registry->method('getClient')->willReturn($client);

        $authenticator = new AzureOAuthAuthenticator(
            $registry,
            $this->createStub(EntityManagerInterface::class),
            $this->users($existing),
            $this->createStub(RouterInterface::class),
            $tenants,
        );

        $user = $authenticator->authenticate(new Request())->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function samlLogin(TenantRepository $tenants): User
    {
        $auth = $this->createStub(Auth::class);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('getNameId')->willReturn('jane@example.com');
        $auth->method('getAttributes')->willReturn([
            'http://schemas.microsoft.com/identity/claims/objectidentifier' => ['object-1'],
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname' => ['Jane'],
        ]);

        $authenticator = new AzureSamlAuthenticator(
            $this->createStub(EntityManagerInterface::class),
            $this->users(null),
            $this->createStub(RouterInterface::class),
            $tenants,
        );
        // The OneLogin Auth is built from env config on first use; inject a stub instead.
        (new \ReflectionProperty(AzureSamlAuthenticator::class, 'samlAuth'))->setValue($authenticator, $auth);

        $user = $authenticator->authenticate(new Request())->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
