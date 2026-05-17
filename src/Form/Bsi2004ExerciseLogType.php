<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Bsi2004ExerciseLog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * BSI-200-4 Übungs-Logbuch Form.
 *
 * Sections:
 *   1. Basis: exerciseType, bsi2004Template
 *   2. Szenario: scenarioSummary, objectives (collection of strings)
 *   3. Maßnahmen: actionsBefore, actionsDuring, actionsAfter
 *   4. Lessons Learned: lessonsLearned, improvementActions (collection)
 *   5. Bewertung: overallRating
 */
final class Bsi2004ExerciseLogType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $translationDomain = 'bsi_200_4_exercise';

        // --- Section 1: Basis ---
        $builder->add('exerciseType', ChoiceType::class, [
            'label'           => 'field.exercise_type',
            'translation_domain' => $translationDomain,
            'choices'         => array_combine(
                array_map(
                    static fn (string $t): string => 'exercise_type.' . $t,
                    Bsi2004ExerciseLog::EXERCISE_TYPES
                ),
                Bsi2004ExerciseLog::EXERCISE_TYPES
            ),
            'choice_translation_domain' => $translationDomain,
        ]);

        $builder->add('bsi2004Template', ChoiceType::class, [
            'label'           => 'field.template',
            'translation_domain' => $translationDomain,
            'choices'         => array_combine(
                array_map(
                    static fn (string $t): string => 'template.' . $t,
                    Bsi2004ExerciseLog::TEMPLATES
                ),
                Bsi2004ExerciseLog::TEMPLATES
            ),
            'choice_translation_domain' => $translationDomain,
        ]);

        // --- Section 2: Szenario ---
        $builder->add('scenarioSummary', TextareaType::class, [
            'label'              => 'field.scenario_summary',
            'translation_domain' => $translationDomain,
            'attr'               => ['rows' => 5],
        ]);

        // Objectives: stored as JSON array but edited as newline-separated textarea
        $builder->add('objectivesText', TextareaType::class, [
            'label'              => 'field.objectives',
            'translation_domain' => $translationDomain,
            'mapped'             => false,
            'required'           => false,
            'attr'               => ['rows' => 4, 'placeholder' => 'field.objectives_placeholder'],
            'help'               => 'field.objectives_help',
            'help_translation_parameters' => [],
        ]);

        // Participants: stored as JSON array but edited as comma-separated textarea
        $builder->add('participantsText', TextareaType::class, [
            'label'              => 'field.participants',
            'translation_domain' => $translationDomain,
            'mapped'             => false,
            'required'           => false,
            'attr'               => ['rows' => 3, 'placeholder' => 'field.participants_placeholder'],
        ]);

        // --- Section 3: Maßnahmen ---
        $builder->add('actionsBefore', TextareaType::class, [
            'label'              => 'section.actions_before',
            'translation_domain' => $translationDomain,
            'required'           => false,
            'attr'               => ['rows' => 4],
        ]);

        $builder->add('actionsDuring', TextareaType::class, [
            'label'              => 'section.actions_during',
            'translation_domain' => $translationDomain,
            'required'           => false,
            'attr'               => ['rows' => 4],
        ]);

        $builder->add('actionsAfter', TextareaType::class, [
            'label'              => 'section.actions_after',
            'translation_domain' => $translationDomain,
            'required'           => false,
            'attr'               => ['rows' => 4],
        ]);

        // --- Section 4: Lessons Learned ---
        $builder->add('lessonsLearned', TextareaType::class, [
            'label'              => 'field.lessons_learned',
            'translation_domain' => $translationDomain,
            'required'           => false,
            'attr'               => ['rows' => 5],
        ]);

        // ImprovementActions as a collection of sub-forms
        $builder->add('improvementActionsCollection', CollectionType::class, [
            'label'              => 'section.improvement_actions',
            'translation_domain' => $translationDomain,
            'entry_type'         => ImprovementActionType::class,
            'entry_options'      => ['label' => false],
            'allow_add'          => true,
            'allow_delete'       => true,
            'prototype'          => true,
            'mapped'             => false,
            'required'           => false,
            'by_reference'       => false,
            'attr'               => ['class' => 'improvement-actions-collection'],
        ]);

        // --- Section 5: Bewertung ---
        $builder->add('overallRating', ChoiceType::class, [
            'label'           => 'field.overall_rating',
            'translation_domain' => $translationDomain,
            'required'        => false,
            'placeholder'     => 'field.rating_placeholder',
            'choices'         => array_combine(
                array_map(
                    static fn (string $r): string => 'rating.' . $r,
                    Bsi2004ExerciseLog::RATINGS
                ),
                Bsi2004ExerciseLog::RATINGS
            ),
            'choice_translation_domain' => $translationDomain,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => Bsi2004ExerciseLog::class,
            'translation_domain' => 'bsi_200_4_exercise',
        ]);
    }
}
