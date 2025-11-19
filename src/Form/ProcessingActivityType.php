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
                'help' => 'Name/title of the processing activity (e.g., "Customer Management", "Payroll")',
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'processing_activity.form.description',
                'help' => 'Detailed description of the processing activity',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('purposes', ChoiceType::class, [
                'label' => 'processing_activity.form.purposes',
                'help' => 'Purpose(s) of processing (Art. 30(1)(a))',
                'choices' => [
                    'Contract fulfillment' => 'contract_fulfillment',
                    'Marketing' => 'marketing',
                    'Legal obligation' => 'legal_obligation',
                    'Customer relationship management' => 'crm',
                    'Human resources management' => 'hr_management',
                    'Financial accounting' => 'accounting',
                    'IT security' => 'it_security',
                    'Quality assurance' => 'quality_assurance',
                    'Research and development' => 'research',
                    'Other' => 'other',
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
                'help' => 'Categories of data subjects (Art. 30(1)(b))',
                'choices' => [
                    'Customers' => 'customers',
                    'Employees' => 'employees',
                    'Job applicants' => 'applicants',
                    'Suppliers/Partners' => 'suppliers',
                    'Website visitors' => 'visitors',
                    'Newsletter subscribers' => 'subscribers',
                    'Patients' => 'patients',
                    'Students' => 'students',
                    'Other' => 'other',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('estimatedDataSubjectsCount', IntegerType::class, [
                'label' => 'processing_activity.form.estimated_data_subjects_count',
                'help' => 'Approximate number of affected data subjects',
                'required' => false,
            ])

            // ============================================================================
            // Personal Data Categories (Art. 30(1)(c))
            // ============================================================================
            ->add('personalDataCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.personal_data_categories',
                'help' => 'Categories of personal data processed (Art. 30(1)(c))',
                'choices' => [
                    'Identification data (name, ID number)' => 'identification',
                    'Contact data (address, email, phone)' => 'contact',
                    'Financial data (bank account, payment info)' => 'financial',
                    'Location data' => 'location',
                    'Online identifiers (IP, cookies)' => 'online_identifiers',
                    'Employment data' => 'employment',
                    'Educational data' => 'educational',
                    'Contract data' => 'contract',
                    'Communication data' => 'communication',
                    'Usage data' => 'usage',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('processesSpecialCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.processes_special_categories',
                'help' => 'Does this process special categories of data (Art. 9 GDPR)?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('specialCategoriesDetails', ChoiceType::class, [
                'label' => 'processing_activity.form.special_categories_details',
                'help' => 'Which special categories are processed (Art. 9 GDPR)?',
                'choices' => [
                    'Health data' => 'health',
                    'Biometric data' => 'biometric',
                    'Genetic data' => 'genetic',
                    'Racial/ethnic origin' => 'racial_ethnic',
                    'Political opinions' => 'political',
                    'Religious/philosophical beliefs' => 'religious',
                    'Trade union membership' => 'union',
                    'Sex life/sexual orientation' => 'sex_life',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('processesCriminalData', ChoiceType::class, [
                'label' => 'processing_activity.form.processes_criminal_data',
                'help' => 'Does this process criminal convictions data (Art. 10 GDPR)?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Recipients (Art. 30(1)(d))
            // ============================================================================
            ->add('recipientCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.recipient_categories',
                'help' => 'Categories of recipients (Art. 30(1)(d))',
                'choices' => [
                    'Internal departments' => 'internal_departments',
                    'IT service providers' => 'it_service_providers',
                    'Cloud providers' => 'cloud_providers',
                    'Payment processors' => 'payment_processors',
                    'Marketing agencies' => 'marketing_agencies',
                    'Auditors/Consultants' => 'auditors',
                    'Public authorities' => 'public_authorities',
                    'Legal representatives' => 'legal',
                    'Other processors' => 'other_processors',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('recipientDetails', TextareaType::class, [
                'label' => 'processing_activity.form.recipient_details',
                'help' => 'Specific recipients (optional, for transparency)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Third Country Transfers (Art. 30(1)(e))
            // ============================================================================
            ->add('hasThirdCountryTransfer', ChoiceType::class, [
                'label' => 'processing_activity.form.has_third_country_transfer',
                'help' => 'Is data transferred to countries outside EU/EEA (Art. 30(1)(e))?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('thirdCountries', ChoiceType::class, [
                'label' => 'processing_activity.form.third_countries',
                'help' => 'Which countries receive data?',
                'choices' => [
                    'USA' => 'US',
                    'United Kingdom' => 'GB',
                    'Switzerland' => 'CH',
                    'Canada' => 'CA',
                    'Japan' => 'JP',
                    'India' => 'IN',
                    'China' => 'CN',
                    'Other' => 'other',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('transferSafeguards', ChoiceType::class, [
                'label' => 'processing_activity.form.transfer_safeguards',
                'help' => 'Legal basis for third country transfer (Art. 44-49)',
                'choices' => [
                    'Art. 45 Adequacy Decision' => 'adequacy_decision',
                    'Art. 46 Standard Contractual Clauses (SCCs)' => 'standard_contractual_clauses',
                    'Art. 47 Binding Corporate Rules (BCRs)' => 'binding_corporate_rules',
                    'Art. 42 Certification' => 'certification',
                    'Art. 40 Codes of Conduct' => 'codes_of_conduct',
                    'Art. 49(1)(a) Explicit consent' => 'explicit_consent',
                    'Art. 49(1)(b) Contract necessity' => 'contract_necessity',
                    'Art. 49(1)(d) Public interest' => 'public_interest',
                    'Art. 49(1)(e) Legal claims' => 'legal_claims',
                    'Art. 49(1)(f) Vital interests' => 'vital_interests',
                ],
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Retention Periods (Art. 30(1)(f))
            // ============================================================================
            ->add('retentionPeriod', TextareaType::class, [
                'label' => 'processing_activity.form.retention_period',
                'help' => 'Retention period/deletion deadline (Art. 30(1)(f))',
                'required' => true,
                'attr' => ['rows' => 2],
            ])
            ->add('retentionPeriodDays', IntegerType::class, [
                'label' => 'processing_activity.form.retention_period_days',
                'help' => 'Retention period in days (optional, for automated deletion)',
                'required' => false,
            ])
            ->add('retentionLegalBasis', TextareaType::class, [
                'label' => 'processing_activity.form.retention_legal_basis',
                'help' => 'Legal basis for retention period (e.g., HGB §257, Tax Code)',
                'required' => false,
                'attr' => ['rows' => 2],
            ])

            // ============================================================================
            // Technical and Organizational Measures (Art. 30(1)(g))
            // ============================================================================
            ->add('technicalOrganizationalMeasures', TextareaType::class, [
                'label' => 'processing_activity.form.technical_organizational_measures',
                'help' => 'Description of technical and organizational security measures (Art. 32 GDPR)',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('implementedControls', EntityType::class, [
                'label' => 'processing_activity.form.implemented_controls',
                'help' => 'Link to ISO 27001 controls (optional)',
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
                'help' => 'Legal basis for processing (Art. 6(1) GDPR)',
                'choices' => [
                    'Art. 6(1)(a) Consent' => 'consent',
                    'Art. 6(1)(b) Contract performance' => 'contract',
                    'Art. 6(1)(c) Legal obligation' => 'legal_obligation',
                    'Art. 6(1)(d) Vital interests' => 'vital_interests',
                    'Art. 6(1)(e) Public task' => 'public_task',
                    'Art. 6(1)(f) Legitimate interests' => 'legitimate_interests',
                ],
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('legalBasisDetails', TextareaType::class, [
                'label' => 'processing_activity.form.legal_basis_details',
                'help' => 'Detailed explanation (mandatory for legitimate interests)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('legalBasisSpecialCategories', ChoiceType::class, [
                'label' => 'processing_activity.form.legal_basis_special_categories',
                'help' => 'Legal basis for processing special categories (Art. 9(2) GDPR)',
                'choices' => [
                    'Art. 9(2)(a) Explicit consent' => 'explicit_consent',
                    'Art. 9(2)(b) Employment law' => 'employment_law',
                    'Art. 9(2)(c) Vital interests' => 'vital_interests',
                    'Art. 9(2)(d) Legitimate activities' => 'legitimate_activities',
                    'Art. 9(2)(e) Made public by data subject' => 'made_public',
                    'Art. 9(2)(f) Legal claims' => 'legal_claims',
                    'Art. 9(2)(g) Substantial public interest' => 'substantial_public_interest',
                    'Art. 9(2)(h) Health care' => 'health_care',
                    'Art. 9(2)(i) Public health' => 'public_health',
                    'Art. 9(2)(j) Research/statistics' => 'research_statistics',
                ],
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Organizational Details
            // ============================================================================
            ->add('responsibleDepartment', TextType::class, [
                'label' => 'processing_activity.form.responsible_department',
                'help' => 'Department/unit responsible for this processing',
                'required' => false,
            ])
            ->add('contactPerson', EntityType::class, [
                'label' => 'processing_activity.form.contact_person',
                'help' => 'Contact person for this processing activity',
                'class' => User::class,
                'choice_label' => 'username',
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('dataProtectionOfficer', EntityType::class, [
                'label' => 'processing_activity.form.data_protection_officer',
                'help' => 'Data Protection Officer (if applicable)',
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
                'help' => 'Are processors (Auftragsverarbeiter) involved (Art. 28)?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Joint Controllers (Art. 26)
            // ============================================================================
            ->add('isJointController', ChoiceType::class, [
                'label' => 'processing_activity.form.is_joint_controller',
                'help' => 'Is this a joint controller arrangement (Art. 26)?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])

            // ============================================================================
            // Risk & DPIA (Art. 35)
            // ============================================================================
            ->add('isHighRisk', ChoiceType::class, [
                'label' => 'processing_activity.form.is_high_risk',
                'help' => 'Is this considered high-risk processing?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('dpiaCompleted', ChoiceType::class, [
                'label' => 'processing_activity.form.dpia_completed',
                'help' => 'Has a Data Protection Impact Assessment (DPIA) been conducted?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('dpiaDate', DateType::class, [
                'label' => 'processing_activity.form.dpia_date',
                'help' => 'Date of DPIA completion',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'processing_activity.form.risk_level',
                'help' => 'Overall risk level assessment',
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
                'help' => 'Does this involve automated decision-making/profiling (Art. 22)?',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => true,
            ])
            ->add('automatedDecisionMakingDetails', TextareaType::class, [
                'label' => 'processing_activity.form.automated_decision_making_details',
                'help' => 'Explain logic and significance of automated decision-making',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Data Sources
            // ============================================================================
            ->add('dataSources', ChoiceType::class, [
                'label' => 'processing_activity.form.data_sources',
                'help' => 'Where is data collected from?',
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
                'help' => 'Status of this record',
                'choices' => [
                    'Draft' => 'draft',
                    'Active' => 'active',
                    'Archived' => 'archived',
                ],
                'required' => true,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'processing_activity.form.start_date',
                'help' => 'When did this processing start?',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('endDate', DateType::class, [
                'label' => 'processing_activity.form.end_date',
                'help' => 'When did this processing end (if applicable)?',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'processing_activity.form.next_review_date',
                'help' => 'When should this be reviewed next?',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProcessingActivity::class,
        ]);
    }
}
