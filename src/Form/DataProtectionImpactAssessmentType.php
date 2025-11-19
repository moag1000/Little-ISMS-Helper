<?php

namespace App\Form;

use App\Entity\Control;
use App\Entity\DataProtectionImpactAssessment;
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
 * CRITICAL-07: DataProtectionImpactAssessment Form Type
 *
 * Comprehensive form for GDPR Art. 35 DPIA (Datenschutz-Folgenabschätzung).
 * Organized in logical sections matching Art. 35(7) structure.
 */
class DataProtectionImpactAssessmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ============================================================================
            // Basic Information
            // ============================================================================
            ->add('title', TextType::class, [
                'label' => 'dpia.form.title',
                'help' => 'Title of the DPIA (e.g., "Employee Monitoring System DPIA")',
                'required' => true,
            ])
            ->add('referenceNumber', TextType::class, [
                'label' => 'dpia.form.reference_number',
                'help' => 'Reference number (auto-generated if empty, format: DPIA-YYYY-XXX)',
                'required' => false,
            ])
            ->add('processingActivity', EntityType::class, [
                'label' => 'dpia.form.processing_activity',
                'help' => 'Link to related VVT processing activity (Art. 30)',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Select processing activity',
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Art. 35(7)(a) - Description of Processing Operations
            // ============================================================================
            ->add('processingDescription', TextareaType::class, [
                'label' => 'dpia.form.processing_description',
                'help' => 'Systematic description of the envisaged processing operations (Art. 35(7)(a))',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('processingPurposes', TextareaType::class, [
                'label' => 'dpia.form.processing_purposes',
                'help' => 'Purposes of the processing (Art. 35(7)(a))',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('dataCategories', ChoiceType::class, [
                'label' => 'dpia.form.data_categories',
                'help' => 'Categories of personal data processed (Art. 35(7)(a))',
                'choices' => [
                    'Identification data (name, ID number)' => 'identification',
                    'Contact data (address, email, phone)' => 'contact',
                    'Financial data (bank account, payment info)' => 'financial',
                    'Location data' => 'location',
                    'Online identifiers (IP, cookies)' => 'online_identifiers',
                    'Employment data' => 'employment',
                    'Health data (Art. 9)' => 'health',
                    'Biometric data (Art. 9)' => 'biometric',
                    'Genetic data (Art. 9)' => 'genetic',
                    'Communication data' => 'communication',
                    'Usage data' => 'usage',
                    'Behavioral data' => 'behavioral',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('dataSubjectCategories', ChoiceType::class, [
                'label' => 'dpia.form.data_subject_categories',
                'help' => 'Categories of data subjects affected (Art. 35(7)(a))',
                'choices' => [
                    'Customers' => 'customers',
                    'Employees' => 'employees',
                    'Job applicants' => 'applicants',
                    'Suppliers/Partners' => 'suppliers',
                    'Website visitors' => 'visitors',
                    'Newsletter subscribers' => 'subscribers',
                    'Patients' => 'patients',
                    'Students' => 'students',
                    'Children' => 'children',
                    'Vulnerable groups' => 'vulnerable',
                    'Other' => 'other',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
            ])
            ->add('estimatedDataSubjects', IntegerType::class, [
                'label' => 'dpia.form.estimated_data_subjects',
                'help' => 'Estimated number of affected data subjects',
                'required' => false,
            ])
            ->add('dataRetentionPeriod', TextareaType::class, [
                'label' => 'dpia.form.data_retention_period',
                'help' => 'How long will the data be retained?',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('dataFlowDescription', TextareaType::class, [
                'label' => 'dpia.form.data_flow_description',
                'help' => 'Description of data flows (collection, storage, processing, sharing, deletion)',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            // ============================================================================
            // Art. 35(7)(b) - Assessment of Necessity and Proportionality
            // ============================================================================
            ->add('necessityAssessment', TextareaType::class, [
                'label' => 'dpia.form.necessity_assessment',
                'help' => 'Why is this processing necessary? (Art. 35(7)(b))',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('proportionalityAssessment', TextareaType::class, [
                'label' => 'dpia.form.proportionality_assessment',
                'help' => 'Is the processing proportionate to the purpose? (Art. 35(7)(b))',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('legalBasis', ChoiceType::class, [
                'label' => 'dpia.form.legal_basis',
                'help' => 'Legal basis for processing (Art. 6 GDPR)',
                'choices' => [
                    'Consent (Art. 6(1)(a))' => 'consent',
                    'Contract (Art. 6(1)(b))' => 'contract',
                    'Legal obligation (Art. 6(1)(c))' => 'legal_obligation',
                    'Vital interests (Art. 6(1)(d))' => 'vital_interests',
                    'Public task (Art. 6(1)(e))' => 'public_task',
                    'Legitimate interests (Art. 6(1)(f))' => 'legitimate_interests',
                ],
                'required' => true,
                'placeholder' => 'Select legal basis',
            ])
            ->add('legislativeCompliance', TextareaType::class, [
                'label' => 'dpia.form.legislative_compliance',
                'help' => 'Compliance with other legislation (e.g., NIS2, sector-specific laws)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Art. 35(7)(c) - Risk Assessment
            // ============================================================================
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'dpia.form.risk_level',
                'help' => 'Overall risk level to rights and freedoms (Art. 35(7)(c))',
                'choices' => [
                    'Low' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Critical' => 'critical',
                ],
                'required' => true,
                'placeholder' => 'Select risk level',
            ])
            ->add('likelihood', ChoiceType::class, [
                'label' => 'dpia.form.likelihood',
                'help' => 'Likelihood of risk occurrence',
                'choices' => [
                    'Rare' => 'rare',
                    'Unlikely' => 'unlikely',
                    'Possible' => 'possible',
                    'Likely' => 'likely',
                    'Certain' => 'certain',
                ],
                'required' => false,
                'placeholder' => 'Select likelihood',
            ])
            ->add('impact', ChoiceType::class, [
                'label' => 'dpia.form.impact',
                'help' => 'Impact if risk materializes',
                'choices' => [
                    'Negligible' => 'negligible',
                    'Minor' => 'minor',
                    'Moderate' => 'moderate',
                    'Major' => 'major',
                    'Severe' => 'severe',
                ],
                'required' => false,
                'placeholder' => 'Select impact',
            ])
            ->add('dataSubjectRisks', TextareaType::class, [
                'label' => 'dpia.form.data_subject_risks',
                'help' => 'Specific risks to data subjects (e.g., discrimination, identity theft, financial loss)',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            // ============================================================================
            // Art. 35(7)(d) - Measures to Address Risks
            // ============================================================================
            ->add('technicalMeasures', TextareaType::class, [
                'label' => 'dpia.form.technical_measures',
                'help' => 'Technical measures to mitigate risks (Art. 32 GDPR)',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('organizationalMeasures', TextareaType::class, [
                'label' => 'dpia.form.organizational_measures',
                'help' => 'Organizational measures to mitigate risks (Art. 32 GDPR)',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('implementedControls', EntityType::class, [
                'label' => 'dpia.form.implemented_controls',
                'help' => 'ISO 27001 controls implemented',
                'class' => Control::class,
                'choice_label' => function (Control $control) {
                    return $control->getControlId() . ' - ' . $control->getName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'select2'],
            ])
            ->add('complianceMeasures', TextareaType::class, [
                'label' => 'dpia.form.compliance_measures',
                'help' => 'Measures to demonstrate compliance (Art. 24 GDPR - accountability)',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('residualRiskAssessment', TextareaType::class, [
                'label' => 'dpia.form.residual_risk_assessment',
                'help' => 'Residual risk assessment after implementing measures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('residualRiskLevel', ChoiceType::class, [
                'label' => 'dpia.form.residual_risk_level',
                'help' => 'Residual risk level after mitigation',
                'choices' => [
                    'Low' => 'low',
                    'Medium' => 'medium',
                    'High' => 'high',
                    'Critical' => 'critical',
                ],
                'required' => false,
                'placeholder' => 'Select residual risk level',
            ])

            // ============================================================================
            // Stakeholder Consultation (Art. 35(4), 35(9))
            // ============================================================================
            ->add('dataProtectionOfficer', EntityType::class, [
                'label' => 'dpia.form.data_protection_officer',
                'help' => 'Data Protection Officer (Art. 35(2))',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'required' => false,
                'placeholder' => 'Select DPO',
                'attr' => ['class' => 'select2'],
            ])
            ->add('dpoConsultationDate', DateType::class, [
                'label' => 'dpia.form.dpo_consultation_date',
                'help' => 'Date DPO was consulted',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('dpoAdvice', TextareaType::class, [
                'label' => 'dpia.form.dpo_advice',
                'help' => 'DPO advice/feedback',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('dataSubjectsConsulted', ChoiceType::class, [
                'label' => 'dpia.form.data_subjects_consulted',
                'help' => 'Were data subjects consulted? (Art. 35(9) - where appropriate)',
                'choices' => [
                    'No' => false,
                    'Yes' => true,
                ],
                'expanded' => true,
                'required' => false,
            ])
            ->add('dataSubjectConsultationDetails', TextareaType::class, [
                'label' => 'dpia.form.data_subject_consultation_details',
                'help' => 'Details of data subject consultation',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Supervisory Authority Consultation (Art. 36)
            // ============================================================================
            ->add('requiresSupervisoryConsultation', ChoiceType::class, [
                'label' => 'dpia.form.requires_supervisory_consultation',
                'help' => 'Does this require prior consultation with supervisory authority? (Art. 36)',
                'choices' => [
                    'No' => false,
                    'Yes (high residual risk)' => true,
                ],
                'expanded' => true,
                'required' => false,
            ])
            ->add('supervisoryConsultationDate', DateType::class, [
                'label' => 'dpia.form.supervisory_consultation_date',
                'help' => 'Date supervisory authority was consulted',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('supervisoryAuthorityFeedback', TextareaType::class, [
                'label' => 'dpia.form.supervisory_authority_feedback',
                'help' => 'Supervisory authority feedback/decision',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Workflow
            // ============================================================================
            ->add('conductor', EntityType::class, [
                'label' => 'dpia.form.conductor',
                'help' => 'Person responsible for conducting the DPIA',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'required' => false,
                'placeholder' => 'Select conductor',
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Review (Art. 35(11))
            // ============================================================================
            ->add('reviewFrequencyMonths', IntegerType::class, [
                'label' => 'dpia.form.review_frequency_months',
                'help' => 'Review frequency in months (e.g., 12 = annually)',
                'required' => false,
                'data' => 12,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'dpia.form.next_review_date',
                'help' => 'Next scheduled review date (Art. 35(11))',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataProtectionImpactAssessment::class,
        ]);
    }
}
