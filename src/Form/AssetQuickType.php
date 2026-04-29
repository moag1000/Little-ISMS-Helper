<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AssetQuickType extends AbstractType
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
            ->add('assetType', ChoiceType::class, [
                'label' => 'asset.field.type',
                'choices' => [
                    'asset.type.information' => 'Information',
                    'asset.type.software' => 'Software',
                    'asset.type.hardware' => 'Hardware',
                    'asset.type.service' => 'Service',
                    'asset.type.personnel' => 'Personnel',
                    'asset.type.physical' => 'Physical',
                    'asset.type.ai_agent' => 'ai_agent',
                ],
                'required' => true,
                'choice_translation_domain' => 'asset',
            ])
            ->add('ownerUser', EntityType::class, [
                'label' => 'asset.field.owner',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'asset.placeholder.owner_user',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('confidentialityValue', IntegerType::class, [
                'label' => 'asset.field.confidentiality',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
            ->add('integrityValue', IntegerType::class, [
                'label' => 'asset.field.integrity',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
            ->add('availabilityValue', IntegerType::class, [
                'label' => 'asset.field.availability',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
            'translation_domain' => 'asset',
        ]);
    }
}
