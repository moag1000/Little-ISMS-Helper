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
                'label' => 'Plan Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('businessProcess', EntityType::class, [
                'label' => 'Business Process',
                'class' => BusinessProcess::class,
                'choice_label' => 'name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('planOwner', TextType::class, [
                'label' => 'Plan Owner',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('bcTeam', TextareaType::class, [
                'label' => 'BC Team Members',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Draft' => 'draft',
                    'Under Review' => 'under_review',
                    'Active' => 'active',
                    'Archived' => 'archived',
                ],
                'required' => true,
            ])
            ->add('activationCriteria', TextareaType::class, [
                'label' => 'Activation Criteria',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'Roles and Responsibilities',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('recoveryProcedures', TextareaType::class, [
                'label' => 'Recovery Procedures',
                'required' => true,
                'attr' => ['rows' => 6],
            ])
            ->add('communicationPlan', TextareaType::class, [
                'label' => 'Communication Plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('internalCommunication', TextareaType::class, [
                'label' => 'Internal Communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('externalCommunication', TextareaType::class, [
                'label' => 'External Communication',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('alternativeSite', TextareaType::class, [
                'label' => 'Alternative Site',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteAddress', TextareaType::class, [
                'label' => 'Alternative Site Address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('alternativeSiteCapacity', TextareaType::class, [
                'label' => 'Alternative Site Capacity',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('backupProcedures', TextareaType::class, [
                'label' => 'Backup Procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('restoreProcedures', TextareaType::class, [
                'label' => 'Restore Procedures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('version', TextType::class, [
                'label' => 'Version',
                'required' => true,
                'attr' => ['maxlength' => 20],
            ])
            ->add('lastTested', DateType::class, [
                'label' => 'Last Tested',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextTestDate', DateType::class, [
                'label' => 'Next Test Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'Last Review Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'Next Review Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('reviewNotes', TextareaType::class, [
                'label' => 'Review Notes',
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
