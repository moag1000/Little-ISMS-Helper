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
                'label' => 'requirement.field.framework',
                'class' => ComplianceFramework::class,
                'choice_label' => 'name',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.framework_required'])
                ],
                'help' => 'requirement.help.framework'
            ])
            ->add('requirementId', TextType::class, [
                'label' => 'requirement.field.requirement_id',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'requirement.placeholder.requirement_id'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.requirement_id_required']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'requirement.validation.requirement_id_max_length'
                    ])
                ],
                'help' => 'requirement.help.requirement_id'
            ])
            ->add('title', TextType::class, [
                'label' => 'requirement.field.title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'requirement.placeholder.title'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.title_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'requirement.validation.title_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'requirement.field.description',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'requirement.placeholder.description'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.description_required'])
                ]
            ])
            ->add('category', TextType::class, [
                'label' => 'requirement.field.category',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'requirement.placeholder.category'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'requirement.validation.category_max_length'
                    ])
                ],
                'help' => 'requirement.help.category'
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'requirement.field.priority',
                'choices' => [
                    'requirement.priority.critical' => 'critical',
                    'requirement.priority.high' => 'high',
                    'requirement.priority.medium' => 'medium',
                    'requirement.priority.low' => 'low',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.priority_required'])
                ]
            ])
            ->add('requirementType', ChoiceType::class, [
                'label' => 'requirement.field.requirement_type',
                'choices' => [
                    'requirement.requirement_type.core' => 'core',
                    'requirement.requirement_type.detailed' => 'detailed',
                    'requirement.requirement_type.sub_requirement' => 'sub_requirement',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'requirement.validation.requirement_type_required'])
                ],
                'help' => 'requirement.help.requirement_type'
            ])
            ->add('parentRequirement', EntityType::class, [
                'label' => 'requirement.field.parent_requirement',
                'class' => ComplianceRequirement::class,
                'choice_label' => function(ComplianceRequirement $req) {
                    return $req->getRequirementId() . ' - ' . $req->getTitle();
                },
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'requirement.help.parent_requirement'
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
