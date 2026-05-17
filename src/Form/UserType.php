<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Entity\Role;
use App\Entity\Tenant;
use App\Service\PasswordPolicyResolver;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * User Form Type
 *
 * Form for creating and editing User entities with role management.
 * Supports both Symfony security roles and custom Role entities.
 */
final class UserType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly PasswordPolicyResolver $passwordPolicyResolver,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $isProfileEdit = $options['is_profile_edit'];

        $builder
            // Basic Information
            ->add('firstName', TextType::class, [
                'label' => 'user.field.first_name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte geben Sie einen Vornamen ein.'),
                    new Assert\Length(max: 100, maxMessage: 'Der Vorname darf maximal {{ limit }} Zeichen lang sein.'),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'user.field.last_name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte geben Sie einen Nachnamen ein.'),
                    new Assert\Length(max: 100, maxMessage: 'Der Nachname darf maximal {{ limit }} Zeichen lang sein.'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'user.field.email',
                'required' => true,
                'help' => 'Wird als Benutzername verwendet',
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte geben Sie eine E-Mail-Adresse ein.'),
                    new Assert\Email(message: 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'),
                ],
            ])
            ->add('department', TextType::class, [
                'label' => 'user.field.department',
                'required' => false,
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'user.field.job_title',
                'required' => false,
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'user.field.phone_number',
                'required' => false,
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
                    new Assert\File(
                        maxSize: '1M',
                        mimeTypes: [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        mimeTypesMessage: 'file_upload.validation.mime_type_invalid',
                        maxSizeMessage: 'file_upload.validation.max_size_exceeded',
                    ),
                ],
            ])

            // Authentication
            ->add('plainPassword', PasswordType::class, [
                'label' => 'user.field.password',
                'mapped' => false,
                'required' => !$isEdit,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'spellcheck' => 'false',
                ],
                'help' => $this->translator->trans(
                    $isEdit ? 'user.help.password_change' : 'user.help.password_create',
                    ['%min%' => $this->resolvePasswordMinLength()],
                    'user',
                ),
                'constraints' => [
                    new Assert\When(
                        expression: 'value !== null and value !== ""',
                        constraints: [
                            new Assert\Length(
                                min: $this->resolvePasswordMinLength(),
                                minMessage: $this->translator->trans(
                                    'user.validation.password_min_length',
                                    [],
                                    'user',
                                ),
                            ),
                        ],
                    ),
                ],
            ])
        ;

        // Only add admin fields if not in profile edit mode
        if (!$isProfileEdit) {
            // Role descriptions for tooltips (translated)
            $roleDescriptions = [
                'ROLE_USER' => $this->translator->trans('user.role_description.user', [], 'user'),
                'ROLE_AUDITOR' => $this->translator->trans('user.role_description.auditor', [], 'user'),
                'ROLE_MANAGER' => $this->translator->trans('user.role_description.manager', [], 'user'),
                'ROLE_ADMIN' => $this->translator->trans('user.role_description.admin', [], 'user'),
                'ROLE_SUPER_ADMIN' => $this->translator->trans('user.role_description.super_admin', [], 'user'),
                // Audit V3 W2-C5 — persona-roles for dashboard gating.
                'ROLE_CISO' => $this->translator->trans('user.role_description.ciso', [], 'user'),
                'ROLE_RISK_MANAGER' => $this->translator->trans('user.role_description.risk_manager', [], 'user'),
                'ROLE_DPO' => $this->translator->trans('user.role_description.dpo', [], 'user'),
                'ROLE_COMPLIANCE_MANAGER' => $this->translator->trans('user.role_description.compliance_manager', [], 'user'),
            ];

            $rolesOptions = [
                'label' => 'user.field.system_roles',
                'choices' => [
                    'user.role.user' => 'ROLE_USER',
                    'user.role.auditor' => 'ROLE_AUDITOR',
                    'user.role.manager' => 'ROLE_MANAGER',
                    'user.role.admin' => 'ROLE_ADMIN',
                    'user.role.super_admin' => 'ROLE_SUPER_ADMIN',
                    // Audit V3 W2-C5 persona-roles
                    'user.role.ciso' => 'ROLE_CISO',
                    'user.role.risk_manager' => 'ROLE_RISK_MANAGER',
                    'user.role.dpo' => 'ROLE_DPO',
                    'user.role.compliance_manager' => 'ROLE_COMPLIANCE_MANAGER',
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_translation_domain' => 'user',
                'help' => 'user.roles_info.system_note',
                'help_translation_parameters' => [],
                'choice_attr' => function (string $choice) use ($roleDescriptions): array {
                    return [
                        'data-bs-toggle' => 'tooltip',
                        'data-bs-placement' => 'right',
                        'title' => $roleDescriptions[$choice] ?? '',
                        'class' => 'form-check-input role-checkbox',
                    ];
                },
            ];
            // Only set default for new users — omitting 'data' in edit mode lets
            // Symfony read the value from the mapped entity property instead of
            // overriding it with an explicit null, which would blank every checkbox.
            if (!$isEdit) {
                $rolesOptions['data'] = ['ROLE_USER'];
            }

            $builder
                // Roles & Permissions
                ->add('roles', ChoiceType::class, $rolesOptions)
            ->add('customRoles', EntityType::class, [
                'label' => 'user.field.custom_roles',
                'class' => Role::class,
                'choice_label' => fn(Role $role): string => $role->getName() . ' - ' . $role->getDescription(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Benutzerdefinierte Rollen mit spezifischen Berechtigungen',
            ])

            // Tenant Assignment
            ->add('tenant', EntityType::class, [
                'label' => 'user.field.tenant',
                'class' => Tenant::class,
                'choice_label' => fn(Tenant $tenant): string => $tenant->getName() . ' (' . $tenant->getCode() . ')',
                'placeholder' => 'user.placeholder.tenant',
                'required' => false,
                'help' => 'user.field.tenant_help',
                'query_builder' => fn($repository) => $repository->createQueryBuilder('t')
                    ->where('t.isActive = :active')
                    ->setParameter('active', true)
                    ->orderBy('t.name', 'ASC'),
            ])

            // Status — omit 'data' in edit mode so Symfony reads the entity value;
            // setting 'data' => null explicitly overrides the mapped property to null.
            ->add('isActive', CheckboxType::class, $isEdit ? [
                'label' => 'user.field.active',
                'required' => false,
                'help' => 'Nur aktive Benutzer können sich anmelden',
                'attr' => ['class' => 'form-check-input'],
            ] : [
                'label' => 'user.field.active',
                'required' => false,
                'data' => true,
                'help' => 'Nur aktive Benutzer können sich anmelden',
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('isVerified', CheckboxType::class, [
                'label' => 'user.field.email_verified',
                'required' => false,
                'help' => 'Gibt an, ob die E-Mail-Adresse bestätigt wurde',
                'attr' => ['class' => 'form-check-input'],
            ]);
        }

        // ISO 27001 §7.2 Competence — list of structured competency objects
        // {name, category, level, certifiedBy, certifiedAt, expiresAt}.
        // TODO(s5-json-objects): replace with CollectionType + CompetencyEntryType.
        $builder->add('competencies', TextareaType::class, [
            'label' => 'user.field.competencies',
            'required' => false,
            'attr' => ['rows' => 6, 'placeholder' => 'user.placeholder.competencies_json'],
            'help' => 'user.help.competencies_json',
        ]);
        $builder->get('competencies')->addModelTransformer(new JsonArrayTransformer());

        // Audit-S5 P-12 — Previous QM-System background (drives Norm-Bridge visibility).
        // Available in every edit mode so an admin can set this for a user during
        // onboarding without forcing the user to revisit their profile-edit screen.
        $builder->add('previousQmsBackground', ChoiceType::class, [
            'label' => 'user.field.previous_qms_background',
            'help' => 'user.help.previous_qms_background',
            'required' => false,
            'placeholder' => 'user.placeholder.previous_qms_background',
            'choices' => [
                'user.qms_background.iso_9001' => 'iso_9001',
                'user.qms_background.iso_14001' => 'iso_14001',
                'user.qms_background.other' => 'other',
                'user.qms_background.none' => 'none',
            ],
            'choice_translation_domain' => 'user',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false,
            'is_profile_edit' => false,
            'attr' => ['novalidate' => 'novalidate'], // Use HTML5 validation
            'translation_domain' => 'user',
        ]);
    }

    /**
     * Resolve the effective password minimum length via PasswordPolicyResolver.
     * Falls back to 8 when no tenant context is available (CLI / early-boot).
     */
    private function resolvePasswordMinLength(): int
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant instanceof Tenant) {
            return $this->passwordPolicyResolver->resolveFor($tenant);
        }
        return 8;
    }
}
