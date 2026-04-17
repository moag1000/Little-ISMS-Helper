<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Tag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CRUD form for Tag master data (WS-5 admin area).
 */
final class TagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'tags.form.name',
                'translation_domain' => 'tags',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'tags.form.type',
                'translation_domain' => 'tags',
                'choices' => [
                    'tags.type.framework' => Tag::TYPE_FRAMEWORK,
                    'tags.type.classification' => Tag::TYPE_CLASSIFICATION,
                    'tags.type.custom' => Tag::TYPE_CUSTOM,
                ],
                'choice_translation_domain' => 'tags',
            ])
            ->add('frameworkCode', TextType::class, [
                'label' => 'tags.form.framework_code',
                'translation_domain' => 'tags',
                'required' => false,
                'help' => 'tags.form.framework_code_help',
            ])
            ->add('color', ChoiceType::class, [
                'label' => 'tags.form.color',
                'translation_domain' => 'tags',
                'choices' => [
                    'tags.color.primary' => 'primary',
                    'tags.color.secondary' => 'secondary',
                    'tags.color.success' => 'success',
                    'tags.color.danger' => 'danger',
                    'tags.color.warning' => 'warning',
                    'tags.color.info' => 'info',
                    'tags.color.dark' => 'dark',
                ],
                'choice_translation_domain' => 'tags',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'tags.form.description',
                'translation_domain' => 'tags',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tag::class,
            'translation_domain' => 'tags',
        ]);
    }
}
