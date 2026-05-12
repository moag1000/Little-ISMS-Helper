<?php

declare(strict_types=1);

namespace App\Form\Step\Sso;

use App\Entity\IdentityProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 3 of the SSO wizard: confirm, test connection, set domain bindings.
 */
final class SsoTestStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'sso.field.enabled',
                'required' => false,
            ])
            ->add('buttonLabel', TextType::class, [
                'label' => 'sso.field.button_label',
                'required' => false,
            ])
            ->add('domainBindingsCsv', TextType::class, [
                'label' => 'sso.field.domain_bindings',
                'mapped' => false,
                'required' => false,
                'help' => 'sso.help.domain_bindings',
                'data' => $options['domain_bindings_initial'] !== []
                    ? implode(', ', $options['domain_bindings_initial'])
                    : '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IdentityProvider::class,
            'translation_domain' => 'sso',
            'domain_bindings_initial' => [],
        ]);
    }
}
