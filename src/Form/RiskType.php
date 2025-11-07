<?php

namespace App\Form;

use App\Entity\Risk;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RiskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Risk Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'choices' => [
                    'Strategic' => 'strategic',
                    'Operational' => 'operational',
                    'Financial' => 'financial',
                    'Compliance' => 'compliance',
                    'Reputational' => 'reputational',
                    'Technology' => 'technology',
                ],
                'required' => true,
            ])
            ->add('identifiedDate', DateType::class, [
                'label' => 'Identified Date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('identifiedBy', TextType::class, [
                'label' => 'Identified By',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('inherentProbability', IntegerType::class, [
                'label' => 'Inherent Probability (1-5)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('inherentImpact', IntegerType::class, [
                'label' => 'Inherent Impact (1-5)',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('residualProbability', IntegerType::class, [
                'label' => 'Residual Probability (1-5)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('residualImpact', IntegerType::class, [
                'label' => 'Residual Impact (1-5)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('treatmentStrategy', ChoiceType::class, [
                'label' => 'Treatment Strategy',
                'choices' => [
                    'Mitigate' => 'mitigate',
                    'Transfer' => 'transfer',
                    'Accept' => 'accept',
                    'Avoid' => 'avoid',
                ],
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Identified' => 'identified',
                    'Assessed' => 'assessed',
                    'In Treatment' => 'in_treatment',
                    'Monitored' => 'monitored',
                    'Closed' => 'closed',
                ],
                'required' => true,
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'Review Date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Risk::class,
        ]);
    }
}
