<?php

declare(strict_types=1);

namespace App\Tests\Form\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Form\Notification\NotificationChannelType;
use Symfony\Component\Form\Test\TypeTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for NotificationChannelType form.
 */
final class NotificationChannelTypeTest extends TypeTestCase
{
    #[Test]
    public function formSubmitsValidData(): void
    {
        $formData = [
            'name'     => 'IT Security Email',
            'type'     => NotificationChannel::TYPE_EMAIL,
            'isActive' => true,
        ];

        $form = $this->factory->create(NotificationChannelType::class, new NotificationChannel());
        $form->submit($formData);

        self::assertTrue($form->isSynchronized());

        /** @var NotificationChannel $channel */
        $channel = $form->getData();
        self::assertSame('IT Security Email', $channel->getName());
        self::assertSame(NotificationChannel::TYPE_EMAIL, $channel->getType());
        self::assertTrue($channel->isActive());
    }

    #[Test]
    public function formHasExpectedFields(): void
    {
        $form = $this->factory->create(NotificationChannelType::class);

        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('type'));
        self::assertTrue($form->has('configJson'));
        self::assertTrue($form->has('secretPlain'));
        self::assertTrue($form->has('isActive'));
    }

    #[Test]
    public function secretPlainIsNotMapped(): void
    {
        $form = $this->factory->create(NotificationChannelType::class);

        $config = $form->get('secretPlain')->getConfig();
        self::assertFalse($config->getMapped());
    }

    #[Test]
    public function configJsonIsNotMapped(): void
    {
        $form = $this->factory->create(NotificationChannelType::class);

        $config = $form->get('configJson')->getConfig();
        self::assertFalse($config->getMapped());
    }

    protected function getTypes(): array
    {
        return [
            new NotificationChannelType(),
        ];
    }
}
