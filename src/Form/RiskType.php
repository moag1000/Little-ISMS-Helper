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
            ->add('title', TextType::class, [
                'label' => 'risk.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'risk.placeholder.title',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'risk.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.description',
                ],
                'help' => 'risk.help.description',
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'risk.field.category',
                'choices' => [
                    'risk.category.strategic' => 'strategic',
                    'risk.category.operational' => 'operational',
                    'risk.category.financial' => 'financial',
                    'risk.category.compliance' => 'compliance',
                    'risk.category.reputational' => 'reputational',
                    'risk.category.technology' => 'technology',
                ],
                'required' => true,
            ])
            ->add('identifiedDate', DateType::class, [
                'label' => 'risk.field.identified_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('identifiedBy', TextType::class, [
                'label' => 'risk.field.identified_by',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'risk.placeholder.identified_by',
                ],
            ])
            ->add('inherentProbability', IntegerType::class, [
                'label' => 'risk.field.inherent_probability',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.probability',
            ])
            ->add('inherentImpact', IntegerType::class, [
                'label' => 'risk.field.inherent_impact',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
            ])
            ->add('residualProbability', IntegerType::class, [
                'label' => 'risk.field.residual_probability',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.probability',
            ])
            ->add('residualImpact', IntegerType::class, [
                'label' => 'risk.field.residual_impact',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
            ])
            ->add('treatmentStrategy', ChoiceType::class, [
                'label' => 'risk.field.treatment_strategy',
                'choices' => [
                    'risk.treatment.mitigate' => 'mitigate',
                    'risk.treatment.transfer' => 'transfer',
                    'risk.treatment.accept' => 'accept',
                    'risk.treatment.avoid' => 'avoid',
                ],
                'required' => true,
                'help' => 'risk.help.treatment',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'risk.field.status',
                'choices' => [
                    'risk.status.identified' => 'identified',
                    'risk.status.assessed' => 'assessed',
                    'risk.status.in_treatment' => 'in_treatment',
                    'risk.status.monitored' => 'monitored',
                    'risk.status.closed' => 'closed',
                ],
                'required' => true,
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'risk.field.review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.review_date',
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
