<?php

declare(strict_types=1);

namespace App\Form\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Form\Type\JsonBuilderType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for creating and editing NotificationChannel entities.
 *
 * secretPlain is mapped: false — the controller encrypts and stores it
 * via SecretEncryptionInterface before persisting.
 *
 * configJson (mapped: false) edits NotificationChannel::$config with the
 * structured JsonBuilderType — the keys EmailChannel (recipients, from,
 * subject) and WebhookChannel (url, timeout) read. Other keys already in the
 * config survive untouched; `delivery_mode` is driven by the deliveryMode
 * radio below.
 */
final class NotificationChannelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'notification.channel.field.name',
                'required' => true,
                'attr'     => [
                    'maxlength'   => 120,
                    'placeholder' => 'notification.channel.field.name_placeholder',
                ],
                'help'     => 'notification.channel.help.name',
            ])
            ->add('type', ChoiceType::class, [
                'label'                     => 'notification.channel.field.type',
                'required'                  => true,
                'choices'                   => [
                    'notification.channel.type.email'   => NotificationChannel::TYPE_EMAIL,
                    'notification.channel.type.webhook' => NotificationChannel::TYPE_WEBHOOK,
                    'notification.channel.type.in_app'  => NotificationChannel::TYPE_IN_APP,
                ],
                'expanded'                  => true,
                'multiple'                  => false,
                'choice_translation_domain' => 'notification',
                'help'                      => 'notification.channel.help.type',
            ])
            ->add('configJson', JsonBuilderType::class, [
                'label'       => 'notification.channel.field.config',
                'required'    => false,
                'mapped'      => false,
                'help'        => 'notification.channel.help.config',
                'shape'       => 'object',
                'hidden_keys' => ['delivery_mode'],
                'fields'      => [
                    ['key' => 'recipients', 'type' => 'tags', 'item_type' => 'email', 'label' => 'notification.channel.config.recipients',
                        'help' => 'notification.channel.config.recipients_help'],
                    ['key' => 'from', 'type' => 'email', 'label' => 'notification.channel.config.from'],
                    ['key' => 'subject', 'type' => 'text', 'label' => 'notification.channel.config.subject'],
                    ['key' => 'url', 'type' => 'url', 'label' => 'notification.channel.config.url',
                        'help' => 'notification.channel.config.url_help'],
                    ['key' => 'timeout', 'type' => 'number', 'label' => 'notification.channel.config.timeout'],
                ],
            ])
            ->add('secretPlain', PasswordType::class, [
                'label'    => 'notification.channel.field.secret',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'autocomplete' => 'new-password',
                    'placeholder'  => 'notification.channel.field.secret_placeholder',
                ],
                'help'     => 'notification.channel.help.secret',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'notification.channel.field.is_active',
                'required' => false,
                'help'     => 'notification.channel.help.is_active',
            ])
            ->add('deliveryMode', ChoiceType::class, [
                'label'                     => 'notification.channel_digest.field.delivery_mode',
                'required'                  => true,
                'mapped'                    => false,
                'choices'                   => [
                    'notification.channel_digest.type.immediate'    => NotificationChannel::DELIVERY_MODE_IMMEDIATE,
                    'notification.channel_digest.type.digest_daily' => NotificationChannel::DELIVERY_MODE_DIGEST_DAILY,
                ],
                'expanded'                  => true,
                'multiple'                  => false,
                'choice_translation_domain' => 'notification',
                'help'                      => 'notification.channel_digest.help.delivery_mode',
                'attr'                      => ['class' => 'js-delivery-mode-radio'],
            ]);

        // Pre-fill the unmapped deliveryMode + configJson from the channel.
        // POST_SET_DATA, not PRE_SET_DATA: the data mapper resets every
        // unmapped child to its configured data right after PRE_SET_DATA, and
        // a child with a static `data` option ignores setData() altogether —
        // either way the stored values never reached the edit form (it showed
        // the defaults and saving overwrote the stored config).
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            /** @var NotificationChannel|null $channel */
            $channel = $event->getData();
            $form = $event->getForm();
            if ($form->has('deliveryMode')) {
                $form->get('deliveryMode')->setData(
                    $channel?->getDeliveryMode() ?? NotificationChannel::DELIVERY_MODE_IMMEDIATE,
                );
            }
            if ($form->has('configJson') && $channel !== null && $channel->getConfig() !== []) {
                $form->get('configJson')->setData($channel->getConfig());
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => NotificationChannel::class,
            'translation_domain' => 'notification',
        ]);
    }
}
