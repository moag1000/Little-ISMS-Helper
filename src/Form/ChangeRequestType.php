<?php

namespace App\Form;

use App\Entity\ChangeRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChangeRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('changeNumber', TextType::class, [
                'label' => 'field.change_number',
                'required' => true,
                'attr' => ['maxlength' => 50],
            ])
            ->add('title', TextType::class, [
                'label' => 'field.title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('changeType', ChoiceType::class, [
                'label' => 'field.change_type',
                'choices' => [
                    'change_type.isms_policy' => 'isms_policy',
                    'change_type.isms_scope' => 'isms_scope',
                    'change_type.control' => 'control',
                    'change_type.asset' => 'asset',
                    'change_type.process' => 'process',
                    'change_type.technology' => 'technology',
                    'change_type.supplier' => 'supplier',
                    'change_type.organizational' => 'organizational',
                    'change_type.other' => 'other',
                ],
                'required' => true,
                    'choice_translation_domain' => 'change_requests',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'field.description',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'field.justification',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('requestedBy', TextType::class, [
                'label' => 'field.requested_by',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('requestedDate', DateType::class, [
                'label' => 'field.requested_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'field.priority',
                'choices' => [
                    'priority.critical' => 'critical',
                    'priority.high' => 'high',
                    'priority.medium' => 'medium',
                    'priority.low' => 'low',
                ],
                'required' => true,
                    'choice_translation_domain' => 'change_requests',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'field.status',
                'choices' => [
                    'status.draft' => 'draft',
                    'status.submitted' => 'submitted',
                    'status.under_review' => 'under_review',
                    'status.approved' => 'approved',
                    'status.rejected' => 'rejected',
                    'status.scheduled' => 'scheduled',
                    'status.implemented' => 'implemented',
                    'status.verified' => 'verified',
                    'status.closed' => 'closed',
                    'status.cancelled' => 'cancelled',
                ],
                'required' => true,
                    'choice_translation_domain' => 'change_requests',
            ])
            ->add('ismsImpact', TextareaType::class, [
                'label' => 'field.isms_impact',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('riskAssessment', TextareaType::class, [
                'label' => 'field.risk_assessment',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('implementationPlan', TextareaType::class, [
                'label' => 'field.implementation_plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('rollbackPlan', TextareaType::class, [
                'label' => 'field.rollback_plan',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('testingRequirements', TextareaType::class, [
                'label' => 'field.testing_requirements',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('plannedImplementationDate', DateType::class, [
                'label' => 'field.planned_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('actualImplementationDate', DateType::class, [
                'label' => 'field.actual_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvedBy', TextType::class, [
                'label' => 'field.approved_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('approvedDate', DateType::class, [
                'label' => 'field.approved_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvalComments', TextareaType::class, [
                'label' => 'field.approval_comments',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('implementedBy', TextType::class, [
                'label' => 'field.implemented_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'field.implementation_notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('verifiedBy', TextType::class, [
                'label' => 'field.verified_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('verifiedDate', DateType::class, [
                'label' => 'field.verified_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('verificationResults', TextareaType::class, [
                'label' => 'field.verification_results',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('closedDate', DateType::class, [
                'label' => 'field.closed_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('closureNotes', TextareaType::class, [
                'label' => 'field.closure_notes',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangeRequest::class,
            'translation_domain' => 'change_requests',
        ]);
    }
}
