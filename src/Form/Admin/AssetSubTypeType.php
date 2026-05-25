<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\AssetSubType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CRUD form for tenant-configurable AssetSubType (S18 B2).
 */
final class AssetSubTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('topType', ChoiceType::class, [
                'label' => 'asset_sub_type.field.top_type',
                'translation_domain' => 'asset_sub_type',
                'choices' => array_combine(
                    array_map(static fn (string $t): string => 'asset_sub_type.top_type.' . $t, AssetSubType::TOP_TYPES),
                    AssetSubType::TOP_TYPES,
                ),
                'choice_translation_domain' => 'asset_sub_type',
                'help' => 'asset_sub_type.help.top_type',
            ])
            ->add('name', TextType::class, [
                'label' => 'asset_sub_type.field.name',
                'translation_domain' => 'asset_sub_type',
                'attr' => ['maxlength' => 100],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'asset_sub_type.field.description',
                'translation_domain' => 'asset_sub_type',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'asset_sub_type.field.is_active',
                'translation_domain' => 'asset_sub_type',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssetSubType::class,
            'translation_domain' => 'asset_sub_type',
        ]);
    }
}
