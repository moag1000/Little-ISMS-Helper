<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DataSubjectRequest;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for Data Subject Request (GDPR Art. 15-22)
 */
class DataSubjectRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ================================================================
            // SECTION 1: Request Details
            // ================================================================
            ->add('requestType', ChoiceType::class, [
                'label' => 'dsr.form.request_type',
                'choices' => [
                    'dsr.type.access' => 'access',
                    'dsr.type.rectification' => 'rectification',
                    'dsr.type.erasure' => 'erasure',
                    'dsr.type.restriction' => 'restriction',
                    'dsr.type.portability' => 'portability',
                    'dsr.type.objection' => 'objection',
                    'dsr.type.automated_decision' => 'automated_decision',
                ],
                'placeholder' => 'dsr.form.placeholder.request_type',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'help' => 'dsr.form.help.request_type',
            ])
            ->add('receivedAt', DateTimeType::class, [
                'label' => 'dsr.form.received_at',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'help' => 'dsr.form.help.received_at',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'dsr.form.description',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'class' => 'form-control',
                    'placeholder' => 'dsr.form.placeholder.description',
                ],
            ])

            // ================================================================
            // SECTION 2: Data Subject Information
            // ================================================================
            ->add('dataSubjectName', TextType::class, [
                'label' => 'dsr.form.data_subject_name',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'dsr.form.placeholder.data_subject_name',
                ],
            ])
            ->add('dataSubjectEmail', TextType::class, [
                'label' => 'dsr.form.data_subject_email',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'dsr.form.placeholder.data_subject_email',
                ],
            ])
            ->add('dataSubjectIdentifier', TextType::class, [
                'label' => 'dsr.form.data_subject_identifier',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'dsr.form.placeholder.data_subject_identifier',
                ],
                'help' => 'dsr.form.help.data_subject_identifier',
            ])

            // ================================================================
            // SECTION 3: Identity Verification
            // ================================================================
            ->add('identityVerified', CheckboxType::class, [
                'label' => 'dsr.form.identity_verified',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('identityVerificationMethod', ChoiceType::class, [
                'label' => 'dsr.form.identity_verification_method',
                'choices' => [
                    'dsr.verification.id_document' => 'id_document',
                    'dsr.verification.email_verification' => 'email_verification',
                    'dsr.verification.account_login' => 'account_login',
                    'dsr.verification.other' => 'other',
                ],
                'placeholder' => 'dsr.form.placeholder.verification_method',
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-depends-on' => 'data_subject_request_identityVerified',
                ],
            ])

            // ================================================================
            // SECTION 4: Assignment & Links
            // ================================================================
            ->add('assignedTo', EntityType::class, [
                'label' => 'dsr.form.assigned_to',
                'class' => User::class,
                'choice_label' => fn(User $user): string => sprintf(
                    '%s %s (%s)',
                    $user->getFirstName(),
                    $user->getLastName(),
                    $user->getEmail()
                ),
                'placeholder' => 'dsr.form.placeholder.assigned_to',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
            ])
            ->add('assignedPerson', EntityType::class, [
                'label' => 'dsr.form.assigned_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'dsr.form.placeholder.assigned_person',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'dsr.form.help.assigned_person',
            ])
            ->add('assignedDeputyPersons', EntityType::class, [
                'label' => 'dsr.form.assigned_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'dsr.form.help.assigned_deputy_persons',
            ])
            ->add('processingActivity', EntityType::class, [
                'label' => 'dsr.form.processing_activity',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'placeholder' => 'dsr.form.placeholder.processing_activity',
                'required' => false,
                'attr' => ['class' => 'form-select select2'],
                'help' => 'dsr.form.help.processing_activity',
            ])

            // ================================================================
            // SECTION 5: Internal Notes
            // ================================================================
            ->add('notes', TextareaType::class, [
                'label' => 'dsr.form.notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'class' => 'form-control',
                    'placeholder' => 'dsr.form.placeholder.notes',
                ],
                'help' => 'dsr.form.help.notes',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataSubjectRequest::class,
            'translation_domain' => 'data_subject_request',
            'attr' => [
                'data-controller' => 'conditional-fields',
            ],
        ]);
    }
}
