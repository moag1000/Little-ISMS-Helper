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
                'help' => 'dpia.help.title',
                'required' => true,
            ])
            ->add('referenceNumber', TextType::class, [
                'label' => 'dpia.form.reference_number',
                'help' => 'dpia.help.reference_number',
                'required' => false,
            ])
            ->add('processingActivity', EntityType::class, [
                'label' => 'dpia.form.processing_activity',
                'help' => 'dpia.help.processing_activity',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'dpia.placeholder.processing_activity',
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Art. 35(7)(a) - Description of Processing Operations
            // ============================================================================
            ->add('processingDescription', TextareaType::class, [
                'label' => 'dpia.form.processing_description',
                'help' => 'dpia.help.processing_description',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('processingPurposes', TextareaType::class, [
                'label' => 'dpia.form.processing_purposes',
                'help' => 'dpia.help.processing_purposes',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('dataCategories', ChoiceType::class, [
                'label' => 'dpia.form.data_categories',
                'help' => 'dpia.help.data_categories',
                'choices' => [
                    'privacy.personal_data.identification' => 'identification',
                    'privacy.personal_data.contact' => 'contact',
                    'privacy.personal_data.financial' => 'financial',
                    'privacy.personal_data.location' => 'location',
                    'privacy.personal_data.online_identifiers' => 'online_identifiers',
                    'privacy.personal_data.employment' => 'employment',
                    'privacy.data_category.health_art9' => 'health',
                    'privacy.data_category.biometric_art9' => 'biometric',
                    'privacy.data_category.genetic_art9' => 'genetic',
                    'privacy.personal_data.communication' => 'communication',
                    'privacy.personal_data.usage' => 'usage',
                    'privacy.data_category.behavioral' => 'behavioral',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
                'choice_translation_domain' => 'privacy',
            ])
            ->add('dataSubjectCategories', ChoiceType::class, [
                'label' => 'dpia.form.data_subject_categories',
                'help' => 'dpia.form.data_subject_categories',
                'choices' => [
                    'privacy.data_subject.customers' => 'customers',
                    'privacy.data_subject.employees' => 'employees',
                    'privacy.data_subject.applicants' => 'applicants',
                    'privacy.data_subject.suppliers' => 'suppliers',
                    'privacy.data_subject.visitors' => 'visitors',
                    'privacy.data_subject.subscribers' => 'subscribers',
                    'privacy.data_subject.patients' => 'patients',
                    'privacy.data_subject.students' => 'students',
                    'privacy.data_subject.children' => 'children',
                    'privacy.data_subject.vulnerable' => 'vulnerable',
                    'privacy.data_subject.other' => 'other',
                ],
                'multiple' => true,
                'required' => true,
                'attr' => ['class' => 'select2'],
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('estimatedDataSubjects', IntegerType::class, [
                'label' => 'dpia.form.estimated_data_subjects',
                'help' => 'dpia.help.estimated_data_subjects',
                'required' => false,
            ])
            ->add('dataRetentionPeriod', TextareaType::class, [
                'label' => 'dpia.form.data_retention_period',
                'help' => 'dpia.help.data_retention_period',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('dataFlowDescription', TextareaType::class, [
                'label' => 'dpia.form.data_flow_description',
                'help' => 'dpia.help.data_flow_description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            // ============================================================================
            // Art. 35(7)(b) - Assessment of Necessity and Proportionality
            // ============================================================================
            ->add('necessityAssessment', TextareaType::class, [
                'label' => 'dpia.form.necessity_assessment',
                'help' => 'dpia.help.necessity_assessment',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('proportionalityAssessment', TextareaType::class, [
                'label' => 'dpia.form.proportionality_assessment',
                'help' => 'dpia.help.proportionality_assessment',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('legalBasis', ChoiceType::class, [
                'label' => 'dpia.form.legal_basis',
                'help' => 'dpia.help.legal_basis',
                'choices' => [
                    'privacy.legal_basis.consent' => 'consent',
                    'privacy.legal_basis.contract' => 'contract',
                    'privacy.legal_basis.legal_obligation' => 'legal_obligation',
                    'privacy.legal_basis.vital_interests' => 'vital_interests',
                    'privacy.legal_basis.public_task' => 'public_task',
                    'privacy.legal_basis.legitimate_interests' => 'legitimate_interests',
                ],
                'required' => true,
                'placeholder' => 'dpia.placeholder.legal_basis',
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('legislativeCompliance', TextareaType::class, [
                'label' => 'dpia.form.legislative_compliance',
                'help' => 'dpia.help.legislative_compliance',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Art. 35(7)(c) - Risk Assessment
            // ============================================================================
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'dpia.form.risk_level',
                'help' => 'dpia.help.risk_level',
                'choices' => [
                    'privacy.risk_level.low' => 'low',
                    'privacy.risk_level.medium' => 'medium',
                    'privacy.risk_level.high' => 'high',
                    'privacy.risk_level.critical' => 'critical',
                ],
                'required' => true,
                'placeholder' => 'dpia.placeholder.risk_level',
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('likelihood', ChoiceType::class, [
                'label' => 'dpia.form.likelihood',
                'help' => 'dpia.help.likelihood',
                'choices' => [
                    'privacy.likelihood.rare' => 'rare',
                    'privacy.likelihood.unlikely' => 'unlikely',
                    'privacy.likelihood.possible' => 'possible',
                    'privacy.likelihood.likely' => 'likely',
                    'privacy.likelihood.certain' => 'certain',
                ],
                'required' => false,
                'placeholder' => 'dpia.placeholder.likelihood',
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('impact', ChoiceType::class, [
                'label' => 'dpia.form.impact',
                'help' => 'dpia.help.impact',
                'choices' => [
                    'privacy.impact.negligible' => 'negligible',
                    'privacy.impact.minor' => 'minor',
                    'privacy.impact.moderate' => 'moderate',
                    'privacy.impact.major' => 'major',
                    'privacy.impact.severe' => 'severe',
                ],
                'required' => false,
                'placeholder' => 'dpia.placeholder.impact',
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('dataSubjectRisks', TextareaType::class, [
                'label' => 'dpia.form.data_subject_risks',
                'help' => 'dpia.help.data_subject_risks',
                'required' => false,
                'attr' => ['rows' => 4],
            ])

            // ============================================================================
            // Art. 35(7)(d) - Measures to Address Risks
            // ============================================================================
            ->add('technicalMeasures', TextareaType::class, [
                'label' => 'dpia.form.technical_measures',
                'help' => 'dpia.help.technical_measures',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('organizationalMeasures', TextareaType::class, [
                'label' => 'dpia.form.organizational_measures',
                'help' => 'dpia.help.organizational_measures',
                'required' => true,
                'attr' => ['rows' => 5],
            ])
            ->add('implementedControls', EntityType::class, [
                'label' => 'dpia.form.implemented_controls',
                'help' => 'dpia.help.implemented_controls',
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
                'help' => 'dpia.help.compliance_measures',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('residualRiskAssessment', TextareaType::class, [
                'label' => 'dpia.form.residual_risk_assessment',
                'help' => 'dpia.help.residual_risk_assessment',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('residualRiskLevel', ChoiceType::class, [
                'label' => 'dpia.form.residual_risk_level',
                'help' => 'dpia.help.residual_risk_level',
                'choices' => [
                    'privacy.risk_level.low' => 'low',
                    'privacy.risk_level.medium' => 'medium',
                    'privacy.risk_level.high' => 'high',
                    'privacy.risk_level.critical' => 'critical',
                ],
                'required' => false,
                'placeholder' => 'dpia.placeholder.residual_risk_level',
                    'choice_translation_domain' => 'privacy',
            ])

            // ============================================================================
            // Stakeholder Consultation (Art. 35(4), 35(9))
            // ============================================================================
            ->add('dataProtectionOfficer', EntityType::class, [
                'label' => 'dpia.form.data_protection_officer',
                'help' => 'dpia.help.data_protection_officer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'required' => false,
                'placeholder' => 'dpia.placeholder.data_protection_officer',
                'attr' => ['class' => 'select2'],
            ])
            ->add('dpoConsultationDate', DateType::class, [
                'label' => 'dpia.form.dpo_consultation_date',
                'help' => 'dpia.help.dpo_consultation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('dpoAdvice', TextareaType::class, [
                'label' => 'dpia.form.dpo_advice',
                'help' => 'dpia.help.dpo_advice',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('dataSubjectsConsulted', ChoiceType::class, [
                'label' => 'dpia.form.data_subjects_consulted',
                'help' => 'dpia.help.data_subjects_consulted',
                'choices' => [
                    'common.no' => false,
                    'common.yes' => true,
                ],
                'expanded' => true,
                'required' => false,
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('dataSubjectConsultationDetails', TextareaType::class, [
                'label' => 'dpia.form.data_subject_consultation_details',
                'help' => 'dpia.help.data_subject_consultation_details',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Supervisory Authority Consultation (Art. 36)
            // ============================================================================
            ->add('requiresSupervisoryConsultation', ChoiceType::class, [
                'label' => 'dpia.form.requires_supervisory_consultation',
                'help' => 'dpia.help.requires_supervisory_consultation',
                'choices' => [
                    'common.no' => false,
                    'privacy.supervisory_consultation.yes_high_risk' => true,
                ],
                'expanded' => true,
                'required' => false,
                    'choice_translation_domain' => 'privacy',
            ])
            ->add('supervisoryConsultationDate', DateType::class, [
                'label' => 'dpia.form.supervisory_consultation_date',
                'help' => 'dpia.help.supervisory_consultation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('supervisoryAuthorityFeedback', TextareaType::class, [
                'label' => 'dpia.form.supervisory_authority_feedback',
                'help' => 'dpia.help.supervisory_authority_feedback',
                'required' => false,
                'attr' => ['rows' => 3],
            ])

            // ============================================================================
            // Workflow
            // ============================================================================
            ->add('conductor', EntityType::class, [
                'label' => 'dpia.form.conductor',
                'help' => 'dpia.help.conductor',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'required' => false,
                'placeholder' => 'dpia.placeholder.conductor',
                'attr' => ['class' => 'select2'],
            ])

            // ============================================================================
            // Review (Art. 35(11))
            // ============================================================================
            ->add('reviewFrequencyMonths', IntegerType::class, [
                'label' => 'dpia.form.review_frequency_months',
                'help' => 'dpia.help.review_frequency_months',
                'required' => false,
                'data' => 12,
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'dpia.form.next_review_date',
                'help' => 'dpia.help.next_review_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataProtectionImpactAssessment::class,
            'translation_domain' => 'privacy',
        ]);
    }
}
