<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Location;
use App\Entity\Person;
use App\Entity\PrototypeProtectionAssessment;
use App\Entity\Supplier;
use App\Entity\User;
use App\Enum\PrototypeProtectionAssessmentStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Form\SectionMapInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Prototype-Protection assessment form.
 * Mirrors the five VDA-ISA-6 Kapitel 8 sub-sections.
 */
final class PrototypeProtectionAssessmentType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'overview'     => ['title', 'scope', 'status', 'tisaxLevel', 'requiredLabels'],
            'classification'=> ['supplier', 'location'],
            'audit_metadata'=> ['assessor', 'assessorPerson', 'assessorDeputyPersons', 'assessmentDate', 'nextAssessmentDue'],
            'physical'     => ['physicalResult', 'physicalNotes'],
            'organisation' => ['organisationResult', 'organisationNotes'],
            'handling'     => ['handlingResult', 'handlingNotes'],
            'transport'    => ['trialOperationResult', 'trialOperationNotes'],
            'photography'  => ['eventsResult', 'eventsNotes'],
        ];
    }

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
            // ── Status field is READ-ONLY (Lifecycle-bypass fix, Sprint Y.5) ──
            // Owned by `prototype_protection_assessment_lifecycle`. TISAX
            // VDA-ISA 6.0 4-eyes auf `approve`. Transitions via
            // LifecycleService::transition() only.
            ->add('status', ChoiceType::class, [
                'label' => 'prototype_protection.field.status',
                'help' => 'prototype_protection.help.status_readonly',
                'choices' => [
                    'prototype_protection.status.draft' => PrototypeProtectionAssessmentStatus::Draft->value,
                    'prototype_protection.status.in_review' => PrototypeProtectionAssessmentStatus::InReview->value,
                    'prototype_protection.status.approved' => PrototypeProtectionAssessmentStatus::Approved->value,
                    'prototype_protection.status.rejected' => PrototypeProtectionAssessmentStatus::Rejected->value,
                    'prototype_protection.status.expired' => PrototypeProtectionAssessmentStatus::Expired->value,
                ],
                'required' => false,
                'disabled' => true,
            ])
            // @no-module-gate-required: PrototypeProtectionAssessment form is TISAX-scoped
            //   (only rendered behind prototype_protection module).
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

        // VDA-ISA 6 Kapitel 8 sub-sections — explicit fields for static analysis compatibility
        $builder
            ->add('physicalResult', ChoiceType::class, [
                'label' => 'prototype_protection.section.physical.result',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => $resultChoices,
            ])
            ->add('physicalNotes', TextareaType::class, [
                'label' => 'prototype_protection.section.physical.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('organisationResult', ChoiceType::class, [
                'label' => 'prototype_protection.section.organisation.result',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => $resultChoices,
            ])
            ->add('organisationNotes', TextareaType::class, [
                'label' => 'prototype_protection.section.organisation.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('handlingResult', ChoiceType::class, [
                'label' => 'prototype_protection.section.handling.result',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => $resultChoices,
            ])
            ->add('handlingNotes', TextareaType::class, [
                'label' => 'prototype_protection.section.handling.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('trialOperationResult', ChoiceType::class, [
                'label' => 'prototype_protection.section.trial_operation.result',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => $resultChoices,
            ])
            ->add('trialOperationNotes', TextareaType::class, [
                'label' => 'prototype_protection.section.trial_operation.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('eventsResult', ChoiceType::class, [
                'label' => 'prototype_protection.section.events.result',
                'required' => false,
                'placeholder' => 'prototype_protection.value.na',
                'choices' => $resultChoices,
            ])
            ->add('eventsNotes', TextareaType::class, [
                'label' => 'prototype_protection.section.events.notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PrototypeProtectionAssessment::class,
            'translation_domain' => 'prototype_protection',
            'constraints' => [
                new Callback([$this, 'validateAssessorSlot']),
            ],
        ]);
    }

    public function validateAssessorSlot(?PrototypeProtectionAssessment $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getAssessor() === null && $entity->getAssessorPerson() === null) {
            $context->buildViolation('prototype_protection.error.owner_required_user_or_person')
                ->atPath('assessor')
                ->addViolation();
        }
    }
}
