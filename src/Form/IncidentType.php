<?php

namespace App\Form;

use App\Entity\Incident;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IncidentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'incident.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'incident.placeholder.title',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'incident.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'incident.placeholder.description',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'incident.field.category',
                'choices' => [
                    'incident.category.data_breach' => 'data_breach',
                    'incident.category.security_incident' => 'security_incident',
                    'incident.category.system_outage' => 'system_outage',
                    'incident.category.compliance_violation' => 'compliance_violation',
                    'incident.category.physical_security' => 'physical_security',
                    'incident.category.other' => 'other',
                ],
                'required' => true,
            ])
            ->add('severity', ChoiceType::class, [
                'label' => 'incident.field.severity',
                'choices' => [
                    'incident.severity.low' => 'low',
                    'incident.severity.medium' => 'medium',
                    'incident.severity.high' => 'high',
                    'incident.severity.critical' => 'critical',
                ],
                'required' => true,
                'help' => 'incident.help.severity',
            ])
            ->add('detectedAt', DateTimeType::class, [
                'label' => 'incident.field.detected_at',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'incident.field.occurred_at',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('reportedBy', TextType::class, [
                'label' => 'incident.field.reported_by',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'incident.placeholder.reported_by',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'incident.field.status',
                'choices' => [
                    'incident.status.reported' => 'reported',
                    'incident.status.in_investigation' => 'in_investigation',
                    'incident.status.in_resolution' => 'in_resolution',
                    'incident.status.resolved' => 'resolved',
                    'incident.status.closed' => 'closed',
                ],
                'required' => true,
            ])
            ->add('affectedSystems', TextareaType::class, [
                'label' => 'incident.field.affected_systems',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.affected_systems',
                ],
                'help' => 'incident.help.affected_systems',
            ])
            ->add('rootCause', TextareaType::class, [
                'label' => 'incident.field.root_cause',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.root_cause',
                ],
            ])
            ->add('correctiveActions', TextareaType::class, [
                'label' => 'incident.field.corrective_actions',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.corrective_actions',
                ],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'incident.field.lessons_learned',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.lessons_learned',
                ],
            ])
            ->add('closedDate', DateTimeType::class, [
                'label' => 'incident.field.closed_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Incident::class,
        ]);
    }
}
