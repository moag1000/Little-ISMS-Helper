<?php

namespace App\Form;

use App\Entity\Control;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CRITICAL-06: ProcessingActivity Form Type
 *
 * Comprehensive form for GDPR Art. 30 VVT entry.
 * Organized in logical sections matching Art. 30(1) structure.
 */
class ProcessingActivityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ============================================================================
            // Basic Information (Art. 30(1)(a))
            // ============================================================================
            ->add('name', TextType::class, [
                'label' => 'processing_activity.form.name',
                'help' => 'processing_activity.help.name',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'processing_activity.form.description',
                'help' => 'processing_activity.help.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('purposes', ChoiceType::class, [
                'label' => 'processing_activity.form.purposes',
                'help' => 'processing_activity.help.purposes',
                'choices' => [
                    'processing_activity.purpose.contract_fulfillment' => 'contract_fulfillment',
                    'processing_activity.purpose.marketing' => 'marketing',
                    'processing_activity.purpose.legal_obligation' => 'legal_obligation',
                    'processing_activity.purpose.crm' => 'crm',
                    'processing_activity.purpose.hr_management' => 'hr_management',
                    'processing_activity.purpose.accounting' => 'accounting',
                    'processing_activity.purpose.it_security' => 'it_security',
                    'processing_activity.purpose.quality_assurance' => 'quality_assurance',
                    'processing_activity.purpose.research' => 'research',
                    'processing_activity.purpose.other' => 'other',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Data Subjects (Art. 30(1)(b))
            // ============================================================================
            ->add('dataSubjectCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.data_subject_categories',
                'help' => 'processing_activity.help.data_subject_categories',
                'choices' => [
                    'processing_activity.data_subject.customers' => 'customers',
                    'processing_activity.data_subject.employees' => 'employees',
                    'processing_activity.data_subject.applicants' => 'applicants',
                    'processing_activity.data_subject.suppliers' => 'suppliers',
                    'processing_activity.data_subject.visitors' => 'visitors',
                    'processing_activity.data_subject.subscribers' => 'subscribers',
                    'processing_activity.data_subject.patients' => 'patients',
                    'processing_activity.data_subject.students' => 'students',
                    'processing_activity.data_subject.other' => 'other',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('estimatedDataSubjectsCount', IntegerType::class, [
                'label' => 'processing_activity.form.estimated_data_subjects_count',
                'help' => 'processing_activity.help.estimated_data_subjects_count',
                'required' => false,
            ])

            // ============================================================================
            // Personal Data Categories (Art. 30(1)(c))
            // ============================================================================
            ->add('personalDataCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.personal_data_categories',
                'help' => 'processing_activity.help.personal_data_categories',
                'choices' => [
                    'processing_activity.personal_data.identification' => 'identification',
                    'processing_activity.personal_data.contact' => 'contact',
                    'processing_activity.personal_data.financial' => 'financial',
                    'processing_activity.personal_data.location' => 'location',
                    'processing_activity.personal_data.online_identifiers' => 'online_identifiers',
                    'processing_activity.personal_data.employment' => 'employment',
                    'processing_activity.personal_data.educational' => 'educational',
                    'processing_activity.personal_data.contract' => 'contract',
                    'processing_activity.personal_data.communication' => 'communication',
                    'processing_activity.personal_data.usage' => 'usage',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('processesSpecialCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.processes_special_categories',
                'help' => 'processing_activity.help.processes_special_categories',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('specialCategoriesDetails', ChoiceType::class, [
                'label' => 'processing_activity.form.special_categories_details',
                'help' => 'processing_activity.help.special_categories_details',
                'choices' => [
                    'processing_activity.special_category.health' => 'health',
                    'processing_activity.special_category.biometric' => 'biometric',
                    'processing_activity.special_category.genetic' => 'genetic',
                    'processing_activity.special_category.racial_ethnic' => 'racial_ethnic',
                    'processing_activity.special_category.political' => 'political',
                    'processing_activity.special_category.religious' => 'religious',
                    'processing_activity.special_category.union' => 'union',
                    'processing_activity.special_category.sex_life' => 'sex_life',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('processesCriminalData', ChoiceType::class, [
                'label' => 'processing_activity.form.processes_criminal_data',
                'help' => 'processing_activity.help.processes_criminal_data',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Recipients (Art. 30(1)(d))
            // ============================================================================
            ->add('recipientCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.recipient_categories',
                'help' => 'processing_activity.help.recipient_categories',
                'choices' => [
                    'processing_activity.recipient.internal_departments' => 'internal_departments',
                    'processing_activity.recipient.it_service_providers' => 'it_service_providers',
                    'processing_activity.recipient.cloud_providers' => 'cloud_providers',
                    'processing_activity.recipient.payment_processors' => 'payment_processors',
                    'processing_activity.recipient.marketing_agencies' => 'marketing_agencies',
                    'processing_activity.recipient.auditors' => 'auditors',
                    'processing_activity.recipient.public_authorities' => 'public_authorities',
                    'processing_activity.recipient.legal' => 'legal',
                    'processing_activity.recipient.other_processors' => 'other_processors',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('recipientDetails', TextareaType::class, [
                'label' => 'processing_activity.form.recipient_details',
                'help' => 'processing_activity.help.recipient_details',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Third Country Transfers (Art. 30(1)(e))
            // ============================================================================
            ->add('hasThirdCountryTransfer', ChoiceType::class, [
                'label' => 'processing_activity.form.has_third_country_transfer',
                'help' => 'processing_activity.help.has_third_country_transfer',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('thirdCountries', ChoiceType::class, [
                'label' => 'processing_activity.form.third_countries',
                'help' => 'processing_activity.help.third_countries',
                'choices' => [
                    'processing_activity.country.us' => 'US',
                    'processing_activity.country.gb' => 'GB',
                    'processing_activity.country.ch' => 'CH',
                    'processing_activity.country.ca' => 'CA',
                    'processing_activity.country.jp' => 'JP',
                    'processing_activity.country.in' => 'IN',
                    'processing_activity.country.cn' => 'CN',
                    'processing_activity.country.other' => 'other',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('transferSafeguards', ChoiceType::class, [
                'label' => 'processing_activity.form.transfer_safeguards',
                'help' => 'processing_activity.help.transfer_safeguards',
                'choices' => [
                    'processing_activity.transfer_safeguard.adequacy_decision' => 'adequacy_decision',
                    'processing_activity.transfer_safeguard.standard_contractual_clauses' => 'standard_contractual_clauses',
                    'processing_activity.transfer_safeguard.binding_corporate_rules' => 'binding_corporate_rules',
                    'processing_activity.transfer_safeguard.certification' => 'certification',
                    'processing_activity.transfer_safeguard.codes_of_conduct' => 'codes_of_conduct',
                    'processing_activity.transfer_safeguard.explicit_consent' => 'explicit_consent',
                    'processing_activity.transfer_safeguard.contract_necessity' => 'contract_necessity',
                    'processing_activity.transfer_safeguard.public_interest' => 'public_interest',
                    'processing_activity.transfer_safeguard.legal_claims' => 'legal_claims',
                    'processing_activity.transfer_safeguard.vital_interests' => 'vital_interests',
                ],
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Retention Periods (Art. 30(1)(f))
            // ============================================================================
            ->add('retentionPeriod', TextareaType::class, [
                'label' => 'processing_activity.form.retention_period',
                'help' => 'processing_activity.help.retention_period',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('retentionPeriodDays', IntegerType::class, [
                'label' => 'processing_activity.form.retention_period_days',
                'help' => 'processing_activity.help.retention_period_days',
                'required' => false,
            ])
            ->add('retentionLegalBasis', TextareaType::class, [
                'label' => 'processing_activity.form.retention_legal_basis',
                'help' => 'processing_activity.help.retention_legal_basis',
                'required' => false,
                'attr' => ['rows' => 2],
            ])

            // ============================================================================
            // Technical and Organizational Measures (Art. 30(1)(g))
            // ============================================================================
            ->add('technicalOrganizationalMeasures', TextareaType::class, [
                'label' => 'processing_activity.form.technical_organizational_measures',
                'help' => 'processing_activity.help.technical_organizational_measures',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('implementedControls', EntityType::class, [
                'label' => 'processing_activity.form.implemented_controls',
                'help' => 'processing_activity.help.implemented_controls',
                'class' => Control::class,
                'choice_label' => function (Control $control) {
                    return $control->getControlId() . ' - ' . $control->getName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Legal Basis (Art. 6)
            // ============================================================================
            ->add('legalBasis', ChoiceType::class, [
                'label' => 'processing_activity.form.legal_basis',
                'help' => 'processing_activity.help.legal_basis',
                'choices' => [
                    'processing_activity.legal_basis.consent' => 'consent',
                    'processing_activity.legal_basis.contract' => 'contract',
                    'processing_activity.legal_basis.legal_obligation' => 'legal_obligation',
                    'processing_activity.legal_basis.vital_interests' => 'vital_interests',
                    'processing_activity.legal_basis.public_task' => 'public_task',
                    'processing_activity.legal_basis.legitimate_interests' => 'legitimate_interests',
                ],
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('legalBasisDetails', TextareaType::class, [
                'label' => 'processing_activity.form.legal_basis_details',
                'help' => 'processing_activity.help.legal_basis_details',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('legalBasisSpecialCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.legal_basis_special_categories',
                'help' => 'processing_activity.help.legal_basis_special_categories',
                'choices' => [
                    'processing_activity.legal_basis_special.explicit_consent' => 'explicit_consent',
                    'processing_activity.legal_basis_special.employment_law' => 'employment_law',
                    'processing_activity.legal_basis_special.vital_interests' => 'vital_interests',
                    'processing_activity.legal_basis_special.legitimate_activities' => 'legitimate_activities',
                    'processing_activity.legal_basis_special.made_public' => 'made_public',
                    'processing_activity.legal_basis_special.legal_claims' => 'legal_claims',
                    'processing_activity.legal_basis_special.substantial_public_interest' => 'substantial_public_interest',
                    'processing_activity.legal_basis_special.health_care' => 'health_care',
                    'processing_activity.legal_basis_special.public_health' => 'public_health',
                    'processing_activity.legal_basis_special.research_statistics' => 'research_statistics',
                ],
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Organizational Details
            // ============================================================================
            ->add('responsibleDepartment', TextType::class, [
                'label' => 'processing_activity.form.responsible_department',
                'help' => 'processing_activity.help.responsible_department',
                'required' => false,
            ])
            ->add('contactPerson', EntityType::class, [
                'label' => 'processing_activity.form.contact_person',
                'help' => 'processing_activity.help.contact_person',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('dataProtectionOfficer', EntityType::class, [
                'label' => 'processing_activity.form.data_protection_officer',
                'help' => 'processing_activity.help.data_protection_officer',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Processors (Art. 28)
            // ============================================================================
            ->add('involvesProcessors', ChoiceType::class, [
                'label' => 'processing_activity.form.involves_processors',
                'help' => 'processing_activity.help.involves_processors',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Joint Controllers (Art. 26)
            // ============================================================================
            ->add('isJointController', ChoiceType::class, [
                'label' => 'processing_activity.form.is_joint_controller',
                'help' => 'processing_activity.help.is_joint_controller',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Risk & DPIA (Art. 35)
            // ============================================================================
            ->add('isHighRisk', ChoiceType::class, [
                'label' => 'processing_activity.form.is_high_risk',
                'help' => 'processing_activity.help.is_high_risk',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('dpiaCompleted', ChoiceType::class, [
                'label' => 'processing_activity.form.dpia_completed',
                'help' => 'processing_activity.help.dpia_completed',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('dpiaDate', DateType::class, [
                'label' => 'processing_activity.form.dpia_date',
                'help' => 'processing_activity.help.dpia_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'processing_activity.form.risk_level',
                'help' => 'processing_activity.help.risk_level',
                'choices' => [
                    'Low' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Critical' => 'critical',
                ],
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Automated Decision-Making (Art. 22)
            // ============================================================================
            ->add('hasAutomatedDecisionMaking', ChoiceType::class, [
                'label' => 'processing_activity.form.has_automated_decision_making',
                'help' => 'processing_activity.help.has_automated_decision_making',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('automatedDecisionMakingDetails', TextareaType::class, [
                'label' => 'processing_activity.form.automated_decision_making_details',
                'help' => 'processing_activity.help.automated_decision_making_details',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Data Sources
            // ============================================================================
            ->add('dataSources', ChoiceType::class, [
                'label' => 'processing_activity.form.data_sources',
                'help' => 'processing_activity.help.data_sources',
                'choices' => [
                    'Directly from data subject' => 'data_subject',
                    'Third parties' => 'third_parties',
                    'Public sources' => 'public_sources',
                    'Other' => 'other',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Status & Dates
            // ============================================================================
            ->add('status', ChoiceType::class, [
                'label' => 'processing_activity.form.status',
                'help' => 'processing_activity.help.status',
                'choices' => [
                    'Draft' => 'draft',
                    'Active' => 'active',
                    'Archived' => 'archived',
                ],
                'required' => true,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'processing_activity.form.start_date',
                'help' => 'processing_activity.help.start_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('endDate', DateType::class, [
                'label' => 'processing_activity.form.end_date',
                'help' => 'processing_activity.help.end_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'processing_activity.form.next_review_date',
                'help' => 'processing_activity.help.next_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProcessingActivity::class,
            'translation_domain' => 'privacy',
        ]);
    }
}
