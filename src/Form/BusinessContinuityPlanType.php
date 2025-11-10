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
                'label' => 'business_continuity_plan.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('businessProcess', EntityType::class, [
                'label' => 'business_continuity_plan.field.business_process',
                'class' => BusinessProcess::class,
                'choice_label' => 'name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'business_continuity_plan.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('planOwner', TextType::class, [
                'label' => 'business_continuity_plan.field.plan_owner',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('bcTeam', TextareaType::class, [
                'label' => 'business_continuity_plan.field.bc_team',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'business_continuity_plan.field.status',
                'choices' => [
                    'Draft' => 'draft',
                    'Under Review' => 'under_review',
                    'Active' => 'active',
                    'Archived' => 'archived',
                ],
                'required' => true,
            ])
            ->add('activationCriteria', TextareaType::class, [
                'label' => 'business_continuity_plan.field.activation_criteria',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'business_continuity_plan.field.roles_and_responsibilities',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('recoveryProcedures', TextareaType::class, [
                'label' => 'business_continuity_plan.field.recovery_procedures',
                'required' => true,
                'attr' => ['rows' => 6],
            ])
            ->add('communicationPlan', TextareaType::class, [
                'label' => 'business_continuity_plan.field.communication_plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('internalCommunication', TextareaType::class, [
                'label' => 'business_continuity_plan.field.internal_communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('externalCommunication', TextareaType::class, [
                'label' => 'business_continuity_plan.field.external_communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('alternativeSite', TextareaType::class, [
                'label' => 'business_continuity_plan.field.alternative_site',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteAddress', TextareaType::class, [
                'label' => 'business_continuity_plan.field.alternative_site_address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteCapacity', TextareaType::class, [
                'label' => 'business_continuity_plan.field.alternative_site_capacity',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('backupProcedures', TextareaType::class, [
                'label' => 'business_continuity_plan.field.backup_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('restoreProcedures', TextareaType::class, [
                'label' => 'business_continuity_plan.field.restore_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('version', TextType::class, [
                'label' => 'business_continuity_plan.field.version',
                'required' => true,
                'attr' => ['maxlength' => 20],
            ])
            ->add('lastTested', DateType::class, [
                'label' => 'business_continuity_plan.field.last_tested',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextTestDate', DateType::class, [
                'label' => 'business_continuity_plan.field.next_test_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'business_continuity_plan.field.last_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'business_continuity_plan.field.next_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('reviewNotes', TextareaType::class, [
                'label' => 'business_continuity_plan.field.review_notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessContinuityPlan::class,
        ]);
    }
}
