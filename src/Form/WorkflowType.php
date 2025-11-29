<?php

namespace App\Form;

use App\Entity\Workflow;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkflowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'workflow.field.name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'workflow.placeholder.name'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow.validation.name_required'),
                    new Assert\Length(max: 255, maxMessage: 'workflow.validation.name_max_length')
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'workflow.field.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'workflow.placeholder.description'
                ]
            ])
            ->add('entityType', ChoiceType::class, [
                'label' => 'workflow.field.entity_type',
                'choices' => [
                    'workflow.entity_type.risk' => 'Risk',
                    'workflow.entity_type.control' => 'Control',
                    'workflow.entity_type.incident' => 'Incident',
                    'workflow.entity_type.asset' => 'Asset',
                    'workflow.entity_type.change_request' => 'ChangeRequest',
                    'workflow.entity_type.document' => 'Document',
                    'workflow.entity_type.audit' => 'InternalAudit',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow.validation.entity_type_required')
                ],
                'help' => 'workflow.help.entity_type',
                'choice_translation_domain' => 'workflows',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'workflow.field.is_active',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'workflow.help.is_active'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Workflow::class,
            'translation_domain' => 'workflows',
        ]);
    }
}
