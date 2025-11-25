<?php

namespace App\Form;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Risk;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class BusinessProcessType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'business_process.field.name',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'business_process.field.description',
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('processOwner', TextType::class, [
                'label' => 'business_process.field.process_owner',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank()],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'business_process.field.criticality',
                'choices' => [
                    'bcm.criticality.critical' => 'critical',
                    'bcm.criticality.high' => 'high',
                    'bcm.criticality.medium' => 'medium',
                    'bcm.impact.low' => 'low',
                ],
                'choice_translation_domain' => 'bcm',
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('rto', IntegerType::class, [
                'label' => 'business_process.field.rto',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rto',
                ],
                'help' => 'business_process.help.rto',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('rpo', IntegerType::class, [
                'label' => 'business_process.field.rpo',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rpo',
                ],
                'help' => 'business_process.help.rpo',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('mtpd', IntegerType::class, [
                'label' => 'business_process.field.mtpd',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.mtpd',
                ],
                'help' => 'business_process.help.mtpd',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('financialImpactPerHour', MoneyType::class, [
                'label' => 'business_process.field.financial_impact_per_hour',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('financialImpactPerDay', MoneyType::class, [
                'label' => 'business_process.field.financial_impact_per_day',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('reputationalImpact', ChoiceType::class, [
                'label' => 'business_process.field.reputational_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'bcm',
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('regulatoryImpact', ChoiceType::class, [
                'label' => 'business_process.field.regulatory_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'bcm',
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('operationalImpact', ChoiceType::class, [
                'label' => 'business_process.field.operational_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'bcm',
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('dependenciesUpstream', TextareaType::class, [
                'label' => 'business_process.field.dependencies_upstream',
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'help' => 'business_process.help.dependencies_upstream',
                'required' => false,
            ])
            ->add('dependenciesDownstream', TextareaType::class, [
                'label' => 'business_process.field.dependencies_downstream',
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'help' => 'business_process.help.dependencies_downstream',
                'required' => false,
            ])
            ->add('recoveryStrategy', TextareaType::class, [
                'label' => 'business_process.field.recovery_strategy',
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('supportingAssets', EntityType::class, [
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'label' => 'business_process.field.supporting_assets',
                'attr' => ['class' => 'form-select', 'size' => 5],
                'help' => 'business_process.help.supporting_assets',
            ])
            ->add('identifiedRisks', EntityType::class, [
                'class' => Risk::class,
                'choice_label' => 'title',
                'multiple' => true,
                'required' => false,
                'label' => 'business_process.field.identified_risks',
                'attr' => ['class' => 'form-select', 'size' => 5],
                'help' => 'business_process.help.identified_risks',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessProcess::class,
            'translation_domain' => 'business_process',
        ]);
    }
}
