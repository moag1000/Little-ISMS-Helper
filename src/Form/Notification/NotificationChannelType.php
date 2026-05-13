<?php

declare(strict_types=1);

namespace App\Form\Notification;

use App\Entity\Notification\NotificationChannel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for creating and editing NotificationChannel entities.
 *
 * secretPlain is mapped: false — the controller encrypts and stores it
 * via SecretEncryptionInterface before persisting.
 *
 * config is a JSON textarea so admins can set channel-specific keys
 * (e.g. recipients[], url, timeout) without bespoke sub-forms for each type.
 */
class NotificationChannelType extends AbstractType
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
            ->add('configJson', TextareaType::class, [
                'label'      => 'notification.channel.field.config',
                'required'   => false,
                'mapped'     => false,
                'attr'       => [
                    'rows'        => 6,
                    'placeholder' => '{"recipients": ["admin@example.com"]}',
                    'class'       => 'font-monospace',
                ],
                'help'       => 'notification.channel.help.config',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => NotificationChannel::class,
            'translation_domain' => 'notification',
        ]);
    }
}
