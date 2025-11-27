<?php

namespace App\Form;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BusinessContinuityPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'bc_plans.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('businessProcess', EntityType::class, [
                'label' => 'bc_plans.field.business_process',
                'class' => BusinessProcess::class,
                'choice_label' => 'name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'bc_plans.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('planOwner', TextType::class, [
                'label' => 'bc_plans.field.plan_owner',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('bcTeam', TextareaType::class, [
                'label' => 'bc_plans.field.bc_team',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'bc_plans.field.status',
                'choices' => [
                    'bc_plans.status.draft' => 'draft',
                    'bc_plans.status.under_review' => 'under_review',
                    'bc_plans.status.active' => 'active',
                    'bc_plans.status.archived' => 'archived',
                ],
                'choice_translation_domain' => 'bc_plans',
                'required' => true,
            ])
            ->add('activationCriteria', TextareaType::class, [
                'label' => 'bc_plans.field.activation_criteria',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'bc_plans.field.roles_and_responsibilities',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('recoveryProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.recovery_procedures',
                'required' => true,
                'attr' => ['rows' => 6],
            ])
            ->add('communicationPlan', TextareaType::class, [
                'label' => 'bc_plans.field.communication_plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('internalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.internal_communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('externalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.external_communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('alternativeSite', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteAddress', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteCapacity', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_capacity',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('backupProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.backup_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('restoreProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.restore_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('version', TextType::class, [
                'label' => 'bc_plans.field.version',
                'required' => true,
                'attr' => ['maxlength' => 20],
            ])
            ->add('lastTested', DateType::class, [
                'label' => 'bc_plans.field.last_tested',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextTestDate', DateType::class, [
                'label' => 'bc_plans.field.next_test_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'bc_plans.field.last_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'bc_plans.field.next_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('reviewNotes', TextareaType::class, [
                'label' => 'bc_plans.field.review_notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessContinuityPlan::class,
            'translation_domain' => 'bc_plans',
            'label_translation_parameters' => [
                '%business_process%' => '{{ businessProcess.name }}',
            ],
            'attr' => [
                'novalidate' => 'novalidate',
            ],
            'empty_data' => 'new'
        ]);
    }
}
