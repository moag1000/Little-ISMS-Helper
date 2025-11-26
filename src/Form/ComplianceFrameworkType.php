<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                'label' => 'framework.field.code',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'framework.placeholder.code'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'framework.validation.code_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'framework.validation.code_max_length'
                    ])
                ],
                'help' => 'framework.help.code'
            ])
            ->add('name', TextType::class, [
                'label' => 'framework.field.name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'framework.placeholder.name'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'framework.validation.name_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'framework.validation.name_max_length'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'framework.field.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'framework.placeholder.description'
                ]
            ])
            ->add('version', TextType::class, [
                'label' => 'framework.field.version',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'framework.placeholder.version'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'framework.validation.version_required']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'framework.validation.version_max_length'
                    ])
                ],
                'help' => 'framework.help.version'
            ])
            ->add('applicableIndustry', ChoiceType::class, [
                'label' => 'framework.field.industry',
                'choices' => [
                    'framework.industry.all_sectors' => 'all_sectors',
                    'framework.industry.automotive' => 'automotive',
                    'framework.industry.financial_services' => 'financial_services',
                    'framework.industry.healthcare' => 'healthcare',
                    'framework.industry.telecommunications' => 'telecommunications',
                    'framework.industry.pharmaceutical' => 'pharmaceutical',
                    'framework.industry.cloud_services' => 'cloud_services',
                    'framework.industry.critical_infrastructure' => 'critical_infrastructure',
                    'framework.industry.energy' => 'energy',
                    'framework.industry.manufacturing' => 'manufacturing',
                    'framework.industry.retail' => 'retail',
                    'framework.industry.transportation' => 'transportation',
                    'framework.industry.public_sector' => 'public_sector',
                    'framework.industry.education' => 'education',
                    'framework.industry.insurance' => 'insurance',
                ],
                'choice_translation_domain' => 'compliance',
                'attr' => [
                    'class' => 'form-control form-select',
                ],
                'placeholder' => 'framework.placeholder.select_industry',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'framework.validation.applicable_industry_required']),
                ],
                'help' => 'framework.help.applicable_industry',
                'choice_translation_domain' => 'compliance',
            ])
            ->add('regulatoryBody', TextType::class, [
                'label' => 'framework.field.regulatory_body',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'framework.placeholder.regulatory_body'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'framework.validation.regulatory_body_required']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'framework.validation.regulatory_body_max_length'
                    ])
                ],
                'help' => 'framework.help.regulatory_body'
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'framework.field.mandatory',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'framework.help.mandatory'
            ])
            ->add('scopeDescription', TextareaType::class, [
                'label' => 'framework.field.scope',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'framework.placeholder.scope_description'
                ]
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'framework.field.active',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'framework.help.active'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceFramework::class,
            'translation_domain' => 'compliance',
        ]);
    }
}
