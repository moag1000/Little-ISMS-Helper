<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ComplianceFramework;
use App\Entity\Training;
use App\Entity\Control;
use App\Entity\ComplianceRequirement;
use App\Form\Trait\OwnerPickerFormTrait;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class TrainingType extends AbstractType
{
    use OwnerPickerFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'training.field.title',
                'attr' => [
                    'placeholder' => 'training.placeholder.title',
                ],
                'constraints' => [
                    new NotBlank(message: 'training.validation.title_required'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'training.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'training.placeholder.description',
                ],
            ])
            ->add('trainingType', ChoiceType::class, [
                'label' => 'training.field.training_type',
                'choices' => [
                    'training.types.security_awareness' => 'security_awareness',
                    'training.types.technical' => 'technical',
                    'training.types.compliance' => 'compliance',
                    'training.types.emergency_drill' => 'emergency_drill',
                    'training.types.phishing_simulation' => 'phishing_simulation',
                    'training.types.data_protection' => 'data_protection',
                    'training.types.cyber_security' => 'cyber_security',
                    'training.types.other' => 'other',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
                'choice_translation_domain' => 'training',
            ])
            ->add('deliveryMethod', ChoiceType::class, [
                'label' => 'training.field.delivery_method',
                'choices' => [
                    'training.delivery_methods.in_person' => 'in_person',
                    'training.delivery_methods.online_live' => 'online_live',
                    'training.delivery_methods.e_learning' => 'e_learning',
                    'training.delivery_methods.hybrid' => 'hybrid',
                    'training.delivery_methods.workshop' => 'workshop',
                ],
                'choice_translation_domain' => 'training',
            ])
            ->add('scheduledDate', DateType::class, [
                'label' => 'training.field.scheduled_date',
                'widget' => 'single_text',
                'constraints' => [
                    new NotBlank(message: 'training.validation.date_required'),
                ],
            ])
            ->add('durationMinutes', IntegerType::class, [
                'label' => 'training.field.duration',
                'required' => false,
                'attr' => [
                    'min' => 15,
                    'placeholder' => 'training.placeholder.duration',
                ],
                'constraints' => [
                    new Range(min: 15, max: 480),
                ],
                'help' => 'training.help.duration',
            ])
            ->add('targetAudience', TextType::class, [
                'label' => 'training.field.target_audience',
                'required' => false,
                'attr' => [
                    'placeholder' => 'training.placeholder.target_audience',
                ],
                'help' => 'training.help.target_audience',
            ])
            // P-15 DataReuse: structured participantUsers Multi-Select.
            // Persisting flows through TrainingController which creates
            // TrainingParticipation rows on save (status=pending,
            // assignmentSource=manual:edit_form). Legacy `participants`
            // textarea kept read-only for migration data.
            ->add('participantUsers', EntityType::class, [
                'label' => 'training.field.participant_users',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'mapped' => true,
                'by_reference' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'training.help.participant_users',
            ])
            ->add('participants', TextareaType::class, [
                'label' => 'training.field.participants_legacy',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'training.placeholder.participants',
                ],
                'help' => 'training.help.participants_legacy',
            ])
            ->add('attendeeCount', IntegerType::class, [
                'label' => 'training.field.attendee_count',
                'required' => false,
                'attr' => [
                    'min' => 0,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'training.field.status',
                'choices' => [
                    'training.statuses.planned' => 'planned',
                    'training.statuses.scheduled' => 'scheduled',
                    'training.statuses.in_progress' => 'in_progress',
                    'training.statuses.completed' => 'completed',
                    'training.statuses.cancelled' => 'cancelled',
                ],
                'choice_translation_domain' => 'training',
            ])
            ->add('mandatory', ChoiceType::class, [
                'label' => 'training.field.mandatory',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'choice_translation_domain' => 'messages',
            ])
            ->add('coveredControls', EntityType::class, [
                'label' => 'training.field.covered_controls',
                'class' => Control::class,
                'choice_label' => fn(Control $control): string => $control->getControlId() . ' - ' . $control->getName(),
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'size' => 5,
                    'data-controller' => 'tom-select',
                ],
                'help' => 'training.help.covered_controls',
            ])
            ->add('complianceRequirements', EntityType::class, [
                'label' => 'training.field.compliance_requirements',
                'class' => ComplianceRequirement::class,
                'choice_label' => function (ComplianceRequirement $complianceRequirement): string {
                    $framework = $complianceRequirement->getFramework();
                    $frameworkName = $framework instanceof ComplianceFramework ? $framework->getName() : 'N/A';
                    return $frameworkName . ' - ' . $complianceRequirement->getRequirementId() . ': ' . $complianceRequirement->getTitle();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'training.help.compliance_requirements',
            ])
            ->add('materials', TextareaType::class, [
                'label' => 'training.field.materials',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'training.help.materials',
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'training.field.feedback',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'training.help.feedback',
            ])
            ->add('completionDate', DateType::class, [
                'label' => 'training.field.completion_date',
                'widget' => 'single_text',
                'required' => false,
            ]);

        // S4 P-1 Wave-2 — Trainer compound slot. Replaces the inline
        // 4-field block (trainerUser + trainerPerson + trainerDeputyPersons
        // + trainer legacy text). Legacy free-text `trainer` is preserved
        // as read-only Migration-Hint when populated.
        $this->addOwnerPicker($builder, [
            'field_prefix'       => 'trainer',
            'user_field'         => 'trainerUser',
            'person_field'       => 'trainerPerson',
            'deputies_field'     => 'trainerDeputyPersons',
            'legacy_field'       => 'trainer',
            'label_user'         => 'training.field.trainer',
            'label_person'       => 'training.field.trainer_person',
            'label_deputies'     => 'training.field.trainer_deputy_persons',
            'label_legacy'       => 'training.field.trainer_legacy',
            'placeholder_user'   => 'training.placeholder.trainer_user',
            'placeholder_person' => 'training.placeholder.trainer_person',
            'help_user'          => 'training.help.trainer_user',
            'help_person'        => 'training.help.trainer_person',
            'help_deputies'      => 'training.help.trainer_deputy_persons',
            'with_deputies'      => true,
            'with_legacy'        => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Training::class,
            'translation_domain' => 'training',
            'constraints' => [
                new Callback([$this, 'validateTrainerSlot']),
            ],
        ]);
    }

    public function validateTrainerSlot(?Training $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getTrainerUser() === null && $entity->getTrainerPerson() === null) {
            $context->buildViolation('training.error.owner_required_user_or_person')
                ->atPath('trainerUser')
                ->addViolation();
        }
    }
}
