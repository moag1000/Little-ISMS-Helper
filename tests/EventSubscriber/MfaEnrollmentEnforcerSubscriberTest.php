<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\MfaToken;
use App\Entity\User;
use App\EventSubscriber\MfaEnrollmentEnforcerSubscriber;
use App\Repository\SystemSettingsRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enforces that the security.mfa_required_roles policy is REAL: a privileged
 * user without a second factor is redirected to the MFA enrolment page until
 * they enrol — closing the "stored-but-never-enforced" dead-config gap.
 */
#[AllowMockObjectsWithoutExpectations]
final class MfaEnrollmentEnforcerSubscriberTest extends TestCase
{
    #[Test]
    public function unenrolledRequiredRoleUserIsRedirectedToEnrolment(): void
    {
        $event = $this->dispatch(
            path: '/de/dashboard',
            userRoles: ['ROLE_ADMIN', 'ROLE_USER'],
            mfaEnrolled: false,
            policyJson: '["ROLE_ADMIN","ROLE_SUPER_ADMIN"]',
        );

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/de/profile/mfa', $response->getTargetUrl());
    }

    #[Test]
    public function noRedirectWhenAlreadyOnEnrolmentFlow(): void
    {
        $event = $this->dispatch(
            path: '/de/profile/mfa/setup-totp',
            userRoles: ['ROLE_ADMIN'],
            mfaEnrolled: false,
            policyJson: '["ROLE_ADMIN"]',
        );
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noRedirectWhenUserAlreadyEnrolled(): void
    {
        $event = $this->dispatch(
            path: '/de/dashboard',
            userRoles: ['ROLE_ADMIN'],
            mfaEnrolled: true,
            policyJson: '["ROLE_ADMIN"]',
        );
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noRedirectForUserWithoutARequiredRole(): void
    {
        $event = $this->dispatch(
            path: '/de/dashboard',
            userRoles: ['ROLE_USER'],
            mfaEnrolled: false,
            policyJson: '["ROLE_ADMIN","ROLE_SUPER_ADMIN"]',
        );
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function noRedirectWhenPolicyDisabled(): void
    {
        $event = $this->dispatch(
            path: '/de/dashboard',
            userRoles: ['ROLE_ADMIN'],
            mfaEnrolled: false,
            policyJson: '[]',
        );
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function ignoresSubRequests(): void
    {
        $event = $this->dispatch(
            path: '/de/dashboard',
            userRoles: ['ROLE_ADMIN'],
            mfaEnrolled: false,
            policyJson: '["ROLE_ADMIN"]',
            mainRequest: false,
        );
        self::assertNull($event->getResponse());
    }

    /**
     * @param string[] $userRoles
     */
    private function dispatch(
        string $path,
        array $userRoles,
        bool $mfaEnrolled,
        string $policyJson,
        bool $mainRequest = true,
    ): RequestEvent {
        $user = new User();
        if ($mfaEnrolled) {
            $user->addMfaToken(new MfaToken()); // isActive defaults to true
        }

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $role): bool => in_array($role, $userRoles, true),
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/de/profile/mfa');

        $settings = $this->createMock(SystemSettingsRepository::class);
        $settings->method('getSetting')->willReturn($policyJson);

        $subscriber = new MfaEnrollmentEnforcerSubscriber($security, $urlGenerator, $settings);

        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            $mainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );

        $subscriber->onKernelRequest($event);

        return $event;
    }
}
