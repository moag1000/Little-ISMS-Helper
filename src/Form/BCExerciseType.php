<?php

declare(strict_types=1);

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
                'help' => 'bc_exercises.help.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('exerciseType', ChoiceType::class, [
                'label' => 'bc_exercises.field.exercise_type',
                'help' => 'bc_exercises.help.exercise_type',
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
                'help' => 'bc_exercises.help.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'bc_exercises.field.scope',
                'help' => 'bc_exercises.help.scope',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'bc_exercises.field.objectives',
                'help' => 'bc_exercises.help.objectives',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('scenario', TextareaType::class, [
                'label' => 'bc_exercises.field.scenario',
                'help' => 'bc_exercises.help.scenario',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('exerciseDate', DateType::class, [
                'label' => 'bc_exercises.field.exercise_date',
                'help' => 'bc_exercises.help.exercise_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('durationHours', IntegerType::class, [
                'label' => 'bc_exercises.field.duration_hours',
                'help' => 'bc_exercises.help.duration_hours',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 168],
            ])
            ->add('participants', TextareaType::class, [
                'label' => 'bc_exercises.field.participants',
                'help' => 'bc_exercises.help.participants',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('facilitator', TextType::class, [
                'label' => 'bc_exercises.field.facilitator',
                'help' => 'bc_exercises.help.facilitator',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('observers', TextareaType::class, [
                'label' => 'bc_exercises.field.observers',
                'help' => 'bc_exercises.help.observers',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'bc_exercises.field.status',
                'help' => 'bc_exercises.help.status',
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
                'help' => 'bc_exercises.help.results',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('whatWentWell', TextareaType::class, [
                'label' => 'bc_exercises.field.what_went_well',
                'help' => 'bc_exercises.help.what_went_well',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('areasForImprovement', TextareaType::class, [
                'label' => 'bc_exercises.field.areas_for_improvement',
                'help' => 'bc_exercises.help.areas_for_improvement',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'bc_exercises.field.findings',
                'help' => 'bc_exercises.help.findings',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'bc_exercises.field.action_items',
                'help' => 'bc_exercises.help.action_items',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'bc_exercises.field.lessons_learned',
                'help' => 'bc_exercises.help.lessons_learned',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('planUpdatesRequired', TextareaType::class, [
                'label' => 'bc_exercises.field.plan_updates_required',
                'help' => 'bc_exercises.help.plan_updates_required',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('successRating', IntegerType::class, [
                'label' => 'bc_exercises.field.success_rating',
                'help' => 'bc_exercises.help.success_rating',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('reportCompleted', CheckboxType::class, [
                'label' => 'bc_exercises.field.report_completed',
                'help' => 'bc_exercises.help.report_completed',
                'required' => false,
            ])
            ->add('reportDate', DateType::class, [
                'label' => 'bc_exercises.field.report_date',
                'help' => 'bc_exercises.help.report_date',
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
