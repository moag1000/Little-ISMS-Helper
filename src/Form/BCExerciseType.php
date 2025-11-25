<?php

namespace App\Form;

use App\Entity\BCExercise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BCExerciseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'bc_exercises.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('exerciseType', ChoiceType::class, [
                'label' => 'bc_exercises.field.exercise_type',
                'choices' => [
                    'bc_exercises.exercise_type.tabletop' => 'tabletop',
                    'bc_exercises.exercise_type.walkthrough' => 'walkthrough',
                    'bc_exercises.exercise_type.simulation' => 'simulation',
                    'bc_exercises.exercise_type.full_test' => 'full_test',
                    'bc_exercises.exercise_type.component_test' => 'component_test',
                ],
                'choice_translation_domain' => 'bc_exercises',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'bc_exercises.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'bc_exercises.field.scope',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'bc_exercises.field.objectives',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('scenario', TextareaType::class, [
                'label' => 'bc_exercises.field.scenario',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('exerciseDate', DateType::class, [
                'label' => 'bc_exercises.field.exercise_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('durationHours', IntegerType::class, [
                'label' => 'bc_exercises.field.duration_hours',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 168],
            ])
            ->add('participants', TextareaType::class, [
                'label' => 'bc_exercises.field.participants',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('facilitator', TextType::class, [
                'label' => 'bc_exercises.field.facilitator',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('observers', TextareaType::class, [
                'label' => 'bc_exercises.field.observers',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'bc_exercises.field.status',
                'choices' => [
                    'bc_exercises.status.planned' => 'planned',
                    'bc_exercises.status.in_progress' => 'in_progress',
                    'bc_exercises.status.completed' => 'completed',
                    'bc_exercises.status.cancelled' => 'cancelled',
                ],
                'choice_translation_domain' => 'bc_exercises',
                'required' => true,
            ])
            ->add('results', TextareaType::class, [
                'label' => 'bc_exercises.field.results',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('whatWentWell', TextareaType::class, [
                'label' => 'bc_exercises.field.what_went_well',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('areasForImprovement', TextareaType::class, [
                'label' => 'bc_exercises.field.areas_for_improvement',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'bc_exercises.field.findings',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'bc_exercises.field.action_items',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'bc_exercises.field.lessons_learned',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('planUpdatesRequired', TextareaType::class, [
                'label' => 'bc_exercises.field.plan_updates_required',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('successRating', IntegerType::class, [
                'label' => 'bc_exercises.field.success_rating',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('reportCompleted', CheckboxType::class, [
                'label' => 'bc_exercises.field.report_completed',
                'required' => false,
            ])
            ->add('reportDate', DateType::class, [
                'label' => 'bc_exercises.field.report_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BCExercise::class,
            'translation_domain' => 'bc_exercises',
        ]);
    }
}
