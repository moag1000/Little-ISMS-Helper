<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\User;
use App\Entity\Incident;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
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
                'required' => true,
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
                    'choice_translation_domain' => 'incident',
            ])
            ->add('severity', EnumType::class, [
                'label' => 'incident.field.severity',
                'class' => IncidentSeverity::class,
                'choice_label' => fn(IncidentSeverity $s): string => 'incident.severity.' . $s->value,
                'required' => true,
                'help' => 'incident.help.severity',
                'choice_translation_domain' => 'incident',
            ])
            ->add('dataBreachOccurred', ChoiceType::class, [
                'label' => 'incident.field.data_breach_occurred',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                'help' => 'incident.help.data_breach_occurred',
                    'choice_translation_domain' => 'messages',
            ])
            ->add('detectedAt', DateTimeType::class, [
                'label' => 'incident.field.detected_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => true,
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'incident.field.occurred_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('reportedByUser', EntityType::class, [
                'label' => 'incident.field.reported_by',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'incident.placeholder.reported_by_user',
                'attr' => ['class' => 'form-select'],
                'help' => 'incident.help.reported_by_user',
            ])
            ->add('reportedBy', TextType::class, [
                'label' => 'incident.field.reported_by_legacy',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'incident.placeholder.reported_by',
                ],
            ])
            ->add('status', EnumType::class, [
                'label' => 'incident.field.status',
                'class' => IncidentStatus::class,
                'choice_label' => fn(IncidentStatus $s): string => 'incident.status.' . $s->value,
                'required' => true,
                'choice_translation_domain' => 'incident',
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
                'help' => 'incident.help.resolution_after_investigation',
            ])
            ->add('correctiveActions', TextareaType::class, [
                'label' => 'incident.field.corrective_actions',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.corrective_actions',
                ],
                'help' => 'incident.help.resolution_after_investigation',
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'incident.field.lessons_learned',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'incident.placeholder.lessons_learned',
                ],
                'help' => 'incident.help.resolution_after_investigation',
            ])
            ->add('closedAt', DateTimeType::class, [
                'label' => 'incident.field.closed_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // Phase 9.P2.3 — opt-out of cross-posting to the Group-CISO
            // in a holding subtree. Default true; operators of a Tochter
            // uncheck only for genuinely confidential incidents.
            ->add('visibleToHolding', CheckboxType::class, [
                'label' => 'incident.field.visible_to_holding',
                'help' => 'incident.help.visible_to_holding',
                'required' => false,
            ])

            // NIS2 Article 23 - Reporting Timeline Fields
            ->add('nis2Category', ChoiceType::class, [
                'label' => 'incident.field.nis2_category',
                'choices' => [
                    'incident.nis2_category.operational' => 'operational',
                    'incident.nis2_category.security' => 'security',
                    'incident.nis2_category.privacy' => 'privacy',
                    'incident.nis2_category.availability' => 'availability',
                ],
                'required' => false,
                'placeholder' => 'incident.placeholder.nis2_category',
                'help' => 'incident.help.nis2_category',
                    'choice_translation_domain' => 'incident',
            ])
            ->add('earlyWarningReportedAt', DateTimeType::class, [
                'label' => 'incident.field.early_warning_reported_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'incident.help.early_warning_24h',
            ])
            ->add('detailedNotificationReportedAt', DateTimeType::class, [
                'label' => 'incident.field.detailed_notification_reported_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'incident.help.detailed_notification_72h',
            ])
            ->add('finalReportSubmittedAt', DateTimeType::class, [
                'label' => 'incident.field.final_report_submitted_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'incident.help.final_report_1month',
            ])
            ->add('crossBorderImpact', ChoiceType::class, [
                'label' => 'incident.field.cross_border_impact',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => false,
                'help' => 'incident.help.cross_border_impact',
                    'choice_translation_domain' => 'messages',
            ])
            ->add('affectedUsersCount', IntegerType::class, [
                'label' => 'incident.field.affected_users_count',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => '0',
                ],
                'help' => 'incident.help.affected_users_count',
            ])
            ->add('estimatedFinancialImpact', MoneyType::class, [
                'label' => 'incident.field.estimated_financial_impact',
                'currency' => 'EUR',
                'required' => false,
                'help' => 'incident.help.estimated_financial_impact',
            ])
            ->add('nationalAuthorityNotified', TextType::class, [
                'label' => 'incident.field.national_authority_notified',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'incident.placeholder.national_authority',
                ],
                'help' => 'incident.help.national_authority',
            ])
            ->add('authorityReferenceNumber', TextType::class, [
                'label' => 'incident.field.authority_reference_number',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'incident.placeholder.authority_reference',
                ],
            ])
            ->add('affectedAssets', EntityType::class, [
                'label' => 'incident.field.affected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'help' => 'incident.help.affected_assets',
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                    'data-controller' => 'tom-select',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Incident::class,
            'translation_domain' => 'incident',
        ]);
    }
}
