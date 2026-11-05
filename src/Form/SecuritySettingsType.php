<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Security Settings Form Type
 *
 * DTO form for system-wide security settings (session, password, 2FA, login).
 * Data is stored in SystemSettings entity as key-value pairs.
 */
final class SecuritySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Password Policy
            ->add('password_min_length', IntegerType::class, [
                'label' => 'admin.settings.security.password_min_length',
                'required' => true,
                'attr' => [
                    'min' => 8,
                    'max' => 128,
                ],
                'help' => 'admin.settings.security.password_min_length_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 8, max: 128),
                ],
            ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'admin',
        ]);
    }
}
