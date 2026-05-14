<?php

declare(strict_types=1);

namespace App\Form\Step\Sso;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Step 2 of the SSO wizard: discovery URL, credentials, basic config.
 */
final class SsoDiscoveryStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $allowGlobal = (bool) $options['allow_global'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'sso.field.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('slug', TextType::class, [
                'label' => 'sso.field.slug',
                'help' => 'sso.help.slug',
                'constraints' => [new NotBlank()],
            ])
            ->add('discoveryUrl', UrlType::class, [
                'label' => 'sso.field.discovery_url',
                'required' => false,
                'help' => 'sso.help.discovery_url',
                'attr' => [
                    'data-action' => 'blur->sso-wizard#validateDiscovery',
                    'data-sso-wizard-target' => 'discoveryUrl',
                ],
                'constraints' => [
                    new \Symfony\Component\Validator\Constraints\Url(protocols: ['https'], requireTld: false),
                    new \App\Validator\Constraint\NoInternalIp(),
                ],
            ])
            ->add('clientId', TextType::class, [
                'label' => 'sso.field.client_id',
                'constraints' => [new NotBlank()],
            ])
            ->add('clientSecretPlain', PasswordType::class, [
                'label' => 'sso.field.client_secret',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.client_secret_edit',
            ])
            ->add('defaultFallbackRole', ChoiceType::class, [
                'label' => 'sso.field.default_fallback_role',
                'help' => 'sso.help.default_fallback_role',
                'choices' => [
                    'ROLE_USER' => 'ROLE_USER',
                    'ROLE_AUDITOR' => 'ROLE_AUDITOR',
                    'ROLE_MANAGER' => 'ROLE_MANAGER',
                ],
                'required' => false,
            ])
            ->add('mfaInheritance', ChoiceType::class, [
                'label' => 'sso.field.mfa_inheritance',
                'help' => 'sso.help.mfa_inheritance',
                'choices' => [
                    'sso.mfa_inheritance.required' => IdentityProvider::MFA_REQUIRED,
                    'sso.mfa_inheritance.optional' => IdentityProvider::MFA_OPTIONAL,
                    'sso.mfa_inheritance.disabled' => IdentityProvider::MFA_DISABLED,
                ],
                'required' => false,
            ])
            ->add('jitProvisioning', CheckboxType::class, ['label' => 'sso.field.jit', 'required' => false])
            ->add('autoApprove', CheckboxType::class, [
                'label' => 'sso.field.auto_approve',
                'required' => false,
                'help' => 'sso.help.auto_approve',
            ]);

        if ($allowGlobal) {
            $builder->add('tenant', EntityType::class, [
                'label' => 'sso.field.scope',
                'class' => Tenant::class,
                'placeholder' => 'sso.scope.global',
                'required' => false,
                'query_builder' => fn (TenantRepository $r) => $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'),
                'help' => 'sso.help.scope',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IdentityProvider::class,
            'translation_domain' => 'sso',
            'allow_global' => false,
        ]);
    }
}
