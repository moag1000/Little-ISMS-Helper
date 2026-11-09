<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Service\Notification\Channel\WebhookChannel;
use App\Service\Sso\SecretEncryptionInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Http\UserUrlHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
final class WebhookChannelTest extends TestCase
{
    private UserUrlHttpClient&MockObject $httpClient;
    private SecretEncryptionInterface&MockObject $encryption;
    private ValidatorInterface&MockObject $validator;
    private WebhookChannel $channel;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(UserUrlHttpClient::class);
        $this->encryption = $this->createMock(SecretEncryptionInterface::class);
        $this->validator  = $this->createMock(ValidatorInterface::class);
        $this->encryption->method('decrypt')
            ->willReturnCallback(static fn (string $v): ?string => $v === 'enc-secret' ? 'my-plain-secret' : null);
        $this->channel    = new WebhookChannel($this->httpClient, $this->encryption, $this->validator);
    }

    private function makeDelivery(): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->setAttemptedAt(new DateTimeImmutable());
        return $delivery;
    }

    private function makeRule(): NotificationRule
    {
        $rule = new NotificationRule();
        $rule->setName('Test Rule');
        $rule->setEventType('incident.created');
        return $rule;
    }

    private function makeChannel(string $url): NotificationChannel
    {
        $ch = new NotificationChannel();
        $ch->setType(NotificationChannel::TYPE_WEBHOOK);
        $ch->setConfig(['url' => $url]);
        $ch->setSecretEncrypted('enc-secret');
        return $ch;
    }

    #[Test]
    public function webhookDoesNotFollowRedirects(): void
    {
        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($violations);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(302);
        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', 'https://hooks.example.com/r', $this->callback(
                static fn(array $options): bool => ($options['max_redirects'] ?? null) === 0,
            ))
            ->willReturn($response);

        $delivery = $this->makeDelivery();
        $this->channel->deliver($this->makeRule(), $this->makeChannel('https://hooks.example.com/r'), $delivery, []);

        self::assertNotSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus(), 'A redirect is not a delivery.');
    }

    #[Test]
    public function testSuccessfulWebhookDelivery(): void
    {
        $url      = 'https://hooks.example.com/test';
        $rule     = $this->makeRule();
        $ch       = $this->makeChannel($url);
        $delivery = $this->makeDelivery();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($violations);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')->willReturn($response);

        $this->channel->deliver($rule, $ch, $delivery, ['severity' => 'high']);

        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
    }

    #[Test]
    public function testHmacSignatureIsAttachedWhenSecretConfigured(): void
    {
        $url      = 'https://hooks.example.com/hmac';
        $rule     = $this->makeRule();
        $ch       = $this->makeChannel($url);
        $delivery = $this->makeDelivery();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($violations);

        $capturedHeaders = [];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $reqUrl, array $options) use ($response, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $response;
            });

        $this->channel->deliver($rule, $ch, $delivery, ['severity' => 'high']);

        self::assertArrayHasKey('X-Signature', $capturedHeaders);
        self::assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}$/', $capturedHeaders['X-Signature']);
    }

    /** S16: without a secret the receiver cannot verify the sender — nothing is sent. */
    #[Test]
    public function unsignedWebhookIsNotSent(): void
    {
        $ch = $this->makeChannel('https://hooks.example.com/unsigned');
        $ch->setSecretEncrypted(null);
        $delivery = $this->makeDelivery();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($violations);
        $this->httpClient->expects($this->never())->method('request');

        $this->channel->deliver($this->makeRule(), $ch, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('notification.error.webhook_no_secret', (string) $delivery->getErrorMessage());
    }

    #[Test]
    public function testInternalIpIsRejected(): void
    {
        $url      = 'http://192.168.1.1/hook';
        $rule     = $this->makeRule();
        $ch       = $this->makeChannel($url);
        $delivery = $this->makeDelivery();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(1);
        $this->validator->method('validate')
            ->willReturn($violations);

        // HttpClient must NOT be called
        $this->httpClient->expects($this->never())->method('request');

        $this->channel->deliver($rule, $ch, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('notification.error.webhook_internal_ip', (string) $delivery->getErrorMessage());
    }

    #[Test]
    public function testEmptyUrlIsRejectedWithoutHttpCall(): void
    {
        $rule     = $this->makeRule();
        $ch       = $this->makeChannel(''); // no URL
        $delivery = $this->makeDelivery();

        $this->httpClient->expects($this->never())->method('request');
        $this->validator->expects($this->never())->method('validate');

        $this->channel->deliver($rule, $ch, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
    }

    #[Test]
    public function testNon2xxStatusCodeMarksFailed(): void
    {
        $url      = 'https://hooks.example.com/500';
        $rule     = $this->makeRule();
        $ch       = $this->makeChannel($url);
        $delivery = $this->makeDelivery();

        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);
        $this->validator->method('validate')->willReturn($violations);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $this->httpClient->method('request')->willReturn($response);

        $this->channel->deliver($rule, $ch, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('500', (string) $delivery->getErrorMessage());
    }
}
