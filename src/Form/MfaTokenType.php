<?php

namespace App\Form;

use App\Entity\MfaToken;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MfaTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'label' => 'mfa_token.field.user',
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'mfa_token.placeholder.user',
                'required' => true,
                'help' => 'mfa_token.help.user',
            ])
            ->add('tokenType', ChoiceType::class, [
                'label' => 'mfa_token.field.token_type',
                'choices' => [
                    'mfa_token.type.totp' => 'totp',
                    'mfa_token.type.webauthn' => 'webauthn',
                    'mfa_token.type.sms' => 'sms',
                    'mfa_token.type.hardware' => 'hardware',
                    'mfa_token.type.backup' => 'backup',
                ],
                'required' => true,
                'help' => 'mfa_token.help.token_type',
            ])
            ->add('deviceName', TextType::class, [
                'label' => 'mfa_token.field.device_name',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'mfa_token.placeholder.device_name',
                ],
                'help' => 'mfa_token.help.device_name',
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'mfa_token.field.phone_number',
                'required' => false,
                'attr' => [
                    'maxlength' => 20,
                    'placeholder' => 'mfa_token.placeholder.phone_number',
                ],
                'help' => 'mfa_token.help.phone_number',
            ])
            ->add('isActive', ChoiceType::class, [
                'label' => 'mfa_token.field.is_active',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('isPrimary', ChoiceType::class, [
                'label' => 'mfa_token.field.is_primary',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                'help' => 'mfa_token.help.is_primary',
            ])
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'mfa_token.field.expires_at',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'mfa_token.help.expires_at',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MfaToken::class,
        ]);
    }
}
