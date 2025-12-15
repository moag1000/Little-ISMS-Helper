<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkflowInstanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workflow', EntityType::class, [
                'label' => 'workflow_instance.field.workflow',
                'class' => Workflow::class,
                'choice_label' => 'name',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_instance.validation.workflow_required')
                ],
                'help' => 'workflow_instance.help.workflow'
            ])
            ->add('entityType', TextType::class, [
                'label' => 'workflow_instance.field.entity_type',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'workflow_instance.placeholder.entity_type'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_instance.validation.entity_type_required'),
                    new Assert\Length(max: 100, maxMessage: 'workflow_instance.validation.entity_type_max_length')
                ],
                'help' => 'workflow_instance.help.entity_type'
            ])
            ->add('entityId', IntegerType::class, [
                'label' => 'workflow_instance.field.entity_id',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'workflow_instance.placeholder.entity_id'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_instance.validation.entity_id_required'),
                    new Assert\Positive(message: 'workflow_instance.validation.entity_id_positive')
                ],
                'help' => 'workflow_instance.help.entity_id'
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'workflow_instance.field.status',
                'choices' => [
                    'workflow_instance.status.pending' => 'pending',
                    'workflow_instance.status.in_progress' => 'in_progress',
                    'workflow_instance.status.approved' => 'approved',
                    'workflow_instance.status.rejected' => 'rejected',
                    'workflow_instance.status.cancelled' => 'cancelled',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_instance.validation.status_required')
                ],
                'choice_translation_domain' => 'workflows',
            ])
            ->add('initiatedBy', EntityType::class, [
                'label' => 'workflow_instance.field.initiated_by',
                'class' => User::class,
                'choice_label' => 'email',
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'workflow_instance.help.initiated_by'
            ])
            ->add('currentStep', EntityType::class, [
                'label' => 'workflow_instance.field.current_step',
                'class' => WorkflowStep::class,
                'choice_label' => 'name',
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'workflow_instance.help.current_step'
            ])
            ->add('comments', TextareaType::class, [
                'label' => 'workflow_instance.field.comments',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'workflow_instance.placeholder.comments'
                ]
            ])
            ->add('dueDate', DateTimeType::class, [
                'label' => 'workflow_instance.field.due_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'workflow_instance.help.due_date'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkflowInstance::class,
            'translation_domain' => 'workflows',
        ]);
    }
}
