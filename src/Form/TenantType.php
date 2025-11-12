<?php

namespace App\Form;

use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TenantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'tenant.field.code',
                'help' => 'tenant.field.code_help',
                'attr' => [
                    'placeholder' => 'tenant.placeholder.code',
                    'maxlength' => 100,
                    'pattern' => '[a-zA-Z0-9_-]+',
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['max' => 100]),
                    new Assert\Regex([
                        'pattern' => '/^[a-zA-Z0-9_-]+$/',
                        'message' => 'tenant.validation.code_format',
                    ]),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'tenant.field.name',
                'help' => 'tenant.field.name_help',
                'attr' => [
                    'placeholder' => 'tenant.placeholder.name',
                    'maxlength' => 255,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['max' => 255]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'tenant.field.description',
                'help' => 'tenant.field.description_help',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'tenant.placeholder.description',
                ],
            ])
            ->add('azureTenantId', TextType::class, [
                'label' => 'tenant.field.azure_tenant_id',
                'help' => 'tenant.field.azure_tenant_id_help',
                'required' => false,
                'attr' => [
                    'placeholder' => 'tenant.placeholder.azure_tenant_id',
                    'maxlength' => 255,
                ],
                'constraints' => [
                    new Assert\Length(['max' => 255]),
                    new Assert\Uuid(message: 'tenant.validation.azure_tenant_id_format'),
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'tenant.field.is_active',
                'help' => 'tenant.field.is_active_help',
                'required' => false,
            ])
            ->add('settings', TextareaType::class, [
                'label' => 'tenant.field.settings',
                'help' => 'tenant.field.settings_help',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'rows' => 10,
                    'placeholder' => 'tenant.placeholder.settings',
                    'class' => 'font-monospace',
                ],
                'data' => $options['data']->getSettings() ? json_encode($options['data']->getSettings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
        ]);
    }
}
