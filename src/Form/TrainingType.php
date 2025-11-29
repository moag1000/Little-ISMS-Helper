<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use App\Entity\Training;
use App\Entity\Control;
use App\Entity\ComplianceRequirement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class TrainingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'training.field.title',
                'attr' => [
                    'class' => 'form-control',
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
                    'class' => 'form-control',
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
                'attr' => ['class' => 'form-select'],
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
                'attr' => ['class' => 'form-select'],
                'choice_translation_domain' => 'training',
            ])
            ->add('scheduledDate', DateType::class, [
                'label' => 'training.field.scheduled_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(message: 'training.validation.date_required'),
                ],
            ])
            ->add('durationMinutes', IntegerType::class, [
                'label' => 'training.field.duration',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 15,
                    'placeholder' => 'training.placeholder.duration',
                ],
                'constraints' => [
                    new Range(min: 15, max: 480),
                ],
                'help' => 'training.help.duration',
            ])
            ->add('trainer', TextType::class, [
                'label' => 'training.field.trainer',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'training.placeholder.trainer',
                ],
                'constraints' => [
                    new NotBlank(message: 'training.validation.trainer_required'),
                ],
            ])
            ->add('targetAudience', TextType::class, [
                'label' => 'training.field.target_audience',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'training.placeholder.target_audience',
                ],
                'help' => 'training.help.target_audience',
            ])
            ->add('participants', TextareaType::class, [
                'label' => 'training.field.participants',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'training.placeholder.participants',
                ],
                'help' => 'training.help.participants',
            ])
            ->add('attendeeCount', IntegerType::class, [
                'label' => 'training.field.attendee_count',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
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
                'attr' => ['class' => 'form-select'],
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
                    'class' => 'form-select',
                    'size' => 5,
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
                    'class' => 'form-select',
                    'size' => 5,
                ],
                'help' => 'training.help.compliance_requirements',
            ])
            ->add('materials', TextareaType::class, [
                'label' => 'training.field.materials',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'training.help.materials',
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'training.field.feedback',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'training.help.feedback',
            ])
            ->add('completionDate', DateType::class, [
                'label' => 'training.field.completion_date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Training::class,
            'translation_domain' => 'training',
        ]);
    }
}
