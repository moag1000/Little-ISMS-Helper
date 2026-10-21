<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Security\AzureOAuthAuthenticator;
use App\Security\AzureSamlAuthenticator;
use App\Service\Sso\SsoAccountLinkPolicy;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;
use TheNetworg\OAuth2\Client\Token\AccessToken;

/**
 * #1059: the env-configured Azure OAuth / SAML logins link an existing
 * account by e-mail when its object id is unknown. A privileged account is
 * never linked that way; AZURE_ALLOW_EMAIL_LINKING=false refuses the link
 * with the same message as provider-record logins; an account already bound
 * to another object id is not re-bound. Object-id bindings keep working.
 */
final class AzureEmailLinkingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function flows(): iterable
    {
        yield 'oauth' => ['oauth'];
        yield 'saml' => ['saml'];
    }

    #[Test]
    #[DataProvider('flows')]
    public function privilegedAccountIsNeverLinkedByEmail(string $flow): void
    {
        foreach (['ROLE_SUPER_ADMIN', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'] as $role) {
            $target = (new User())->setEmail('jane@example.com')->setRoles([$role, 'ROLE_ADMIN']);
            try {
                $this->login($flow, null, $target, allowEmailLinking: true);
                self::fail($role . ' account must not be linked by e-mail');
            } catch (AuthenticationException $e) {
                self::assertNotInstanceOf(CustomUserMessageAuthenticationException::class, $e);
            }
            self::assertNull($target->getAzureObjectId(), $role . ' account was bound');
        }
    }

    #[Test]
    #[DataProvider('flows')]
    public function disabledSettingRefusesWithTheProviderRecordMessage(string $flow): void
    {
        $target = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER']);

        try {
            $this->login($flow, null, $target, allowEmailLinking: false);
            self::fail('link by e-mail must be refused');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertSame(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE, $e->getMessageKey());
        }
        self::assertNull($target->getAzureObjectId());
    }

    #[Test]
    #[DataProvider('flows')]
    public function accountBoundToAnotherObjectIdIsNotRebound(string $flow): void
    {
        $target = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER'])->setAzureObjectId('other-object');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->login($flow, null, $target, allowEmailLinking: true);
    }

    #[Test]
    #[DataProvider('flows')]
    public function accountBoundToAProviderRecordIsNotRebound(string $flow): void
    {
        $target = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER'])
            ->setSsoProvider(new \App\Entity\IdentityProvider());

        try {
            $this->login($flow, null, $target, allowEmailLinking: true);
            self::fail('an account bound to a provider record must not be re-bound');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertSame(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE, $e->getMessageKey());
        }
        self::assertNull($target->getAzureObjectId());
    }

    #[Test]
    #[DataProvider('flows')]
    public function enabledSettingLinksOrdinaryAccount(string $flow): void
    {
        $target = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER']);

        self::assertSame($target, $this->login($flow, null, $target, allowEmailLinking: true));
        self::assertSame('object-1', $target->getAzureObjectId());
    }

    #[Test]
    #[DataProvider('flows')]
    public function existingObjectIdBindingStillWorksForPrivilegedAccount(string $flow): void
    {
        $operator = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_SUPER_ADMIN'])->setAzureObjectId('object-1');

        self::assertSame($operator, $this->login($flow, $operator, null, allowEmailLinking: false));
    }

    private function login(string $flow, ?User $byObjectId, ?User $byEmail, bool $allowEmailLinking): User
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByAzureObjectId')->willReturn($byObjectId);
        $users->method('findOneBy')->willReturn($byEmail);
        $tenants = $this->createStub(TenantRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        if ($flow === 'oauth') {
            $token = new AccessToken(['access_token' => 'token-123'], $this->createStub(Azure::class));
            $client = $this->createStub(OAuth2ClientInterface::class);
            $client->method('getAccessToken')->willReturn($token);
            $client->method('fetchUserFromToken')->willReturn(new AzureResourceOwner([
                'oid' => 'object-1',
                'tid' => 'azure-tenant-1',
                'email' => 'jane@example.com',
            ], $token));
            $registry = $this->createStub(ClientRegistry::class);
            $registry->method('getClient')->willReturn($client);
            $authenticator = new AzureOAuthAuthenticator($registry, $em, $users, $router, $tenants, $allowEmailLinking);
        } else {
            $auth = $this->createStub(Auth::class);
            $auth->method('isAuthenticated')->willReturn(true);
            $auth->method('getNameId')->willReturn('jane@example.com');
            $auth->method('getAttributes')->willReturn([
                'http://schemas.microsoft.com/identity/claims/objectidentifier' => ['object-1'],
            ]);
            $authenticator = new AzureSamlAuthenticator($em, $users, $router, $tenants, $allowEmailLinking);
            (new \ReflectionProperty(AzureSamlAuthenticator::class, 'samlAuth'))->setValue($authenticator, $auth);
        }

        $user = $authenticator->authenticate(new Request())->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
