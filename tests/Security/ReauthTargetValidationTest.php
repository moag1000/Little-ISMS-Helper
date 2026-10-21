<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LoginSuccessHandler;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * #1059 m-7: the re-auth page uses the same target rule as the SSO start
 * (SafeRedirectTarget), and the password-login success handler re-validates
 * the stored target — `/\evil.com` (read by browsers as `//evil.com`) never
 * becomes a Location.
 */
final class ReauthTargetValidationTest extends WebTestCase
{
    #[Test]
    public function reauthPageRefusesBackslashTarget(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('reauth_' . substr(md5(uniqid('', true)), 0, 10) . '@example.com')
            ->setFirstName('Re')->setLastName('Auth')->setRoles(['ROLE_USER'])
            ->setPassword('hashed_password')->setIsActive(true);
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/en/reauth/sso/azure_oauth?return_to=' . rawurlencode('/\\evil.com'));
            $session = $client->getRequest()->getSession();
            self::assertSame('/', $session->get('_security.main.target_path'));
            self::assertSame('/', $session->get('_reauth_return_to'));

            $client->request('GET', '/en/reauth/sso/azure_oauth?return_to=' . rawurlencode('/en/dashboard'));
            self::assertSame('/en/dashboard', $client->getRequest()->getSession()->get('_security.main.target_path'));
        } finally {
            $em->clear();
            $em->getConnection()->executeStatement('DELETE FROM users WHERE email = ?', [$user->getEmail()]);
        }
    }

    #[Test]
    public function passwordLoginSuccessRevalidatesStoredTarget(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/de/dashboard');
        // MfaService is final: the real one (a user without MFA devices).
        $handler = new LoginSuccessHandler($urls, static::getContainer()->get(MfaService::class));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User());

        foreach (['/\\evil.com' => '/de/dashboard', '/en/risk' => '/en/risk'] as $stored => $expected) {
            $request = Request::create('https://isms.example/de/login');
            $request->setSession(new Session(new MockArraySessionStorage()));
            $request->getSession()->set('_security.main.target_path', $stored);

            self::assertSame($expected, $handler->onAuthenticationSuccess($request, $token)->getTargetUrl());
        }
    }
}
