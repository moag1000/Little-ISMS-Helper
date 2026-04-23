<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\SupplierCriticalityLevel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Admin form for creating/editing SupplierCriticalityLevel records.
 */
class SupplierCriticalityLevelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            ->add('code', TextType::class, [
                'label' => 'supplier_criticality.field.code',
                'disabled' => $isEdit, // Code is immutable after creation
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 50,
                    'placeholder' => 'supplier_criticality.placeholder.code',
                ],
                'help' => 'supplier_criticality.help.code',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 50),
                    new Assert\Regex('/^[a-z0-9_]+$/', message: 'supplier_criticality.validation.code_pattern'),
                ],
            ])
            ->add('labelDe', TextType::class, [
                'label' => 'supplier_criticality.field.label_de',
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('labelEn', TextType::class, [
                'label' => 'supplier_criticality.field.label_en',
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'supplier_criticality.field.sort_order',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 999,
                ],
                'help' => 'supplier_criticality.help.sort_order',
                'constraints' => [
                    new Assert\Range(min: 0, max: 999),
                ],
            ])
            ->add('color', ChoiceType::class, [
                'label' => 'supplier_criticality.field.color',
                'required' => false,
                'placeholder' => 'supplier_criticality.placeholder.color',
                'choices' => [
                    'supplier_criticality.color.danger' => 'danger',
                    'supplier_criticality.color.warning' => 'warning',
                    'supplier_criticality.color.info' => 'info',
                    'supplier_criticality.color.secondary' => 'secondary',
                    'supplier_criticality.color.success' => 'success',
                    'supplier_criticality.color.primary' => 'primary',
                    'supplier_criticality.color.dark' => 'dark',
                ],
                'choice_translation_domain' => 'supplier_criticality',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'supplier_criticality.field.is_default',
                'required' => false,
                'help' => 'supplier_criticality.help.is_default',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'supplier_criticality.field.is_active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SupplierCriticalityLevel::class,
            'translation_domain' => 'supplier_criticality',
            'is_edit' => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
