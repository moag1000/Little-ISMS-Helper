<?php

declare(strict_types=1);

namespace App\Tests\Entity\Notification;

use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTemplateTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $tpl = new NotificationTemplate();

        self::assertNull($tpl->getId());
        self::assertNull($tpl->getTenant());
        self::assertSame('', $tpl->getTemplateKey());
        self::assertSame('', $tpl->getName());
        self::assertSame('', $tpl->getDefaultEventType());
        self::assertSame([], $tpl->getDefaultConditions());
        self::assertSame([], $tpl->getDefaultChannels());
        self::assertSame('', $tpl->getCategory());
        self::assertTrue($tpl->isGlobal());
    }

    #[Test]
    public function testAccessors(): void
    {
        $tenant = new Tenant();
        $tpl    = new NotificationTemplate();

        $tpl->setTenant($tenant);
        $tpl->setTemplateKey('tier1.test');
        $tpl->setName('Test Template');
        $tpl->setDefaultEventType('incident.created');
        $tpl->setDefaultConditions([['field' => 'severity', 'op' => '>=', 'value' => 'high']]);
        $tpl->setDefaultChannels([['type' => 'email']]);
        $tpl->setCategory(NotificationTemplate::CATEGORY_INCIDENT);

        self::assertSame($tenant, $tpl->getTenant());
        self::assertSame('tier1.test', $tpl->getTemplateKey());
        self::assertSame('Test Template', $tpl->getName());
        self::assertSame('incident.created', $tpl->getDefaultEventType());
        self::assertSame([['field' => 'severity', 'op' => '>=', 'value' => 'high']], $tpl->getDefaultConditions());
        self::assertSame([['type' => 'email']], $tpl->getDefaultChannels());
        self::assertSame('incident', $tpl->getCategory());
        self::assertFalse($tpl->isGlobal());
    }

    #[Test]
    public function testGlobalTemplateHasNullTenant(): void
    {
        $tpl = new NotificationTemplate();
        $tpl->setTenant(null);

        self::assertTrue($tpl->isGlobal());
    }

    #[Test]
    public function testCategoryConstants(): void
    {
        self::assertSame('incident',   NotificationTemplate::CATEGORY_INCIDENT);
        self::assertSame('compliance', NotificationTemplate::CATEGORY_COMPLIANCE);
        self::assertSame('sla',        NotificationTemplate::CATEGORY_SLA);
        self::assertSame('privacy',    NotificationTemplate::CATEGORY_PRIVACY);
    }
}
