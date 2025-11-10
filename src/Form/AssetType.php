<?php

namespace App\Form;

use App\Entity\Asset;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AssetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'asset.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'asset.placeholder.name',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'asset.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.placeholder.description',
                ],
                'help' => 'asset.help.description',
            ])
            ->add('assetType', ChoiceType::class, [
                'label' => 'asset.field.type',
                'choices' => [
                    'asset.type.information' => 'Information',
                    'asset.type.software' => 'Software',
                    'asset.type.hardware' => 'Hardware',
                    'asset.type.service' => 'Service',
                    'asset.type.personnel' => 'Personnel',
                    'asset.type.physical' => 'Physical',
                ],
                'required' => true,
            ])
            ->add('owner', TextType::class, [
                'label' => 'asset.field.owner',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'asset.placeholder.owner',
                ],
                'help' => 'asset.help.owner',
            ])
            ->add('location', TextType::class, [
                'label' => 'asset.field.location',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'asset.placeholder.location',
                ],
            ])
            ->add('acquisitionValue', NumberType::class, [
                'label' => 'asset.field.acquisition_value',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00',
                ],
                'help' => 'asset.help.acquisition_value',
            ])
            ->add('currentValue', NumberType::class, [
                'label' => 'asset.field.current_value',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00',
                ],
                'help' => 'asset.help.current_value',
            ])
            ->add('confidentialityValue', IntegerType::class, [
                'label' => 'asset.field.confidentiality',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.confidentiality',
            ])
            ->add('integrityValue', IntegerType::class, [
                'label' => 'asset.field.integrity',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.integrity',
            ])
            ->add('availabilityValue', IntegerType::class, [
                'label' => 'asset.field.availability',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.availability',
            ])
            ->add('monetaryValue', NumberType::class, [
                'label' => 'asset.field.monetary_value',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00',
                ],
                'help' => 'asset.help.monetary_value',
            ])
            ->add('dataClassification', ChoiceType::class, [
                'label' => 'asset.field.data_classification',
                'choices' => [
                    'asset.classification.public' => 'public',
                    'asset.classification.internal' => 'internal',
                    'asset.classification.confidential' => 'confidential',
                    'asset.classification.restricted' => 'restricted',
                ],
                'required' => false,
                'placeholder' => 'asset.placeholder.data_classification',
                'help' => 'asset.help.data_classification',
            ])
            ->add('acceptableUsePolicy', TextareaType::class, [
                'label' => 'asset.field.acceptable_use_policy',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'asset.placeholder.acceptable_use_policy',
                ],
                'help' => 'asset.help.acceptable_use_policy',
            ])
            ->add('handlingInstructions', TextareaType::class, [
                'label' => 'asset.field.handling_instructions',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.placeholder.handling_instructions',
                ],
                'help' => 'asset.help.handling_instructions',
            ])
            ->add('returnDate', null, [
                'label' => 'asset.field.return_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'asset.help.return_date',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'asset.field.status',
                'choices' => [
                    'asset.status.active' => 'active',
                    'asset.status.inactive' => 'inactive',
                    'asset.status.in_use' => 'in_use',
                    'asset.status.returned' => 'returned',
                    'asset.status.retired' => 'retired',
                    'asset.status.disposed' => 'disposed',
                ],
                'required' => true,
                'help' => 'asset.help.status',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
        ]);
    }
}
