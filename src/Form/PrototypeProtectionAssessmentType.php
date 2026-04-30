<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Location;
use App\Entity\Person;
use App\Entity\PrototypeProtectionAssessment;
use App\Entity\Supplier;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Prototype-Protection assessment form.
 * Mirrors the five VDA-ISA-6 Kapitel 8 sub-sections.
 */
class PrototypeProtectionAssessmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $resultChoices = [
            'prototype_protection.result.not_applicable' => PrototypeProtectionAssessment::RESULT_NOT_APPLICABLE,
            'prototype_protection.result.not_met' => PrototypeProtectionAssessment::RESULT_NOT_MET,
            'prototype_protection.result.partial' => PrototypeProtectionAssessment::RESULT_PARTIAL,
            'prototype_protection.result.met' => PrototypeProtectionAssessment::RESULT_MET,
            'prototype_protection.result.exceeded' => PrototypeProtectionAssessment::RESULT_EXCEEDED,
        ];

        $builder
            ->add('title', TextType::class, [
                'label' => 'prototype_protection.field.title',
                'attr' => ['maxlength' => 255, 'placeholder' => 'Next-gen EV platform Q3 2026'],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'prototype_protection.field.scope',
                'help' => 'prototype_protection.help.scope',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'prototype_protection.field.status',
                'choices' => [
                    'prototype_protection.status.draft' => PrototypeProtectionAssessment::STATUS_DRAFT,
                    'prototype_protection.status.in_review' => PrototypeProtectionAssessment::STATUS_IN_REVIEW,
                    'prototype_protection.status.approved' => PrototypeProtectionAssessment::STATUS_APPROVED,
                    'prototype_protection.status.rejected' => PrototypeProtectionAssessment::STATUS_REJECTED,
                    'prototype_protection.status.expired' => PrototypeProtectionAssessment::STATUS_EXPIRED,
                ],
            ])
            ->add('tisaxLevel', ChoiceType::class, [
                'label' => 'prototype_protection.field.tisax_level',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => [
                    'AL2' => 'AL2',
                    'AL3' => 'AL3',
                ],
            ])
            ->add('requiredLabels', ChoiceType::class, [
                'label' => 'prototype_protection.field.required_labels',
                'help' => 'prototype_protection.help.required_labels',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'prototype_protection.label.prototype_parts_components' => PrototypeProtectionAssessment::LABEL_PROTOTYPE_PARTS,
                    'prototype_protection.label.prototype_vehicles' => PrototypeProtectionAssessment::LABEL_PROTOTYPE_VEHICLES,
                    'prototype_protection.label.test_vehicles' => PrototypeProtectionAssessment::LABEL_TEST_VEHICLES,
                    'prototype_protection.label.events_and_shoots' => PrototypeProtectionAssessment::LABEL_EVENTS_AND_SHOOTS,
                ],
            ])
            ->add('supplier', EntityType::class, [
                'label' => 'prototype_protection.field.supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
            ])
            ->add('location', EntityType::class, [
                'label' => 'prototype_protection.field.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
            ])
            ->add('assessor', EntityType::class, [
                'label' => 'prototype_protection.field.assessor',
                'class' => User::class,
                'choice_label' => fn(User $u): string => (string) ($u->getFullName() ?: $u->getEmail()),
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
            ])
            ->add('assessorPerson', EntityType::class, [
                'label' => 'prototype_protection.field.assessor_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'prototype_protection.placeholder.assessor_person',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'prototype_protection.help.assessor_person',
            ])
            ->add('assessorDeputyPersons', EntityType::class, [
                'label' => 'prototype_protection.field.assessor_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'prototype_protection.help.assessor_deputy_persons',
            ])
            ->add('assessmentDate', DateType::class, [
                'label' => 'prototype_protection.field.assessment_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextAssessmentDue', DateType::class, [
                'label' => 'prototype_protection.field.next_assessment_due',
                'widget' => 'single_text',
                'required' => false,
            ]);

        foreach (PrototypeProtectionAssessment::SECTIONS as $section) {
            $resultField = $section . 'Result';
            $notesField = $section . 'Notes';
            if ($section === 'trial_operation') {
                $resultField = 'trialOperationResult';
                $notesField = 'trialOperationNotes';
            }
            $builder
                ->add($resultField, ChoiceType::class, [
                    'label' => 'prototype_protection.section.' . $section . '.result',
                    'required' => false,
                    'placeholder' => 'prototype_protection.value.na',
                    'choices' => $resultChoices,
                ])
                ->add($notesField, TextareaType::class, [
                    'label' => 'prototype_protection.section.' . $section . '.notes',
                    'required' => false,
                    'attr' => ['rows' => 3],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrototypeProtectionAssessment::class,
            'translation_domain' => 'prototype_protection',
        ]);
    }
}
