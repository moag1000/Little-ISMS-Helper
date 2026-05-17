<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sub-form for a single improvement action entry.
 * Maps to the JSON structure: {description, owner_user_id?, due_date?, completed?}
 */
final class ImprovementActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('description', TextType::class, [
                'label'              => 'field.improvement_action_description',
                'translation_domain' => 'bsi_200_4_exercise',
                'attr'               => ['class' => 'form-control'],
            ])
            ->add('owner_user_id', IntegerType::class, [
                'label'              => 'field.improvement_action_owner',
                'translation_domain' => 'bsi_200_4_exercise',
                'required'           => false,
                'attr'               => ['class' => 'form-control', 'placeholder' => 'field.owner_user_id_placeholder'],
            ])
            ->add('due_date', TextType::class, [
                'label'              => 'field.improvement_action_due_date',
                'translation_domain' => 'bsi_200_4_exercise',
                'required'           => false,
                'attr'               => ['class' => 'form-control', 'type' => 'date'],
            ])
            ->add('completed', CheckboxType::class, [
                'label'              => 'field.improvement_action_completed',
                'translation_domain' => 'bsi_200_4_exercise',
                'required'           => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'translation_domain' => 'bsi_200_4_exercise',
        ]);
    }
}
