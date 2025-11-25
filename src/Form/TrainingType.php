<?php

namespace App\Form;

use App\Entity\Training;
use App\Entity\User;
use App\Entity\Control;
use App\Entity\ComplianceRequirement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
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
                    new NotBlank(['message' => 'training.validation.title_required']),
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
            ])
            ->add('scheduledDate', DateTimeType::class, [
                'label' => 'training.field.scheduled_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'training.validation.date_required']),
                ],
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'training.field.duration',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 15,
                    'placeholder' => 'training.placeholder.duration',
                ],
                'constraints' => [
                    new Range(['min' => 15, 'max' => 480]),
                ],
                'help' => 'training.help.duration',
            ])
            ->add('location', TextType::class, [
                'label' => 'training.field.location',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'training.placeholder.location',
                ],
            ])
            ->add('trainer', EntityType::class, [
                'label' => 'training.field.trainer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'placeholder' => 'common.please_select',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('targetAudience', ChoiceType::class, [
                'label' => 'training.field.target_audience',
                'choices' => [
                    'training.target_audiences.all_employees' => 'all_employees',
                    'training.target_audiences.it_department' => 'it_department',
                    'training.target_audiences.management' => 'management',
                    'training.target_audiences.developers' => 'developers',
                    'training.target_audiences.hr' => 'hr',
                    'training.target_audiences.contractors' => 'contractors',
                    'training.target_audiences.new_employees' => 'new_employees',
                    'training.target_audiences.specific_departments' => 'specific_departments',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('participants', EntityType::class, [
                'label' => 'training.field.participants',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getDepartment() . ')';
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 8,
                ],
                'help' => 'training.help.participants',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'training.field.status',
                'choices' => [
                    'training.statuses.planned' => 'planned',
                    'training.statuses.confirmed' => 'confirmed',
                    'training.statuses.completed' => 'completed',
                    'training.statuses.cancelled' => 'cancelled',
                    'training.statuses.postponed' => 'postponed',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('mandatory', ChoiceType::class, [
                'label' => 'training.field.mandatory',
                'choices' => [
                    'training.mandatory_options.yes' => true,
                    'training.mandatory_options.no' => false,
                ],
                'expanded' => true,
                'data' => true,
            ])
            ->add('coveredControls', EntityType::class, [
                'label' => 'training.field.covered_controls',
                'class' => Control::class,
                'choice_label' => function (Control $control) {
                    return $control->getControlId() . ' - ' . $control->getName();
                },
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
                'choice_label' => function (ComplianceRequirement $requirement) {
                    $framework = $requirement->getFramework();
                    $frameworkName = $framework ? $framework->getName() : 'N/A';
                    return $frameworkName . ' - ' . $requirement->getRequirementId() . ': ' . $requirement->getTitle();
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
