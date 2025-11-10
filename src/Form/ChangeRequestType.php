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
                'label' => 'change_request.field.change_number',
                'required' => true,
                'attr' => ['maxlength' => 50],
            ])
            ->add('title', TextType::class, [
                'label' => 'change_request.field.title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('changeType', ChoiceType::class, [
                'label' => 'change_request.field.change_type',
                'choices' => [
                    'ISMS Policy' => 'isms_policy',
                    'ISMS Scope' => 'isms_scope',
                    'Control' => 'control',
                    'Asset' => 'asset',
                    'Process' => 'process',
                    'Technology' => 'technology',
                    'Supplier' => 'supplier',
                    'Organizational' => 'organizational',
                    'Other' => 'other',
                ],
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'change_request.field.description',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'change_request.field.justification',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('requestedBy', TextType::class, [
                'label' => 'change_request.field.requested_by',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('requestedDate', DateType::class, [
                'label' => 'change_request.field.requested_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'change_request.field.priority',
                'choices' => [
                    'Critical' => 'critical',
                    'High' => 'high',
                    'Medium' => 'medium',
                    'Low' => 'low',
                ],
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'change_request.field.status',
                'choices' => [
                    'Draft' => 'draft',
                    'Submitted' => 'submitted',
                    'Under Review' => 'under_review',
                    'Approved' => 'approved',
                    'Rejected' => 'rejected',
                    'Scheduled' => 'scheduled',
                    'Implemented' => 'implemented',
                    'Verified' => 'verified',
                    'Closed' => 'closed',
                    'Cancelled' => 'cancelled',
                ],
                'required' => true,
            ])
            ->add('ismsImpact', TextareaType::class, [
                'label' => 'change_request.field.isms_impact',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('riskAssessment', TextareaType::class, [
                'label' => 'change_request.field.risk_assessment',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('implementationPlan', TextareaType::class, [
                'label' => 'change_request.field.implementation_plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('rollbackPlan', TextareaType::class, [
                'label' => 'change_request.field.rollback_plan',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('testingRequirements', TextareaType::class, [
                'label' => 'change_request.field.testing_requirements',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('plannedImplementationDate', DateType::class, [
                'label' => 'change_request.field.planned_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('actualImplementationDate', DateType::class, [
                'label' => 'change_request.field.actual_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvedBy', TextType::class, [
                'label' => 'change_request.field.approved_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('approvedDate', DateType::class, [
                'label' => 'change_request.field.approved_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvalComments', TextareaType::class, [
                'label' => 'change_request.field.approval_comments',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('implementedBy', TextType::class, [
                'label' => 'change_request.field.implemented_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'change_request.field.implementation_notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('verifiedBy', TextType::class, [
                'label' => 'change_request.field.verified_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('verifiedDate', DateType::class, [
                'label' => 'change_request.field.verified_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('verificationResults', TextareaType::class, [
                'label' => 'change_request.field.verification_results',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('closedDate', DateType::class, [
                'label' => 'change_request.field.closed_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('closureNotes', TextareaType::class, [
                'label' => 'change_request.field.closure_notes',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangeRequest::class,
        ]);
    }
}
