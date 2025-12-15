<?php

namespace App\Form;

use App\Entity\BusinessContinuityPlan;
use App\Entity\CrisisTeam;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CrisisTeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('teamName', TextType::class, [
                'label' => 'crisis_team.field.team_name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'crisis_team.placeholder.team_name',
                ],
                'help' => 'crisis_team.help.team_name',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'crisis_team.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'crisis_team.placeholder.description',
                ],
            ])
            ->add('teamType', ChoiceType::class, [
                'label' => 'crisis_team.field.team_type',
                'choices' => [
                    'crisis_team.type.operational' => 'operational',
                    'crisis_team.type.strategic' => 'strategic',
                    'crisis_team.type.technical' => 'technical',
                    'crisis_team.type.communication' => 'communication',
                ],
                'required' => true,
                'help' => 'crisis_team.help.team_type',
                    'choice_translation_domain' => 'crisis_team',
            ])
            ->add('isActive', ChoiceType::class, [
                'label' => 'crisis_team.field.is_active',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                    'choice_translation_domain' => 'messages',
            ])
            ->add('teamLeader', EntityType::class, [
                'label' => 'crisis_team.field.team_leader',
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'crisis_team.placeholder.team_leader',
                'required' => false,
                'help' => 'crisis_team.help.team_leader',
            ])
            ->add('deputyLeader', EntityType::class, [
                'label' => 'crisis_team.field.deputy_leader',
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'crisis_team.placeholder.deputy_leader',
                'required' => false,
                'help' => 'crisis_team.help.deputy_leader',
            ])
            ->add('primaryPhone', TelType::class, [
                'label' => 'crisis_team.field.primary_phone',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => 'crisis_team.placeholder.primary_phone',
                ],
                'help' => 'crisis_team.help.primary_phone',
            ])
            ->add('primaryEmail', EmailType::class, [
                'label' => 'crisis_team.field.primary_email',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'crisis_team.placeholder.primary_email',
                ],
            ])
            ->add('meetingLocation', TextareaType::class, [
                'label' => 'crisis_team.field.meeting_location',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'crisis_team.placeholder.meeting_location',
                ],
                'help' => 'crisis_team.help.meeting_location',
            ])
            ->add('backupMeetingLocation', TextareaType::class, [
                'label' => 'crisis_team.field.backup_meeting_location',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'crisis_team.placeholder.backup_meeting_location',
                ],
            ])
            ->add('virtualMeetingUrl', UrlType::class, [
                'label' => 'crisis_team.field.virtual_meeting_url',
                'required' => false,
                'attr' => [
                    'placeholder' => 'crisis_team.placeholder.virtual_meeting_url',
                ],
                'help' => 'crisis_team.help.virtual_meeting_url',
            ])
            ->add('alertProcedures', TextareaType::class, [
                'label' => 'crisis_team.field.alert_procedures',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'crisis_team.placeholder.alert_procedures',
                ],
                'help' => 'crisis_team.help.alert_procedures',
            ])
            ->add('decisionAuthority', TextareaType::class, [
                'label' => 'crisis_team.field.decision_authority',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'crisis_team.placeholder.decision_authority',
                ],
                'help' => 'crisis_team.help.decision_authority',
            ])
            ->add('communicationProtocols', TextareaType::class, [
                'label' => 'crisis_team.field.communication_protocols',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'crisis_team.placeholder.communication_protocols',
                ],
                'help' => 'crisis_team.help.communication_protocols',
            ])
            ->add('trainingSchedule', TextareaType::class, [
                'label' => 'crisis_team.field.training_schedule',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'crisis_team.placeholder.training_schedule',
                ],
                'help' => 'crisis_team.help.training_schedule',
            ])
            ->add('lastTrainingAt', DateTimeType::class, [
                'label' => 'crisis_team.field.last_training_at',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('nextTrainingAt', DateTimeType::class, [
                'label' => 'crisis_team.field.next_training_at',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'crisis_team.help.next_training_at',
            ])
            ->add('businessContinuityPlans', EntityType::class, [
                'label' => 'crisis_team.field.business_continuity_plans',
                'class' => BusinessContinuityPlan::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'help' => 'crisis_team.help.business_continuity_plans',
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'crisis_team.field.notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CrisisTeam::class,
            'translation_domain' => 'crisis_team',
        ]);
    }
}
