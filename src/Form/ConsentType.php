<?php

namespace App\Form;

use App\Entity\Consent;
use App\Entity\ProcessingActivity;
use App\Entity\Document;
use App\Repository\ProcessingActivityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConsentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ═══════════════════════════════════════════════════════════
            // Section 1: Data Subject
            // ═══════════════════════════════════════════════════════════
            ->add('dataSubjectIdentifier', TextType::class, [
                'label' => 'consent.form.data_subject_identifier',
                'attr' => [
                    'placeholder' => 'consent.form.data_subject_identifier_placeholder',
                    'class' => 'form-control',
                ],
                'help' => 'consent.form.data_subject_identifier_help',
            ])
            ->add('identifierType', ChoiceType::class, [
                'label' => 'consent.form.identifier_type',
                'choices' => [
                    'consent.form.identifier_type.email' => 'email',
                    'consent.form.identifier_type.customer_id' => 'customer_id',
                    'consent.form.identifier_type.pseudonym' => 'pseudonym',
                    'consent.form.identifier_type.phone' => 'phone',
                    'consent.form.identifier_type.other' => 'other',
                ],
                'attr' => ['class' => 'form-select'],
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 2: Processing Activity
            // ═══════════════════════════════════════════════════════════
            ->add('processingActivity', EntityType::class, [
                'label' => 'consent.form.processing_activity',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'query_builder' => function (ProcessingActivityRepository $repo) {
                    return $repo->createQueryBuilder('p')
                        ->where('p.legalBasis = :legal_basis')
                        ->setParameter('legal_basis', 'Art_6_1_a')
                        ->orderBy('p.name', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
                'help' => 'consent.form.processing_activity_help',
            ])
            ->add('purposes', ChoiceType::class, [
                'label' => 'consent.form.purposes',
                'choices' => [
                    'consent.form.purposes.marketing' => 'marketing',
                    'consent.form.purposes.profiling' => 'profiling',
                    'consent.form.purposes.analytics' => 'analytics',
                    'consent.form.purposes.newsletter' => 'newsletter',
                    'consent.form.purposes.personalization' => 'personalization',
                ],
                'multiple' => true,
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'consent.form.purposes_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 3: Consent Grant
            // ═══════════════════════════════════════════════════════════
            ->add('grantedAt', DateTimeType::class, [
                'label' => 'consent.form.granted_at',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'help' => 'consent.form.granted_at_help',
            ])
            ->add('consentMethod', ChoiceType::class, [
                'label' => 'consent.form.consent_method',
                'choices' => [
                    'consent.form.consent_method.double_opt_in' => 'double_opt_in',
                    'consent.form.consent_method.written_form' => 'written_form',
                    'consent.form.consent_method.checkbox' => 'checkbox',
                    'consent.form.consent_method.oral' => 'oral',
                    'consent.form.consent_method.email' => 'email',
                    'consent.form.consent_method.other' => 'other',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('consentChannel', ChoiceType::class, [
                'label' => 'consent.form.consent_channel',
                'choices' => [
                    'consent.form.consent_channel.website' => 'website',
                    'consent.form.consent_channel.email' => 'email',
                    'consent.form.consent_channel.paper_form' => 'paper_form',
                    'consent.form.consent_channel.phone' => 'phone',
                    'consent.form.consent_channel.in_person' => 'in_person',
                    'consent.form.consent_channel.other' => 'other',
                ],
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('consentText', TextareaType::class, [
                'label' => 'consent.form.consent_text',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'consent.form.consent_text_placeholder',
                ],
                'help' => 'consent.form.consent_text_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 4: Proof Documentation
            // ═══════════════════════════════════════════════════════════
            ->add('proofDocument', EntityType::class, [
                'label' => 'consent.form.proof_document',
                'class' => Document::class,
                'choice_label' => 'title',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'consent.form.proof_document_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 5: Expiry (optional)
            // ═══════════════════════════════════════════════════════════
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'consent.form.expires_at',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'help' => 'consent.form.expires_at_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 6: Notes
            // ═══════════════════════════════════════════════════════════
            ->add('notes', TextareaType::class, [
                'label' => 'consent.form.notes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'consent.form.notes_placeholder',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Consent::class,
        ]);
    }
}
