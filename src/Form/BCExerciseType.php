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
                'label' => 'Exercise Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('exerciseType', ChoiceType::class, [
                'label' => 'Exercise Type',
                'choices' => [
                    'Tabletop (Discussion-based)' => 'tabletop',
                    'Walkthrough (Step-by-step)' => 'walkthrough',
                    'Simulation (Simulated incident)' => 'simulation',
                    'Full Test (Complete activation)' => 'full_test',
                    'Component Test (Specific component)' => 'component_test',
                ],
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'Scope',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('objectives', TextareaType::class, [
                'label' => 'Objectives',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('scenario', TextareaType::class, [
                'label' => 'Exercise Scenario',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('exerciseDate', DateType::class, [
                'label' => 'Exercise Date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('durationHours', IntegerType::class, [
                'label' => 'Duration (hours)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 168],
            ])
            ->add('participants', TextareaType::class, [
                'label' => 'Participants',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('facilitator', TextType::class, [
                'label' => 'Facilitator/Lead',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('observers', TextareaType::class, [
                'label' => 'Observers',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Planned' => 'planned',
                    'In Progress' => 'in_progress',
                    'Completed' => 'completed',
                    'Cancelled' => 'cancelled',
                ],
                'required' => true,
            ])
            ->add('results', TextareaType::class, [
                'label' => 'Results',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('whatWentWell', TextareaType::class, [
                'label' => 'What Went Well (WWW)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('areasForImprovement', TextareaType::class, [
                'label' => 'Areas for Improvement (AFI)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('findings', TextareaType::class, [
                'label' => 'Findings',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'Action Items',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'Lessons Learned',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('planUpdatesRequired', TextareaType::class, [
                'label' => 'Plan Updates Required',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('successRating', IntegerType::class, [
                'label' => 'Overall Success Rating (1-5)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('reportCompleted', CheckboxType::class, [
                'label' => 'Report Completed',
                'required' => false,
            ])
            ->add('reportDate', DateType::class, [
                'label' => 'Report Date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BCExercise::class,
        ]);
    }
}
