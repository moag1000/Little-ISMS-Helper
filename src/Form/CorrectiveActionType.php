<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CorrectiveActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('finding', EntityType::class, [
                'label' => 'corrective_action.field.finding',
                'class' => AuditFinding::class,
                'choice_label' => fn(AuditFinding $f): string => ($f->getFindingNumber() ?? '#' . $f->getId()) . ' — ' . ($f->getTitle() ?? ''),
                'placeholder' => 'corrective_action.placeholder.finding',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'disabled' => $options['finding_locked'],
            ])
            ->add('title', TextType::class, [
                'label' => 'corrective_action.field.title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'corrective_action.field.description',
                'required' => true,
                'attr' => ['rows' => 4],
                'help' => 'corrective_action.help.description',
            ])
            ->add('rootCauseAnalysis', TextareaType::class, [
                'label' => 'corrective_action.field.root_cause_analysis',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'corrective_action.help.root_cause_analysis',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'corrective_action.field.status',
                'choices' => [
                    'corrective_action.status.planned' => CorrectiveAction::STATUS_PLANNED,
                    'corrective_action.status.in_progress' => CorrectiveAction::STATUS_IN_PROGRESS,
                    'corrective_action.status.completed' => CorrectiveAction::STATUS_COMPLETED,
                    'corrective_action.status.verified_effective' => CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
                    'corrective_action.status.verified_ineffective' => CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
                ],
                'choice_translation_domain' => 'audits',
                'required' => true,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('responsiblePersonUser', EntityType::class, [
                'label' => 'corrective_action.field.responsible_person_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder' => 'corrective_action.placeholder.responsible_person_user',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('responsiblePerson', EntityType::class, [
                'label' => 'corrective_action.field.responsible_person',
                'class' => Person::class,
                'choice_label' => 'fullName',
                'placeholder' => 'corrective_action.placeholder.responsible_person',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('responsibleDeputyPersons', EntityType::class, [
                'label' => 'corrective_action.field.responsible_deputy_persons',
                'class' => Person::class,
                'choice_label' => 'fullName',
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('plannedCompletionDate', DateType::class, [
                'label' => 'corrective_action.field.planned_completion_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('actualCompletionDate', DateType::class, [
                'label' => 'corrective_action.field.actual_completion_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('effectivenessReviewDate', DateType::class, [
                'label' => 'corrective_action.field.effectiveness_review_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'corrective_action.help.effectiveness_review_date',
            ])
            ->add('effectivenessNotes', TextareaType::class, [
                'label' => 'corrective_action.field.effectiveness_notes',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'corrective_action.help.effectiveness_notes',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CorrectiveAction::class,
            'translation_domain' => 'audits',
            'finding_locked' => false,
        ]);
        $resolver->setAllowedTypes('finding_locked', 'bool');
    }
}
