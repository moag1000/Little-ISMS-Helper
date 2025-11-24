<?php

namespace App\Form;

use App\Entity\ComplianceMapping;
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

class ComplianceMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceRequirement', EntityType::class, [
                'label' => 'compliance_mapping.field.source_requirement',
                'class' => ComplianceRequirement::class,
                'choice_label' => function(ComplianceRequirement $req) {
                    return $req->getFramework()->getCode() . ' - ' . $req->getRequirementId() . ': ' . $req->getTitle();
                },
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_mapping.validation.source_requirement_required'])
                ],
                'help' => 'compliance_mapping.help.source_requirement'
            ])
            ->add('targetRequirement', EntityType::class, [
                'label' => 'compliance_mapping.field.target_requirement',
                'class' => ComplianceRequirement::class,
                'choice_label' => function(ComplianceRequirement $req) {
                    return $req->getFramework()->getCode() . ' - ' . $req->getRequirementId() . ': ' . $req->getTitle();
                },
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_mapping.validation.target_requirement_required'])
                ],
                'help' => 'compliance_mapping.help.target_requirement'
            ])
            ->add('mappingPercentage', IntegerType::class, [
                'label' => 'compliance_mapping.field.mapping_percentage',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 150,
                    'placeholder' => '0-150'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_mapping.validation.mapping_percentage_required']),
                    new Assert\Range([
                        'min' => 0,
                        'max' => 150,
                        'notInRangeMessage' => 'compliance_mapping.validation.mapping_percentage_range'
                    ])
                ],
                'help' => 'compliance_mapping.help.mapping_percentage'
            ])
            ->add('mappingType', ChoiceType::class, [
                'label' => 'compliance_mapping.field.mapping_type',
                'choices' => [
                    'compliance_mapping.mapping_type.weak' => 'weak',
                    'compliance_mapping.mapping_type.partial' => 'partial',
                    'compliance_mapping.mapping_type.full' => 'full',
                    'compliance_mapping.mapping_type.exceeds' => 'exceeds',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_mapping.validation.mapping_type_required'])
                ],
                'help' => 'compliance_mapping.help.mapping_type'
            ])
            ->add('mappingRationale', TextareaType::class, [
                'label' => 'compliance_mapping.field.mapping_rationale',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'compliance_mapping.placeholder.mapping_rationale'
                ],
                'help' => 'compliance_mapping.help.mapping_rationale'
            ])
            ->add('bidirectional', CheckboxType::class, [
                'label' => 'compliance_mapping.field.bidirectional',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'compliance_mapping.help.bidirectional'
            ])
            ->add('confidence', ChoiceType::class, [
                'label' => 'compliance_mapping.field.confidence',
                'choices' => [
                    'compliance_mapping.confidence.low' => 'low',
                    'compliance_mapping.confidence.medium' => 'medium',
                    'compliance_mapping.confidence.high' => 'high',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_mapping.validation.confidence_required'])
                ],
                'help' => 'compliance_mapping.help.confidence'
            ])
            ->add('verifiedBy', TextType::class, [
                'label' => 'compliance_mapping.field.verified_by',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_mapping.placeholder.verified_by'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'compliance_mapping.validation.verified_by_max_length'
                    ])
                ],
                'help' => 'compliance_mapping.help.verified_by'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceMapping::class,
        ]);
    }
}
