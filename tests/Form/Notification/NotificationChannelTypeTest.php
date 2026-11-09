<?php

declare(strict_types=1);

namespace App\Tests\Form\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Form\Notification\NotificationChannelType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Translation\IdentityTranslator;
use App\Validator\Constraint\JsonShapeValidator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for NotificationChannelType form.
 */
final class NotificationChannelTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        // JsonShapeValidator (configJson) needs a translator; the factory keeps one instance per
        // validator class (FormValidator relies on that across nested forms).
        $validators = new ConstraintValidatorFactory([
            JsonShapeValidator::class => new JsonShapeValidator(new IdentityTranslator()),
        ]);

        return [new ValidatorExtension(Validation::createValidatorBuilder()->setConstraintValidatorFactory($validators)->getValidator())];
    }

    /** S16: a webhook channel cannot be saved without an HMAC secret (unsigned = not sent). */
    #[Test]
    public function webhookWithoutSecretIsRejected(): void
    {
        $form = $this->factory->create(NotificationChannelType::class, new NotificationChannel());
        $form->submit(['name' => 'Hook', 'type' => NotificationChannel::TYPE_WEBHOOK, 'isActive' => true]);

        self::assertFalse($form->isValid());
        self::assertSame('validators.notification.webhook_secret_required', $form->get('secretPlain')->getErrors()[0]?->getMessageTemplate());
    }

    #[Test]
    public function webhookWithSecretOrStoredSecretIsAccepted(): void
    {
        $form = $this->factory->create(NotificationChannelType::class, new NotificationChannel());
        $form->submit(['name' => 'Hook', 'type' => NotificationChannel::TYPE_WEBHOOK, 'secretPlain' => 's3cret']);
        self::assertCount(0, $form->get('secretPlain')->getErrors());

        $stored = (new NotificationChannel())->setSecretEncrypted('enc');
        $form = $this->factory->create(NotificationChannelType::class, $stored);
        $form->submit(['name' => 'Hook', 'type' => NotificationChannel::TYPE_WEBHOOK]);
        self::assertCount(0, $form->get('secretPlain')->getErrors());
    }

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
