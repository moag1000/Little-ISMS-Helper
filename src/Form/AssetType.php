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
                'required' => false,
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
        ]);
    }
}
