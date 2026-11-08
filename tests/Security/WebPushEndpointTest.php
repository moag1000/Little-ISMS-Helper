<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\PushSubscription;
use App\Entity\Tenant;
use App\Entity\User;
use App\Http\PushEndpointPolicy;
use App\Http\UserUrlHttpClient;
use App\Repository\PushSubscriptionRepository;
use App\Exception\Security\PushSubscriptionTakenException;
use App\Service\WebPushService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * S11: a push endpoint is user input. Only https URLs of the browser push services are
 * registered, and a subscription of another account is taken over only by the browser
 * that holds its keys.
 */
final class WebPushEndpointTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function endpoints(): iterable
    {
        yield 'chrome' => ['https://fcm.googleapis.com/fcm/send/abc:def', true];
        yield 'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAA', true];
        yield 'windows' => ['https://wns2-am3p.notify.windows.com/w/?token=x', true];
        yield 'safari' => ['https://web.push.apple.com/QKx', true];
        yield 'plain http' => ['http://fcm.googleapis.com/fcm/send/abc', false];
        yield 'internal host' => ['https://10.0.0.5/hook', false];
        yield 'look-alike' => ['https://fcm.googleapis.com.evil.example/x', false];
        yield 'suffix without dot' => ['https://evilpush.apple.com.example/x', false];
        yield 'credentials' => ['https://user:pw@fcm.googleapis.com/x', false];
        yield 'port' => ['https://fcm.googleapis.com:8443/x', false];
        yield 'any url' => ['https://attacker.example/collect', false];
        yield 'too long' => ['https://fcm.googleapis.com/' . str_repeat('a', 1100), false];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function onlyBrowserPushServicesAreAccepted(string $endpoint, bool $accepted): void
    {
        self::assertSame($accepted, PushEndpointPolicy::accepts($endpoint));
    }

    #[Test]
    public function subscribeRefusesAnEndpointOutsideThePushServices(): void
    {
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->expects(self::never())->method('save');

        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        $this->service($repo)->subscribe($this->user(1), new Tenant(), 'https://attacker.example/x', 'k', 'a');
    }

    #[Test]
    public function anotherAccountsSubscriptionIsNotTakenOverWithoutItsKeys(): void
    {
        $existing = (new PushSubscription())
            ->setUser($this->user(1))
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/victim')
            ->setPublicKey('victim-key')
            ->setAuthToken('victim-auth');
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByEndpoint')->willReturn($existing);
        $repo->expects(self::never())->method('save');

        $this->expectException(PushSubscriptionTakenException::class);
        $this->service($repo)->subscribe(
            $this->user(2),
            new Tenant(),
            'https://fcm.googleapis.com/fcm/send/victim',
            'attacker-key',
            'attacker-auth',
        );
    }

    #[Test]
    public function theSameBrowserMayMoveItsSubscriptionToTheNextAccount(): void
    {
        $existing = (new PushSubscription())
            ->setUser($this->user(1))
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/shared')
            ->setPublicKey('device-key')
            ->setAuthToken('device-auth');
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByEndpoint')->willReturn($existing);
        $repo->expects(self::once())->method('save');

        $next = $this->user(2);
        $result = $this->service($repo)->subscribe(
            $next,
            new Tenant(),
            'https://fcm.googleapis.com/fcm/send/shared',
            'device-key',
            'device-auth',
        );

        self::assertSame($next, $result->getUser());
    }

    #[Test]
    public function deliveryGoesThroughTheGuardedClient(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/WebPushService.php');

        self::assertMatchesRegularExpression('/new WebPush\([\s\S]*?\$psr18, \$psr18, \$psr18\)/', $source);
        self::assertStringContainsString('new Psr18Client($this->httpClient)', $source);
    }

    private function service(PushSubscriptionRepository $repo): WebPushService
    {
        return new WebPushService($repo, new NullLogger(), new UserUrlHttpClient(new MockHttpClient()), sys_get_temp_dir());
    }

    private function user(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
