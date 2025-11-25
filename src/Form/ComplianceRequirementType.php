<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ComplianceRequirementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('framework', EntityType::class, [
                'label' => 'compliance_requirement.field.framework',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.framework_required'])
                ],
                'help' => 'compliance_requirement.help.framework'
            ])
            ->add('requirementId', TextType::class, [
                'label' => 'compliance_requirement.field.requirement_id',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_requirement.placeholder.requirement_id'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.requirement_id_required']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'compliance_requirement.validation.requirement_id_max_length'
                    ])
                ],
                'help' => 'compliance_requirement.help.requirement_id'
            ])
            ->add('title', TextType::class, [
                'label' => 'compliance_requirement.field.title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_requirement.placeholder.title'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.title_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'compliance_requirement.validation.title_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'compliance_requirement.field.description',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'compliance_requirement.placeholder.description'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.description_required'])
                ]
            ])
            ->add('category', TextType::class, [
                'label' => 'compliance_requirement.field.category',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_requirement.placeholder.category'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'compliance_requirement.validation.category_max_length'
                    ])
                ],
                'help' => 'compliance_requirement.help.category'
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'compliance_requirement.field.priority',
                'choices' => [
                    'compliance_requirement.priority.critical' => 'critical',
                    'compliance_requirement.priority.high' => 'high',
                    'compliance_requirement.priority.medium' => 'medium',
                    'compliance_requirement.priority.low' => 'low',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.priority_required'])
                ]
            ])
            ->add('requirementType', ChoiceType::class, [
                'label' => 'compliance_requirement.field.requirement_type',
                'choices' => [
                    'compliance_requirement.requirement_type.core' => 'core',
                    'compliance_requirement.requirement_type.detailed' => 'detailed',
                    'compliance_requirement.requirement_type.sub_requirement' => 'sub_requirement',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_requirement.validation.requirement_type_required'])
                ],
                'help' => 'compliance_requirement.help.requirement_type'
            ])
            ->add('parentRequirement', EntityType::class, [
                'label' => 'compliance_requirement.field.parent_requirement',
                'class' => ComplianceRequirement::class,
                'choice_label' => function(ComplianceRequirement $req) {
                    return $req->getRequirementId() . ' - ' . $req->getTitle();
                },
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'compliance_requirement.help.parent_requirement'
            ])
            // Note: applicable, applicabilityJustification, and fulfillmentPercentage
            // are now tenant-specific and managed via ComplianceRequirementFulfillment.
            // Use the "Quick Update" form on the requirement show page to edit fulfillment data.
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceRequirement::class,
            'translation_domain' => 'compliance',
        ]);
    }
}
