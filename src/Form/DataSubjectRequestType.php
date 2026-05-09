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
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
                'help' => 'dsr.form.help.request_type',
            ])
            ->add('receivedAt', DateTimeType::class, [
                'label' => 'dsr.form.received_at',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
                'help' => 'dsr.form.help.received_at',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'dsr.form.description',
                'required' => true,
                'attr' => [
                    'rows' => 5,
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
                    'placeholder' => 'dsr.form.placeholder.data_subject_name',
                ],
            ])
            ->add('dataSubjectEmail', TextType::class, [
                'label' => 'dsr.form.data_subject_email',
                'required' => false,
                'attr' => [
                    'placeholder' => 'dsr.form.placeholder.data_subject_email',
                ],
            ])
            ->add('dataSubjectIdentifier', TextType::class, [
                'label' => 'dsr.form.data_subject_identifier',
                'required' => false,
                'attr' => [
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
                'attr' => ['data-controller' => 'tom-select'],
            ])
            ->add('assignedPerson', EntityType::class, [
                'label' => 'dsr.form.assigned_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'dsr.form.placeholder.assigned_person',
                'required' => false,
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
                'attr' => ['data-controller' => 'tom-select'],
                'help' => 'dsr.form.help.processing_activity',
            ])

            // ================================================================
            // SECTION 5: Response Tracking (GDPR Art. 12(3))
            // ================================================================
            ->add('responseAt', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'dsr.form.response_at',
                'required' => false,
                'input' => 'datetime_immutable',
                'help' => 'dsr.form.help.response_at',
            ])
            ->add('extendedDeadlineAt', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'dsr.form.extended_deadline',
                'required' => false,
                'input' => 'datetime_immutable',
                'help' => 'dsr.form.help.extended_deadline',
            ])
            ->add('extensionReason', TextareaType::class, [
                'label' => 'dsr.form.extension_reason',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'dsr.form.placeholder.extension_reason',
                ],
                'help' => 'dsr.form.help.extension_reason',
            ])
            ->add('responseDocument', TextType::class, [
                'label' => 'dsr.form.response_document',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'dsr.form.placeholder.response_document',
                ],
                'help' => 'dsr.form.help.response_document',
            ])
            ->add('responseMethod', ChoiceType::class, [
                'label' => 'dsr.form.response_method',
                'required' => false,
                'placeholder' => 'dsr.form.placeholder.response_method',
                'choices' => [
                    'dsr.response_method.email' => 'email',
                    'dsr.response_method.letter' => 'letter',
                    'dsr.response_method.portal' => 'portal',
                    'dsr.response_method.in_person' => 'in_person',
                ],
            ])
            ->add('rejectionReason', TextareaType::class, [
                'label' => 'dsr.form.rejection_reason',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'dsr.form.placeholder.rejection_reason',
                ],
                'help' => 'dsr.form.help.rejection_reason',
            ])

            // ================================================================
            // SECTION 6: Internal Notes
            // ================================================================
            ->add('notes', TextareaType::class, [
                'label' => 'dsr.form.notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
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
            'constraints' => [
                new Callback([$this, 'validateAssignedSlot']),
            ],
        ]);
    }

    public function validateAssignedSlot(?DataSubjectRequest $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getAssignedTo() === null && $entity->getAssignedPerson() === null) {
            $context->buildViolation('dsr.error.owner_required_user_or_person')
                ->atPath('assignedTo')
                ->addViolation();
        }
    }
}
