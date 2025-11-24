<?php

namespace App\Form;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for Data Breach (Art. 33/34 GDPR)
 */
class DataBreachType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ================================================================
            // SECTION 1: Basic Information
            // ================================================================
            ->add('title', TextType::class, [
                'label' => 'data_breach.form.title',
                'required' => true,
                'attr' => [
                    'placeholder' => 'e.g., Unauthorized access to customer database',
                    'class' => 'form-control',
                ],
            ])
            ->add('incident', EntityType::class, [
                'label' => 'data_breach.form.incident',
                'class' => Incident::class,
                'choice_label' => function (Incident $incident) {
                    return sprintf('%s - %s', $incident->getReferenceNumber(), $incident->getTitle());
                },
                'placeholder' => 'Select incident...',
                'required' => true,
                'attr' => ['class' => 'form-select select2'],
            ])
            ->add('processingActivity', EntityType::class, [
                'label' => 'data_breach.form.processing_activity',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'placeholder' => 'Select processing activity (VVT)...',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'Link to VVT (Art. 30 GDPR)',
            ])

            // ================================================================
            // SECTION 2: Art. 33(3) - Content of Notification
            // ================================================================
            ->add('affectedDataSubjects', IntegerType::class, [
                'label' => 'data_breach.form.affected_data_subjects',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Number of affected individuals',
                    'class' => 'form-control',
                    'min' => 0,
                ],
                'help' => 'Art. 33(3)(a) GDPR',
            ])
            ->add('dataCategories', ChoiceType::class, [
                'label' => 'data_breach.form.data_categories',
                'choices' => [
                    'data_breach.data_categories.personal_identification' => 'personal_identification',
                    'data_breach.data_categories.contact_information' => 'contact_information',
                    'data_breach.data_categories.financial_data' => 'financial_data',
                    'data_breach.data_categories.health_data' => 'health_data',
                    'data_breach.data_categories.location_data' => 'location_data',
                    'data_breach.data_categories.online_identifiers' => 'online_identifiers',
                    'data_breach.data_categories.employment_data' => 'employment_data',
                    'data_breach.data_categories.education_data' => 'education_data',
                    'data_breach.data_categories.criminal_convictions' => 'criminal_convictions',
                    'data_breach.data_categories.biometric_data' => 'biometric_data',
                    'data_breach.data_categories.genetic_data' => 'genetic_data',
                    'data_breach.data_categories.other' => 'other',
                ],
                'choice_translation_domain' => 'privacy',
                'multiple' => true,
                'expanded' => false,
                'required' => true,
                'attr' => ['class' => 'form-select select2-multiple'],
                'help' => 'Art. 33(3)(a) GDPR - Select all applicable categories',
            ])
            ->add('dataSubjectCategories', ChoiceType::class, [
                'label' => 'data_breach.form.data_subject_categories',
                'choices' => [
                    'data_breach.data_subject_categories.customers' => 'customers',
                    'data_breach.data_subject_categories.employees' => 'employees',
                    'data_breach.data_subject_categories.applicants' => 'applicants',
                    'data_breach.data_subject_categories.visitors' => 'visitors',
                    'data_breach.data_subject_categories.patients' => 'patients',
                    'data_breach.data_subject_categories.students' => 'students',
                    'data_breach.data_subject_categories.suppliers' => 'suppliers',
                    'data_breach.data_subject_categories.minors' => 'minors',
                    'data_breach.data_subject_categories.vulnerable' => 'vulnerable',
                    'data_breach.data_subject_categories.other' => 'other',
                ],
                'choice_translation_domain' => 'privacy',
                'multiple' => true,
                'expanded' => false,
                'required' => true,
                'attr' => ['class' => 'form-select select2-multiple'],
                'help' => 'Art. 33(3)(a) GDPR',
            ])
            ->add('breachNature', TextareaType::class, [
                'label' => 'data_breach.form.breach_nature',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'Describe the nature of the personal data breach, including the circumstances...',
                ],
                'help' => 'Art. 33(3)(a) GDPR - Describe what happened',
            ])
            ->add('likelyConsequences', TextareaType::class, [
                'label' => 'data_breach.form.likely_consequences',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'Describe the likely consequences of the breach for data subjects...',
                ],
                'help' => 'Art. 33(3)(b) GDPR',
            ])
            ->add('measuresTaken', TextareaType::class, [
                'label' => 'data_breach.form.measures_taken',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'Describe measures taken or proposed to address the breach...',
                ],
                'help' => 'Art. 33(3)(c) & (d) GDPR',
            ])
            ->add('mitigationMeasures', TextareaType::class, [
                'label' => 'data_breach.form.mitigation_measures',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'Additional mitigation measures including remedies...',
                ],
                'help' => 'Art. 33(3)(d) GDPR - Measures to mitigate adverse effects',
            ])

            // ================================================================
            // SECTION 3: Risk Assessment
            // ================================================================
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'data_breach.form.risk_level',
                'choices' => [
                    'data_breach.risk_level.low' => 'low',
                    'data_breach.risk_level.medium' => 'medium',
                    'data_breach.risk_level.high' => 'high',
                    'data_breach.risk_level.critical' => 'critical',
                ],
                'choice_translation_domain' => 'privacy',
                'placeholder' => 'Assess risk level...',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'Assessment of risk to rights and freedoms',
            ])
            ->add('riskAssessment', TextareaType::class, [
                'label' => 'data_breach.form.risk_assessment',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'Detailed risk assessment...',
                ],
            ])
            ->add('specialCategoriesAffected', CheckboxType::class, [
                'label' => 'data_breach.form.special_categories_affected',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'Art. 9 GDPR - Racial/ethnic origin, political opinions, religious beliefs, health data, etc.',
            ])
            ->add('criminalDataAffected', CheckboxType::class, [
                'label' => 'data_breach.form.criminal_data_affected',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'Art. 10 GDPR - Criminal convictions and offences',
            ])

            // ================================================================
            // SECTION 4: Notification Requirements
            // ================================================================
            ->add('requiresAuthorityNotification', CheckboxType::class, [
                'label' => 'data_breach.form.requires_authority_notification',
                'required' => false,
                'data' => true, // Default to checked
                'attr' => ['class' => 'form-check-input'],
                'help' => 'Art. 33(1) GDPR - Notification required unless unlikely to result in risk',
            ])
            ->add('requiresSubjectNotification', CheckboxType::class, [
                'label' => 'data_breach.form.requires_subject_notification',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'Art. 34(1) GDPR - Required if breach likely to result in HIGH RISK',
            ])
            ->add('noSubjectNotificationReason', TextareaType::class, [
                'label' => 'data_breach.form.no_subject_notification_reason',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'class' => 'form-control',
                    'placeholder' => 'Explain why notification is not required (Art. 34(3))...',
                ],
                'help' => 'Art. 34(3) GDPR exemptions: (a) Protection measures, (b) Subsequent measures, (c) Disproportionate effort',
            ])

            // ================================================================
            // SECTION 5: Investigation & Follow-up
            // ================================================================
            ->add('rootCause', TextareaType::class, [
                'label' => 'data_breach.form.root_cause',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'Root cause analysis...',
                ],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'data_breach.form.lessons_learned',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'Lessons learned from this breach...',
                ],
            ])

            // ================================================================
            // SECTION 6: Responsible Persons
            // ================================================================
            ->add('dataProtectionOfficer', EntityType::class, [
                'label' => 'data_breach.form.data_protection_officer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return sprintf('%s %s (%s)', $user->getFirstName(), $user->getLastName(), $user->getEmail());
                },
                'placeholder' => 'Select DPO...',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'Data Protection Officer (Art. 35(4) GDPR)',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataBreach::class,
        ]);
    }
}
