<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\GenericOidcAuthenticator;
use App\Service\Sso\SsoAccountLinkPolicy;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * #1059: a refused SSO login with a user-facing reason (link by e-mail
 * disabled) must reach the login page, which reads the last authentication
 * error from the session. Technical failures stay log-only.
 */
final class GenericOidcAuthenticatorFailureTest extends KernelTestCase
{
    #[Test]
    public function userFacingFailureIsStoredForTheLoginPage(): void
    {
        $request = $this->request();
        $exception = new CustomUserMessageAuthenticationException(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE);

        $response = $this->authenticator()->onAuthenticationFailure($request, $exception);

        self::assertNotNull($response);
        self::assertTrue($response->isRedirect());
        self::assertSame($exception, $request->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
    }

    #[Test]
    public function technicalFailureIsNotShownOnTheLoginPage(): void
    {
        $request = $this->request();

        $this->authenticator()->onAuthenticationFailure($request, new AuthenticationException('Token exchange failed: x'));

        self::assertFalse($request->getSession()->has(SecurityRequestAttributes::AUTHENTICATION_ERROR));
    }

    #[Test]
    public function unsafeStoredTargetIsNotUsedAfterLogin(): void
    {
        $request = $this->request();
        $request->getSession()->set(GenericOidcAuthenticator::SESSION_TARGET_KEY, 'https://evil.example/');

        $response = $this->authenticator()->onAuthenticationSuccess(
            $request,
            $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class),
            'main',
        );

        self::assertNotNull($response);
        self::assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
        self::assertFalse($request->getSession()->has(GenericOidcAuthenticator::SESSION_TARGET_KEY));
    }

    #[Test]
    public function failedLoginClearsTheStoredTarget(): void
    {
        $request = $this->request();
        $request->getSession()->set(GenericOidcAuthenticator::SESSION_TARGET_KEY, '/en/dashboard');

        $this->authenticator()->onAuthenticationFailure($request, new AuthenticationException('x'));

        self::assertFalse($request->getSession()->has(GenericOidcAuthenticator::SESSION_TARGET_KEY));
    }

    private function authenticator(): GenericOidcAuthenticator
    {
        self::bootKernel();

        return static::getContainer()->get(GenericOidcAuthenticator::class);
    }

    private function request(): Request
    {
        $request = Request::create('/sso/demo/callback');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
