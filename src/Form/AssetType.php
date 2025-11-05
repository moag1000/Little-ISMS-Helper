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
                'label' => 'Asset Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('assetType', ChoiceType::class, [
                'label' => 'Asset Type',
                'choices' => [
                    'Information' => 'Information',
                    'Software' => 'Software',
                    'Hardware' => 'Hardware',
                    'Service' => 'Service',
                    'Personnel' => 'Personnel',
                    'Physical' => 'Physical',
                ],
                'required' => true,
            ])
            ->add('owner', TextType::class, [
                'label' => 'Owner',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('location', TextType::class, [
                'label' => 'Location',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('criticalityLevel', ChoiceType::class, [
                'label' => 'Criticality Level',
                'choices' => [
                    'Low' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Critical' => 'critical',
                ],
                'required' => true,
            ])
            ->add('acquisitionValue', NumberType::class, [
                'label' => 'Acquisition Value',
                'required' => false,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('currentValue', NumberType::class, [
                'label' => 'Current Value',
                'required' => false,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('confidentialityValue', IntegerType::class, [
                'label' => 'Confidentiality (1-5)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('integrityValue', IntegerType::class, [
                'label' => 'Integrity (1-5)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('availabilityValue', IntegerType::class, [
                'label' => 'Availability (1-5)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
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
