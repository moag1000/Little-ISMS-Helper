<?php

declare(strict_types=1);

namespace App\Tests\Form\Notification;

use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Form\Notification\NotificationRuleType;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationRuleType form fields and options.
 *
 * We test form instantiation and field structure without the EntityType
 * (channels) since that requires a full Doctrine registry. The EntityType
 * integration is covered by the controller smoke test.
 */
#[AllowMockObjectsWithoutExpectations]
final class NotificationRuleTypeTest extends TestCase
{
    #[Test]
    public function formTypeInstantiatesWithModuleService(): void
    {
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);

        $type = new NotificationRuleType($moduleService);
        self::assertInstanceOf(NotificationRuleType::class, $type);
    }

    #[Test]
    public function dataClassIsNotificationRule(): void
    {
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $type = new NotificationRuleType($moduleService);

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([]);
        self::assertSame(NotificationRule::class, $options['data_class']);
    }

    #[Test]
    public function translationDomainIsNotification(): void
    {
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $type = new NotificationRuleType($moduleService);

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([]);
        self::assertSame('notification', $options['translation_domain']);
    }

    #[Test]
    public function tenantOptionAllowsNullOrTenant(): void
    {
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $type = new NotificationRuleType($moduleService);

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        // Null is allowed
        $options = $resolver->resolve(['tenant' => null]);
        self::assertNull($options['tenant']);

        // Tenant instance is allowed
        $tenant  = new Tenant();
        $options = $resolver->resolve(['tenant' => $tenant]);
        self::assertSame($tenant, $options['tenant']);
    }
}
