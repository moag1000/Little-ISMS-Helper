<?php

namespace App\Form;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
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
                    'placeholder' => 'data_breach.placeholder.title',
                    'class' => 'form-control',
                ],
            ])
            ->add('detectedAt', DateTimeType::class, [
                'label' => 'data_breach.form.detected_at',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'help' => 'data_breach.help.detected_at',
            ])
            ->add('incident', EntityType::class, [
                'label' => 'data_breach.form.incident',
                'class' => Incident::class,
                'choice_label' => function (Incident $incident) {
                    return sprintf('%s - %s', $incident->getReferenceNumber(), $incident->getTitle());
                },
                'placeholder' => 'data_breach.placeholder.incident',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'data_breach.help.incident',
            ])
            ->add('processingActivity', EntityType::class, [
                'label' => 'data_breach.form.processing_activity',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'placeholder' => 'data_breach.placeholder.processing_activity',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'data_breach.help.processing_activity',
            ])

            // ================================================================
            // SECTION 2: Art. 33(3) - Content of Notification
            // ================================================================
            ->add('affectedDataSubjects', IntegerType::class, [
                'label' => 'data_breach.form.affected_data_subjects',
                'required' => false,
                'attr' => [
                    'placeholder' => 'data_breach.placeholder.affected_data_subjects',
                    'class' => 'form-control',
                    'min' => 0,
                ],
                'help' => 'data_breach.help.affected_data_subjects',
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
                'help' => 'data_breach.help.data_categories',
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
                'help' => 'data_breach.help.data_subject_categories',
            ])
            ->add('breachNature', TextareaType::class, [
                'label' => 'data_breach.form.breach_nature',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.breach_nature',
                ],
                'help' => 'data_breach.help.breach_nature',
            ])
            ->add('likelyConsequences', TextareaType::class, [
                'label' => 'data_breach.form.likely_consequences',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.likely_consequences',
                ],
                'help' => 'data_breach.help.likely_consequences',
            ])
            ->add('measuresTaken', TextareaType::class, [
                'label' => 'data_breach.form.measures_taken',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.measures_taken',
                ],
                'help' => 'data_breach.help.measures_taken',
            ])
            ->add('mitigationMeasures', TextareaType::class, [
                'label' => 'data_breach.form.mitigation_measures',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.mitigation_measures',
                ],
                'help' => 'data_breach.help.mitigation_measures',
            ])

            // ================================================================
            // SECTION 3: Risk Assessment
            // ================================================================
            ->add('severity', ChoiceType::class, [
                'label' => 'data_breach.form.severity',
                'choices' => [
                    'data_breach.severity.low' => 'low',
                    'data_breach.severity.medium' => 'medium',
                    'data_breach.severity.high' => 'high',
                    'data_breach.severity.critical' => 'critical',
                ],
                'choice_translation_domain' => 'privacy',
                'placeholder' => 'data_breach.placeholder.severity',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'help' => 'data_breach.help.severity',
            ])
            ->add('riskLevel', ChoiceType::class, [
                'label' => 'data_breach.form.risk_level',
                'choices' => [
                    'data_breach.risk_level.low' => 'low',
                    'data_breach.risk_level.medium' => 'medium',
                    'data_breach.risk_level.high' => 'high',
                    'data_breach.risk_level.critical' => 'critical',
                ],
                'choice_translation_domain' => 'privacy',
                'placeholder' => 'data_breach.placeholder.risk_level',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'data_breach.help.risk_level',
            ])
            ->add('riskAssessment', TextareaType::class, [
                'label' => 'data_breach.form.risk_assessment',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.risk_assessment',
                ],
            ])
            ->add('specialCategoriesAffected', CheckboxType::class, [
                'label' => 'data_breach.form.special_categories_affected',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'data_breach.help.special_categories_affected',
            ])
            ->add('criminalDataAffected', CheckboxType::class, [
                'label' => 'data_breach.form.criminal_data_affected',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'data_breach.help.criminal_data_affected',
            ])

            // ================================================================
            // SECTION 4: Notification Requirements
            // ================================================================
            ->add('requiresAuthorityNotification', CheckboxType::class, [
                'label' => 'data_breach.form.requires_authority_notification',
                'required' => false,
                'data' => true, // Default to checked
                'attr' => ['class' => 'form-check-input'],
                'help' => 'data_breach.help.requires_authority_notification',
            ])
            ->add('requiresSubjectNotification', CheckboxType::class, [
                'label' => 'data_breach.form.requires_subject_notification',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'help' => 'data_breach.help.requires_subject_notification',
            ])
            ->add('noSubjectNotificationReason', TextareaType::class, [
                'label' => 'data_breach.form.no_subject_notification_reason',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.no_subject_notification_reason',
                ],
                'help' => 'data_breach.help.no_subject_notification_reason',
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
                    'placeholder' => 'data_breach.placeholder.root_cause',
                ],
            ])
            ->add('lessonsLearned', TextareaType::class, [
                'label' => 'data_breach.form.lessons_learned',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'data_breach.placeholder.lessons_learned',
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
                'placeholder' => 'data_breach.placeholder.data_protection_officer',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'data_breach.help.data_protection_officer',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataBreach::class,
            'translation_domain' => 'privacy',
        ]);
    }
}
