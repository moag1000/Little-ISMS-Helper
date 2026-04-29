<?php

declare(strict_types=1);

namespace App\Form;

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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form for create/edit of IdentityProvider entries in the admin module.
 *
 * Caller decides whether the global `tenant=null` choice is allowed.
 * `clientSecret` is plaintext on the form; the controller encrypts it.
 */
final class IdentityProviderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $allowGlobal = (bool) $options['allow_global'];

        $builder
            ->add('slug', TextType::class, [
                'label' => 'sso.field.slug',
                'help' => 'sso.help.slug',
            ])
            ->add('name', TextType::class, ['label' => 'sso.field.name'])
            ->add('type', ChoiceType::class, [
                'label' => 'sso.field.type',
                'choices' => [
                    'sso.type.oidc' => IdentityProvider::TYPE_OIDC,
                    'sso.type.oauth2' => IdentityProvider::TYPE_OAUTH2,
                ],
            ])
            ->add('enabled', CheckboxType::class, ['label' => 'sso.field.enabled', 'required' => false])
            ->add('clientId', TextType::class, ['label' => 'sso.field.client_id'])
            ->add('clientSecretPlain', PasswordType::class, [
                'label' => 'sso.field.client_secret',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.client_secret_edit',
            ])
            ->add('discoveryUrl', UrlType::class, [
                'label' => 'sso.field.discovery_url',
                'required' => false,
                'help' => 'sso.help.discovery_url',
            ])
            ->add('issuer', UrlType::class, ['label' => 'sso.field.issuer', 'required' => false])
            ->add('authorizationEndpoint', UrlType::class, ['label' => 'sso.field.auth_endpoint', 'required' => false])
            ->add('tokenEndpoint', UrlType::class, ['label' => 'sso.field.token_endpoint', 'required' => false])
            ->add('userinfoEndpoint', UrlType::class, ['label' => 'sso.field.userinfo_endpoint', 'required' => false])
            ->add('jwksUri', UrlType::class, ['label' => 'sso.field.jwks_uri', 'required' => false])
            ->add('scopesCsv', TextType::class, [
                'label' => 'sso.field.scopes',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.scopes',
                'data' => implode(' ', $options['scopes_initial'] ?? []),
            ])
            ->add('attributeMapJson', TextType::class, [
                'label' => 'sso.field.attribute_map',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.attribute_map',
                'data' => (string) ($options['attribute_map_json'] ?? ''),
                'attr' => ['placeholder' => '{"email":"email","given_name":"firstName","family_name":"lastName"}'],
            ])
            ->add('buttonLabel', TextType::class, ['label' => 'sso.field.button_label', 'required' => false])
            ->add('buttonIcon', TextType::class, [
                'label' => 'sso.field.button_icon',
                'required' => false,
                'help' => 'sso.help.button_icon',
            ])
            ->add('buttonColor', TextType::class, ['label' => 'sso.field.button_color', 'required' => false])
            ->add('domainBindingsCsv', TextType::class, [
                'label' => 'sso.field.domain_bindings',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.domain_bindings',
                'data' => implode(', ', $options['domain_bindings_initial'] ?? []),
            ])
            ->add('domainBindingMode', ChoiceType::class, [
                'label' => 'sso.field.domain_mode',
                'choices' => [
                    'sso.domain_mode.disabled' => IdentityProvider::DOMAIN_MODE_DISABLED,
                    'sso.domain_mode.optional' => IdentityProvider::DOMAIN_MODE_OPTIONAL,
                    'sso.domain_mode.enforce' => IdentityProvider::DOMAIN_MODE_ENFORCE,
                ],
            ])
            ->add('jitProvisioning', CheckboxType::class, ['label' => 'sso.field.jit', 'required' => false])
            ->add('autoApprove', CheckboxType::class, [
                'label' => 'sso.field.auto_approve',
                'required' => false,
                'help' => 'sso.help.auto_approve',
            ])
            ->add('defaultRole', ChoiceType::class, [
                'label' => 'sso.field.default_role',
                'choices' => [
                    'ROLE_USER' => 'ROLE_USER',
                    'ROLE_AUDITOR' => 'ROLE_AUDITOR',
                    'ROLE_MANAGER' => 'ROLE_MANAGER',
                ],
                'constraints' => [new NotBlank(), new Choice(['ROLE_USER', 'ROLE_AUDITOR', 'ROLE_MANAGER'])],
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
            'scopes_initial' => [],
            'attribute_map_json' => '',
            'domain_bindings_initial' => [],
        ]);
    }
}
