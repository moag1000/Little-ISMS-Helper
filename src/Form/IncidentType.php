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
                'label' => 'Incident Title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 5],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'choices' => [
                    'Data Breach' => 'data_breach',
                    'Security Incident' => 'security_incident',
                    'System Outage' => 'system_outage',
                    'Compliance Violation' => 'compliance_violation',
                    'Physical Security' => 'physical_security',
                    'Other' => 'other',
                ],
                'required' => true,
            ])
            ->add('severity', ChoiceType::class, [
                'label' => 'Severity',
                'choices' => [
                    'Low' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Critical' => 'critical',
                ],
                'required' => true,
            ])
            ->add('reportedDate', DateTimeType::class, [
                'label' => 'Reported Date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('reportedBy', TextType::class, [
                'label' => 'Reported By',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('detectedDate', DateTimeType::class, [
                'label' => 'Detected Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Reported' => 'reported',
                    'In Investigation' => 'in_investigation',
                    'In Resolution' => 'in_resolution',
                    'Resolved' => 'resolved',
                    'Closed' => 'closed',
                ],
                'required' => true,
            ])
            ->add('affectedSystems', TextType::class, [
                'label' => 'Affected Systems',
                'required' => false,
                'attr' => ['maxlength' => 255],
            ])
            ->add('rootCause', TextareaType::class, [
                'label' => 'Root Cause',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('correctiveActions', TextareaType::class, [
                'label' => 'Corrective Actions',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'Lessons Learned',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('closedDate', DateTimeType::class, [
                'label' => 'Closed Date',
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
