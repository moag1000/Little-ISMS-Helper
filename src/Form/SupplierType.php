<?php

namespace App\Form;

use App\Entity\Supplier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SupplierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'supplier.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'supplier.placeholder.name',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'supplier.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.description',
                ],
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'supplier.field.contact_person',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'supplier.placeholder.contact_person',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'supplier.field.email',
                'required' => false,
                'attr' => [
                    'placeholder' => 'supplier.placeholder.email',
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'supplier.field.phone',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => 'supplier.placeholder.phone',
                ],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'supplier.field.address',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'supplier.placeholder.address',
                ],
            ])
            ->add('serviceProvided', TextareaType::class, [
                'label' => 'supplier.field.service_provided',
                'required' => true,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.service_provided',
                ],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'supplier.field.criticality',
                'choices' => [
                    'supplier.criticality.critical' => 'critical',
                    'supplier.criticality.high' => 'high',
                    'supplier.criticality.medium' => 'medium',
                    'supplier.criticality.low' => 'low',
                ],
                'choice_translation_domain' => 'suppliers',
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'supplier.field.status',
                'choices' => [
                    'supplier.status.active' => 'active',
                    'supplier.status.inactive' => 'inactive',
                    'supplier.status.evaluation' => 'evaluation',
                    'supplier.status.terminated' => 'terminated',
                ],
                'choice_translation_domain' => 'suppliers',
                'required' => true,
            ])
            ->add('securityScore', IntegerType::class, [
                'label' => 'supplier.field.security_score',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'placeholder' => 'supplier.placeholder.security_score',
                ],
            ])
            ->add('lastSecurityAssessment', DateType::class, [
                'label' => 'supplier.field.last_security_assessment',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextAssessmentDate', DateType::class, [
                'label' => 'supplier.field.next_assessment_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('assessmentFindings', TextareaType::class, [
                'label' => 'supplier.field.assessment_findings',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'supplier.placeholder.assessment_findings',
                ],
            ])
            ->add('nonConformities', TextareaType::class, [
                'label' => 'supplier.field.non_conformities',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.non_conformities',
                ],
            ])
            ->add('contractStartDate', DateType::class, [
                'label' => 'supplier.field.contract_start_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('contractEndDate', DateType::class, [
                'label' => 'supplier.field.contract_end_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('securityRequirements', TextareaType::class, [
                'label' => 'supplier.field.security_requirements',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'supplier.placeholder.security_requirements',
                ],
            ])
            ->add('hasISO27001', CheckboxType::class, [
                'label' => 'supplier.field.has_iso27001',
                'required' => false,
            ])
            ->add('hasISO22301', CheckboxType::class, [
                'label' => 'supplier.field.has_iso22301',
                'required' => false,
            ])
            ->add('certifications', TextareaType::class, [
                'label' => 'supplier.field.certifications',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'supplier.placeholder.certifications',
                ],
            ])
            ->add('hasDPA', CheckboxType::class, [
                'label' => 'supplier.field.has_dpa',
                'required' => false,
            ])
            ->add('dpaSignedDate', DateType::class, [
                'label' => 'supplier.field.dpa_signed_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Supplier::class,
        ]);
    }
}
