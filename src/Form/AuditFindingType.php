<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\InternalAudit;
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
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class AuditFindingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('audit', EntityType::class, [
                'label' => 'audit_finding.field.audit',
                'class' => InternalAudit::class,
                'choice_label' => fn(InternalAudit $a): string => ($a->getAuditNumber() ?? '') . ' — ' . ($a->getTitle() ?? ''),
                'placeholder' => 'audit_finding.placeholder.audit',
                'required' => true,
                'help' => 'audit_finding.help.audit',
            ])
            ->add('findingNumber', TextType::class, [
                'label' => 'audit_finding.field.finding_number',
                'required' => false,
                'attr' => ['maxlength' => 50, 'placeholder' => 'e.g. F-2026-001'],
                'help' => 'audit_finding.help.finding_number',
            ])
            ->add('title', TextType::class, [
                'label' => 'audit_finding.field.title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'audit_finding.field.description',
                'required' => true,
                'attr' => ['rows' => 5],
                'help' => 'audit_finding.help.description',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'audit_finding.field.type',
                'choices' => [
                    'audit_finding.type.major_nc' => AuditFinding::TYPE_MAJOR_NC,
                    'audit_finding.type.minor_nc' => AuditFinding::TYPE_MINOR_NC,
                    'audit_finding.type.observation' => AuditFinding::TYPE_OBSERVATION,
                    'audit_finding.type.opportunity' => AuditFinding::TYPE_OPPORTUNITY,
                ],
                'choice_translation_domain' => 'audits',
                'required' => true,
                'help' => 'audit_finding.help.type',
            ])
            ->add('severity', ChoiceType::class, [
                'label' => 'audit_finding.field.severity',
                'choices' => [
                    'audit_finding.severity.critical' => AuditFinding::SEVERITY_CRITICAL,
                    'audit_finding.severity.high' => AuditFinding::SEVERITY_HIGH,
                    'audit_finding.severity.medium' => AuditFinding::SEVERITY_MEDIUM,
                    'audit_finding.severity.low' => AuditFinding::SEVERITY_LOW,
                ],
                'choice_translation_domain' => 'audits',
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'audit_finding.field.status',
                'choices' => [
                    'audit_finding.status.open' => AuditFinding::STATUS_OPEN,
                    'audit_finding.status.in_progress' => AuditFinding::STATUS_IN_PROGRESS,
                    'audit_finding.status.resolved' => AuditFinding::STATUS_RESOLVED,
                    'audit_finding.status.verified' => AuditFinding::STATUS_VERIFIED,
                    'audit_finding.status.closed' => AuditFinding::STATUS_CLOSED,
                ],
                'choice_translation_domain' => 'audits',
                'required' => true,
            ])
            ->add('source', ChoiceType::class, [
                'label' => 'audits.field.finding_source',
                'required' => false,
                'placeholder' => 'audits.placeholder.finding_source',
                'choices' => [
                    'audits.finding_source.internal_audit' => 'internal_audit',
                    'audits.finding_source.external_audit' => 'external_audit',
                    'audits.finding_source.incident' => 'incident',
                    'audits.finding_source.review' => 'review',
                    'audits.finding_source.customer_complaint' => 'customer_complaint',
                    'audits.finding_source.management_review' => 'management_review',
                ],
                'choice_translation_domain' => 'audits',
                'attr' => ['class' => 'form-select'],
                'help' => 'audits.help.finding_source',
            ])
            ->add('clauseReference', TextType::class, [
                'label' => 'audit_finding.field.clause_reference',
                'required' => false,
                'attr' => ['maxlength' => 100, 'placeholder' => 'ISO 27001 A.5.1 / Clause 9.3'],
                'help' => 'audit_finding.help.clause_reference',
            ])
            ->add('evidence', TextareaType::class, [
                'label' => 'audit_finding.field.evidence',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'audit_finding.help.evidence',
            ])
            ->add('relatedControl', EntityType::class, [
                'label' => 'audit_finding.field.related_control',
                'class' => Control::class,
                'choice_label' => fn(Control $c): string => ($c->getControlId() ?? '') . ' — ' . ($c->getName() ?? ''),
                'placeholder' => 'audit_finding.placeholder.related_control',
                'required' => false,
            ])
            ->add('assignedTo', EntityType::class, [
                'label' => 'audit_finding.field.assigned_to',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder' => 'audit_finding.placeholder.assigned_to',
                'required' => false,
            ])
            ->add('assignedPerson', EntityType::class, [
                'label' => 'audit_finding.field.assigned_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'audit_finding.placeholder.assigned_person',
                'required' => false,
                'help' => 'audit_finding.help.assigned_person',
            ])
            ->add('assignedDeputyPersons', EntityType::class, [
                'label' => 'audit_finding.field.assigned_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'audit_finding.help.assigned_deputy_persons',
            ])
            ->add('reportedByPerson', EntityType::class, [
                'label' => 'audit_finding.field.reported_by_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'audit_finding.placeholder.reported_by_person',
                'required' => false,
                'help' => 'audit_finding.help.reported_by_person',
            ])
            ->add('reportedByDeputyPersons', EntityType::class, [
                'label' => 'audit_finding.field.reported_by_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'audit_finding.help.reported_by_deputy_persons',
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'audit_finding.field.due_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'audit_finding.help.due_date',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AuditFinding::class,
            'translation_domain' => 'audits',
            'constraints' => [
                new Callback([$this, 'validateAssignedSlot']),
            ],
        ]);
    }

    public function validateAssignedSlot(?AuditFinding $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getAssignedTo() === null && $entity->getAssignedPerson() === null) {
            $context->buildViolation('audits.error.owner_required_user_or_person')
                ->atPath('assignedTo')
                ->addViolation();
        }
    }
}
