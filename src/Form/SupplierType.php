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
                'label' => 'Supplier Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'Contact Person',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'Phone',
                'required' => false,
                'attr' => ['maxlength' => 50],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('serviceProvided', TextareaType::class, [
                'label' => 'Service Provided',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'Criticality',
                'choices' => [
                    'Critical' => 'critical',
                    'High' => 'high',
                    'Medium' => 'medium',
                    'Low' => 'low',
                ],
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Active' => 'active',
                    'Inactive' => 'inactive',
                    'Under Review' => 'under_review',
                    'Terminated' => 'terminated',
                ],
                'required' => true,
            ])
            ->add('securityScore', IntegerType::class, [
                'label' => 'Security Score (0-100)',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100],
            ])
            ->add('lastSecurityAssessment', DateType::class, [
                'label' => 'Last Security Assessment',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextAssessmentDate', DateType::class, [
                'label' => 'Next Assessment Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('assessmentFindings', TextareaType::class, [
                'label' => 'Assessment Findings',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('nonConformities', TextareaType::class, [
                'label' => 'Non-Conformities',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contractStartDate', DateType::class, [
                'label' => 'Contract Start Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('contractEndDate', DateType::class, [
                'label' => 'Contract End Date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('securityRequirements', TextareaType::class, [
                'label' => 'Security Requirements',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('hasISO27001', CheckboxType::class, [
                'label' => 'ISO 27001 Certified',
                'required' => false,
            ])
            ->add('hasISO22301', CheckboxType::class, [
                'label' => 'ISO 22301 Certified',
                'required' => false,
            ])
            ->add('certifications', TextareaType::class, [
                'label' => 'Other Certifications',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('hasDPA', CheckboxType::class, [
                'label' => 'Data Processing Agreement Signed',
                'required' => false,
            ])
            ->add('dpaSignedDate', DateType::class, [
                'label' => 'DPA Signed Date',
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
