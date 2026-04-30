<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
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

class BusinessContinuityPlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'bc_plans.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
                'help' => 'bc_plans.help.name',
            ])
            ->add('businessProcess', EntityType::class, [
                'label' => 'bc_plans.field.business_process',
                'class' => BusinessProcess::class,
                'choice_label' => 'name',
                'required' => true,
                'help' => 'bc_plans.help.business_process',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'bc_plans.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.description',
            ])
            ->add('planOwnerUser', EntityType::class, [
                'label' => 'bc_plans.field.plan_owner',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'bc_plans.placeholder.plan_owner_user',
                'attr' => ['class' => 'form-select'],
                'help' => 'bc_plans.help.plan_owner_user',
            ])
            ->add('planOwnerPerson', EntityType::class, [
                'label' => 'bc_plans.field.plan_owner_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'bc_plans.placeholder.plan_owner_person',
                'attr' => ['class' => 'form-select'],
                'help' => 'bc_plans.help.plan_owner_person',
            ])
            ->add('planOwnerDeputyPersons', EntityType::class, [
                'label' => 'bc_plans.field.plan_owner_deputies',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'bc_plans.help.plan_owner_deputies',
            ])
            ->add('planOwner', TextType::class, [
                'label' => 'bc_plans.field.plan_owner_legacy',
                'required' => false,
                'attr' => ['maxlength' => 100],
                'help' => 'bc_plans.help.plan_owner',
            ])
            ->add('bcTeam', TextareaType::class, [
                'label' => 'bc_plans.field.bc_team',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.bc_team',
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
                'help' => 'bc_plans.help.status',
            ])
            ->add('activationCriteria', TextareaType::class, [
                'label' => 'bc_plans.field.activation_criteria',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.activation_criteria',
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'bc_plans.field.roles_and_responsibilities',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.roles_responsibilities',
            ])
            ->add('recoveryProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.recovery_procedures',
                'required' => false,
                'attr' => ['rows' => 6],
                'help' => 'bc_plans.help.recovery_procedures',
            ])
            ->add('communicationPlan', TextareaType::class, [
                'label' => 'bc_plans.field.communication_plan',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'bc_plans.help.communication_plan',
            ])
            ->add('internalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.internal_communication',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.internal_communication',
            ])
            ->add('externalCommunication', TextareaType::class, [
                'label' => 'bc_plans.field.external_communication',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.external_communication',
            ])
            ->add('alternativeSite', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site',
            ])
            ->add('alternativeSiteAddress', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_address',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site_address',
            ])
            ->add('alternativeSiteCapacity', TextareaType::class, [
                'label' => 'bc_plans.field.alternative_site_capacity',
                'required' => false,
                'attr' => ['rows' => 2],
                'help' => 'bc_plans.help.alternative_site_capacity',
            ])
            ->add('backupProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.backup_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.backup_procedures',
            ])
            ->add('restoreProcedures', TextareaType::class, [
                'label' => 'bc_plans.field.restore_procedures',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.restore_procedures',
            ])
            ->add('version', TextType::class, [
                'label' => 'bc_plans.field.version',
                'required' => true,
                'attr' => ['maxlength' => 20],
                'help' => 'bc_plans.help.version',
            ])
            ->add('lastTested', DateType::class, [
                'label' => 'bc_plans.field.last_tested',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.last_tested',
            ])
            ->add('nextTestDate', DateType::class, [
                'label' => 'bc_plans.field.next_test_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.next_test_date',
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'bc_plans.field.last_review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.last_review_date',
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'bc_plans.field.next_review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'bc_plans.help.next_review_date',
            ])
            ->add('reviewNotes', TextareaType::class, [
                'label' => 'bc_plans.field.review_notes',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'bc_plans.help.review_notes',
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
