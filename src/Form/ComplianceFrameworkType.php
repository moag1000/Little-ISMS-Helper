<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ComplianceFrameworkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'compliance_framework.field.code',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_framework.placeholder.code'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_framework.validation.code_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'compliance_framework.validation.code_max_length'
                    ])
                ],
                'help' => 'compliance_framework.help.code'
            ])
            ->add('name', TextType::class, [
                'label' => 'compliance_framework.field.name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_framework.placeholder.name'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_framework.validation.name_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'compliance_framework.validation.name_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'compliance_framework.field.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'compliance_framework.placeholder.description'
                ]
            ])
            ->add('version', TextType::class, [
                'label' => 'compliance_framework.field.version',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_framework.placeholder.version'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_framework.validation.version_required']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'compliance_framework.validation.version_max_length'
                    ])
                ],
                'help' => 'compliance_framework.help.version'
            ])
            ->add('applicableIndustry', TextType::class, [
                'label' => 'compliance_framework.field.applicable_industry',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_framework.placeholder.applicable_industry'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_framework.validation.applicable_industry_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'compliance_framework.validation.applicable_industry_max_length'
                    ])
                ],
                'help' => 'compliance_framework.help.applicable_industry'
            ])
            ->add('regulatoryBody', TextType::class, [
                'label' => 'compliance_framework.field.regulatory_body',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'compliance_framework.placeholder.regulatory_body'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'compliance_framework.validation.regulatory_body_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'compliance_framework.validation.regulatory_body_max_length'
                    ])
                ],
                'help' => 'compliance_framework.help.regulatory_body'
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'compliance_framework.field.mandatory',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'compliance_framework.help.mandatory'
            ])
            ->add('scopeDescription', TextareaType::class, [
                'label' => 'compliance_framework.field.scope_description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'compliance_framework.placeholder.scope_description'
                ]
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'compliance_framework.field.active',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'compliance_framework.help.active'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceFramework::class,
        ]);
    }
}
