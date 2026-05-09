<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class IncidentType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

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
                'help' => 'incident.help.reported_by_user',
            ])
            ->add('reportedByPerson', EntityType::class, [
                'label' => 'incident.field.reported_by_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'incident.placeholder.reported_by_person',
                'help' => 'incident.help.reported_by_person',
            ])
            ->add('reportedByDeputyPersons', EntityType::class, [
                'label' => 'incident.field.reported_by_deputies',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'incident.help.reported_by_deputies',
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
                    'size' => 5,
                    'data-controller' => 'tom-select',
                ],
            ])

            // ISO 27001 A.5.24-A.5.28 — always-active fields (T31.2.2)
            ->add('incidentClassification', ChoiceType::class, [
                'label' => 'incident.field.incident_classification',
                'choices' => [
                    'incident.classification.event' => 'event',
                    'incident.classification.incident' => 'incident',
                ],
                'required' => false,
                'placeholder' => 'incident.placeholder.classification',
                'help' => 'incident.help.incident_classification',
                'choice_translation_domain' => 'incident',
            ])
            ->add('containmentActions', TextareaType::class, [
                'label' => 'incident.field.containment_actions',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'incident.placeholder.containment_actions'],
                'help' => 'incident.help.containment_actions',
            ])
            ->add('evidencePreserved', CheckboxType::class, [
                'label' => 'incident.field.evidence_preserved',
                'required' => false,
                'help' => 'incident.help.evidence_preserved',
            ])
        ;

        // DORA Art. 17-19 — gated on nis2_dora module (T31.2.2)
        if ($this->isModuleActive('nis2_dora')) {
            $builder
                ->add('ictIncidentClassification', ChoiceType::class, [
                    'label' => 'incident.field.ict_incident_classification',
                    'choices' => [
                        'incident.dora_classification.major' => 'major_ict_incident',
                        'incident.dora_classification.significant' => 'significant_cyber_threat',
                    ],
                    'required' => false,
                    'placeholder' => 'incident.placeholder.ict_classification',
                    'help' => 'incident.help.ict_incident_classification',
                    'choice_translation_domain' => 'incident',
                ])
                ->add('dataLossOccurred', CheckboxType::class, [
                    'label' => 'incident.field.data_loss_occurred',
                    'required' => false,
                ])
                ->add('dataLeakageOccurred', CheckboxType::class, [
                    'label' => 'incident.field.data_leakage_occurred',
                    'required' => false,
                ])
                ->add('economicImpact', NumberType::class, [
                    'label' => 'incident.field.economic_impact',
                    'required' => false,
                    'scale' => 2,
                    'attr' => ['placeholder' => 'incident.placeholder.economic_impact', 'min' => 0, 'step' => '0.01'],
                    'help' => 'incident.help.economic_impact',
                ])
                ->add('reputationalImpact', ChoiceType::class, [
                    'label' => 'incident.field.reputational_impact',
                    'choices' => [
                        'incident.impact.minimal' => 1,
                        'incident.impact.minor' => 2,
                        'incident.impact.moderate' => 3,
                        'incident.impact.major' => 4,
                        'incident.impact.severe' => 5,
                    ],
                    'required' => false,
                    'placeholder' => 'incident.placeholder.reputational_impact',
                    'choice_translation_domain' => 'incident',
                ])
                ->add('criticalServicesAffected', EntityType::class, [
                    'label' => 'incident.field.critical_services_affected',
                    'class' => BusinessProcess::class,
                    'choice_label' => 'name',
                    'multiple' => true,
                    'required' => false,
                    'help' => 'incident.help.critical_services_affected',
                    'attr' => [
                        'class' => 'form-select',
                        'data-controller' => 'tom-select',
                    ],
                ])
                ->add('recurringIncident', CheckboxType::class, [
                    'label' => 'incident.field.recurring_incident',
                    'required' => false,
                ])
                ->add('clientsAffected', IntegerType::class, [
                    'label' => 'incident.field.clients_affected',
                    'required' => false,
                    'attr' => ['min' => 0],
                ])
                ->add('clientsAffectedFinancialVolume', NumberType::class, [
                    'label' => 'incident.field.clients_affected_financial_volume',
                    'required' => false,
                    'scale' => 2,
                    'attr' => ['min' => 0, 'step' => '0.01'],
                    'help' => 'incident.help.clients_affected_financial_volume',
                ])
                ->add('replicationOfImpact', CheckboxType::class, [
                    'label' => 'incident.field.replication_of_impact',
                    'required' => false,
                    'help' => 'incident.help.replication_of_impact',
                ])
                ->add('initialReportSubmittedAt', DateTimeType::class, [
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'label' => 'incident.field.initial_report_submitted_at',
                    'required' => false,
                    'help' => 'incident.help.initial_report_submitted_at_dora',
                ])
                ->add('intermediateReportSubmittedAt', DateTimeType::class, [
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'label' => 'incident.field.intermediate_report_submitted_at',
                    'required' => false,
                ])
                ->add('dataRecoveryStrategy', TextareaType::class, [
                    'label' => 'incident.field.data_recovery_strategy',
                    'required' => false,
                    'attr' => ['rows' => 3],
                    'help' => 'incident.help.data_recovery_strategy',
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Incident::class,
            'translation_domain' => 'incident',
            'constraints' => [
                new Callback([$this, 'validateReportedBySlot']),
            ],
        ]);
    }

    public function validateReportedBySlot(?Incident $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getReportedByUser() === null && $entity->getReportedByPerson() === null) {
            $context->buildViolation('incident.error.owner_required_user_or_person')
                ->atPath('reportedByUser')
                ->addViolation();
        }
    }
}
