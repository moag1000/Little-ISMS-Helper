<?php

declare(strict_types=1);

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

final class ConsentType extends AbstractType
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
                ],
                'help' => 'consent.form.data_subject_identifier_help',
            ])
            ->add('identifierType', ChoiceType::class, [
                'label' => 'consent.form.identifier_type_label',
                'choices' => [
                    'consent.form.identifier_type_options.email' => 'email',
                    'consent.form.identifier_type_options.customer_id' => 'customer_id',
                    'consent.form.identifier_type_options.pseudonym' => 'pseudonym',
                    'consent.form.identifier_type_options.phone' => 'phone',
                    'consent.form.identifier_type_options.other' => 'other',
                ],
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
                        ->setParameter('legal_basis', 'consent')
                        ->orderBy('p.name', 'ASC');
                },
                'help' => 'consent.form.processing_activity_help',
            ])
            ->add('purposes', ChoiceType::class, [
                'label' => 'consent.form.purposes',
                'choices' => [
                    'consent.form.purposes_options.marketing' => 'marketing',
                    'consent.form.purposes_options.profiling' => 'profiling',
                    'consent.form.purposes_options.analytics' => 'analytics',
                    'consent.form.purposes_options.newsletter' => 'newsletter',
                    'consent.form.purposes_options.personalization' => 'personalization',
                ],
                'multiple' => true,
                'required' => false,
                'help' => 'consent.form.purposes_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 3: Consent Grant
            // ═══════════════════════════════════════════════════════════
            ->add('grantedAt', DateTimeType::class, [
                'label' => 'consent.form.granted_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'consent.form.granted_at_help',
            ])
            ->add('consentMethod', ChoiceType::class, [
                'label' => 'consent.form.consent_method',
                'choices' => [
                    'consent.form.consent_method_options.double_opt_in' => 'double_opt_in',
                    'consent.form.consent_method_options.written_form' => 'written_form',
                    'consent.form.consent_method_options.checkbox' => 'checkbox',
                    'consent.form.consent_method_options.oral' => 'oral',
                    'consent.form.consent_method_options.email' => 'email',
                    'consent.form.consent_method_options.other' => 'other',
                ],
            ])
            ->add('consentChannel', ChoiceType::class, [
                'label' => 'consent.form.consent_channel',
                'choices' => [
                    'consent.form.consent_channel_options.website' => 'website',
                    'consent.form.consent_channel_options.email' => 'email',
                    'consent.form.consent_channel_options.paper_form' => 'paper_form',
                    'consent.form.consent_channel_options.phone' => 'phone',
                    'consent.form.consent_channel_options.in_person' => 'in_person',
                    'consent.form.consent_channel_options.other' => 'other',
                ],
                'required' => false,
            ])
            ->add('consentText', TextareaType::class, [
                'label' => 'consent.form.consent_text',
                'attr' => [
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
                'choice_label' => 'originalFilename',
                'required' => false,
                'help' => 'consent.form.proof_document_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 5: Expiry (optional)
            // ═══════════════════════════════════════════════════════════
            ->add('expiresAt', DateTimeType::class, [
                'label' => 'consent.form.expires_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'consent.form.expires_at_help',
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 6: Notes
            // ═══════════════════════════════════════════════════════════
            ->add('notes', TextareaType::class, [
                'label' => 'consent.form.notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'consent.form.notes_placeholder',
                ],
            ])

            // ═══════════════════════════════════════════════════════════
            // Section 7: Withdrawal — GDPR Art. 7(3)
            // ═══════════════════════════════════════════════════════════
            ->add('withdrawnAt', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'consent.field.withdrawn_at',
                'required' => false,
                'input' => 'datetime_immutable',
                'help' => 'consent.help.withdrawn_at',
            ])
            ->add('withdrawalReason', TextareaType::class, [
                'label' => 'consent.field.withdrawal_reason',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'consent.placeholder.withdrawal_reason',
                ],
            ])
            ->add('withdrawalChannel', ChoiceType::class, [
                'label' => 'consent.field.withdrawal_channel',
                'choices' => [
                    'consent.withdrawal_channel.web' => 'web',
                    'consent.withdrawal_channel.email' => 'email',
                    'consent.withdrawal_channel.phone' => 'phone',
                    'consent.withdrawal_channel.letter' => 'letter',
                    'consent.withdrawal_channel.in_person' => 'in_person',
                ],
                'required' => false,
                'placeholder' => 'consent.placeholder.withdrawal_channel',
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
