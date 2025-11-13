<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User Form Type
 *
 * Form for creating and editing User entities with role management.
 * Supports both Symfony security roles and custom Role entities.
 */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            // Basic Information
            ->add('firstName', TextType::class, [
                'label' => 'user.field.first_name',
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Vornamen ein.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Vorname darf maximal {{ limit }} Zeichen lang sein.',
                    ]),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'user.field.last_name',
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Nachnamen ein.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Nachname darf maximal {{ limit }} Zeichen lang sein.',
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'user.field.email',
                'required' => true,
                'attr' => ['class' => 'form-control'],
                'help' => 'Wird als Benutzername verwendet',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine E-Mail-Adresse ein.']),
                    new Assert\Email(['message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.']),
                ],
            ])
            ->add('department', TextType::class, [
                'label' => 'user.field.department',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'user.field.job_title',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'user.field.phone_number',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('avatarFile', FileType::class, [
                'label' => 'user.field.avatar',
                'help' => 'user.field.avatar_help',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/gif,image/webp',
                ],
                'constraints' => [
                    new Assert\File([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'user.validation.avatar_format',
                    ]),
                ],
            ])

            // Authentication
            ->add('plainPassword', PasswordType::class, [
                'label' => 'user.field.password',
                'mapped' => false,
                'required' => !$isEdit,
                'attr' => ['class' => 'form-control'],
                'help' => $isEdit
                    ? 'Leer lassen, um Passwort unverändert zu lassen'
                    : 'Optional für lokale Authentifizierung. Leer lassen für Azure-Authentifizierung.',
                'constraints' => $isEdit ? [] : [
                    new Assert\Length([
                        'min' => 8,
                        'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                    ]),
                ],
            ])

            // Roles & Permissions
            ->add('roles', ChoiceType::class, [
                'label' => 'user.field.system_roles',
                'choices' => [
                    'ROLE_USER - Standard Benutzer' => 'ROLE_USER',
                    'ROLE_AUDITOR - Auditor (Leserechte)' => 'ROLE_AUDITOR',
                    'ROLE_MANAGER - Manager (Erweiterte Rechte)' => 'ROLE_MANAGER',
                    'ROLE_ADMIN - Administrator (Alle Rechte)' => 'ROLE_ADMIN',
                    'ROLE_SUPER_ADMIN - Super Administrator' => 'ROLE_SUPER_ADMIN',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'data' => $isEdit ? null : ['ROLE_USER'], // Default for new users
                'help' => 'Systemrollen definieren grundlegende Zugriffsrechte',
            ])
            ->add('customRoles', EntityType::class, [
                'label' => 'user.field.custom_roles',
                'class' => Role::class,
                'choice_label' => function (Role $role) {
                    return $role->getName() . ' - ' . $role->getDescription();
                },
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Benutzerdefinierte Rollen mit spezifischen Berechtigungen',
            ])

            // Status
            ->add('isActive', CheckboxType::class, [
                'label' => 'user.field.active',
                'required' => false,
                'data' => $isEdit ? null : true, // Default active for new users
                'help' => 'Nur aktive Benutzer können sich anmelden',
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('isVerified', CheckboxType::class, [
                'label' => 'user.field.email_verified',
                'required' => false,
                'help' => 'Gibt an, ob die E-Mail-Adresse bestätigt wurde',
                'attr' => ['class' => 'form-check-input'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false,
            'attr' => ['novalidate' => 'novalidate'], // Use HTML5 validation
        ]);
    }
}
