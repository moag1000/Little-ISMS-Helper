<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use App\Repository\Notification\NotificationChannelRepository;
use App\Service\Notification\TemplateInstantiator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class TemplateInstantiatorTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationChannelRepository&MockObject $channelRepo;
    private TemplateInstantiator $instantiator;

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->channelRepo = $this->createMock(NotificationChannelRepository::class);
        $this->instantiator = new TemplateInstantiator($this->em, $this->channelRepo);
    }

    private function makeTemplate(): NotificationTemplate
    {
        $tpl = new NotificationTemplate();
        $tpl->setTemplateKey('tier1.test');
        $tpl->setName('Test Template');
        $tpl->setDefaultEventType('incident.created');
        $tpl->setDefaultConditions([['field' => 'severity', 'op' => '>=', 'value' => 'high']]);
        $tpl->setDefaultChannels([['type' => 'email']]);
        $tpl->setCategory(NotificationTemplate::CATEGORY_INCIDENT);
        return $tpl;
    }

    #[Test]
    public function testInstantiateCreatesRuleWithTemplateDefaults(): void
    {
        $template = $this->makeTemplate();
        $tenant   = new Tenant();

        // No existing channel — auto-create path
        $this->channelRepo->method('findActiveByType')->willReturn([]);
        $this->em->expects($this->atLeastOnce())->method('persist');

        $rule = $this->instantiator->instantiate($template, $tenant);

        self::assertInstanceOf(NotificationRule::class, $rule);
        self::assertSame($tenant, $rule->getTenant());
        self::assertSame('Test Template', $rule->getName());
        self::assertSame('incident.created', $rule->getEventType());
        self::assertFalse($rule->isActive()); // starts inactive pending tenant review
    }

    #[Test]
    public function testInstantiateReusesExistingChannel(): void
    {
        $template        = $this->makeTemplate();
        $tenant          = new Tenant();
        $existingChannel = new NotificationChannel();
        $existingChannel->setType(NotificationChannel::TYPE_EMAIL);
        $existingChannel->setIsActive(true);

        $this->channelRepo->method('findActiveByType')
            ->with(NotificationChannel::TYPE_EMAIL, $tenant)
            ->willReturn([$existingChannel]);

        $persistedEntities = [];
        $this->em->method('persist')->willReturnCallback(
            function ($entity) use (&$persistedEntities): void { $persistedEntities[] = $entity; }
        );

        $rule = $this->instantiator->instantiate($template, $tenant);

        // Channel should be the existing one, not a newly created placeholder
        self::assertCount(1, $rule->getChannels());
        self::assertSame($existingChannel, $rule->getChannels()->first());

        // Only the rule itself should be persisted (not a new channel)
        $newChannels = array_filter($persistedEntities, fn($e) => $e instanceof NotificationChannel);
        self::assertCount(0, array_values($newChannels));
    }

    #[Test]
    public function testInstantiateAutoCreatesEmailChannelWhenNoneExists(): void
    {
        $template = $this->makeTemplate(); // defaults to email channel
        $tenant   = new Tenant();

        $this->channelRepo->method('findActiveByType')->willReturn([]);

        $persistedEntities = [];
        $this->em->method('persist')->willReturnCallback(
            function ($entity) use (&$persistedEntities): void { $persistedEntities[] = $entity; }
        );

        $rule = $this->instantiator->instantiate($template, $tenant);

        // One channel should have been auto-created
        $newChannels = array_filter($persistedEntities, fn($e) => $e instanceof NotificationChannel);
        self::assertCount(1, array_values($newChannels));

        $autoChannel = array_values($newChannels)[0];
        self::assertSame(NotificationChannel::TYPE_EMAIL, $autoChannel->getType());
        self::assertFalse($autoChannel->isActive()); // placeholder, inactive until configured
    }
}
