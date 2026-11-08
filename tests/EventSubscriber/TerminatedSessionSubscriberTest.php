<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\Entity\UserSession;
use App\EventSubscriber\TerminatedSessionSubscriber;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * "Sitzung beenden" signs the browser out on its next request (S4 add-on,
 * 2026-11); a row only the idle cleanup marked stays harmless.
 */
#[AllowMockObjectsWithoutExpectations]
final class TerminatedSessionSubscriberTest extends TestCase
{
    #[Test]
    public function aSessionEndedByAnAdministratorIsSignedOut(): void
    {
        [$event, $tokens] = $this->run('forced', active: false);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/login', $event->getResponse()->getTargetUrl());
        self::assertNull($tokens->getToken());
    }

    #[Test]
    public function aSessionOverTheConcurrentLimitIsSignedOut(): void
    {
        [$event] = $this->run('limit_exceeded', active: false);

        self::assertNotNull($event->getResponse());
    }

    #[Test]
    public function activeAndIdleTimedOutSessionsStay(): void
    {
        [$active, $tokensA] = $this->run(null, active: true);
        [$timeout, $tokensB] = $this->run('timeout', active: false);

        self::assertNull($active->getResponse());
        self::assertNotNull($tokensA->getToken());
        self::assertNull($timeout->getResponse());
        self::assertNotNull($tokensB->getToken());
    }

    /** @return array{RequestEvent, TokenStorage} */
    private function run(?string $endReason, bool $active): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = Request::create('/de/dashboard');
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());

        $user = (new User())->setEmail('isb@example.test');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $row = (new UserSession())->setUser($user)->setSessionId($session->getId());
        if (!$active) {
            $row->terminate((string) $endReason);
        }
        $repository = $this->createStub(UserSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($row);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/login');

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new TerminatedSessionSubscriber($this->createStub(EntityManagerInterface::class), $tokens, $repository, $urls, new NullLogger()))
            ->onKernelRequest($event);

        return [$event, $tokens];
    }
}
