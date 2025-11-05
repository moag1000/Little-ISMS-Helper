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
                'label' => 'Prozessname',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('processOwner', TextType::class, [
                'label' => 'Prozessverantwortlicher',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank()],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'Kritikalität',
                'choices' => [
                    'Kritisch' => 'critical',
                    'Hoch' => 'high',
                    'Mittel' => 'medium',
                    'Niedrig' => 'low',
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('rto', IntegerType::class, [
                'label' => 'RTO (Recovery Time Objective) in Stunden',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'z.B. 4',
                ],
                'help' => 'Maximale akzeptable Ausfallzeit in Stunden',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('rpo', IntegerType::class, [
                'label' => 'RPO (Recovery Point Objective) in Stunden',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'z.B. 2',
                ],
                'help' => 'Maximaler akzeptabler Datenverlust in Stunden',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('mtpd', IntegerType::class, [
                'label' => 'MTPD (Maximum Tolerable Period of Disruption) in Stunden',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => 'z.B. 24',
                ],
                'help' => 'Maximale tolerierbare Unterbrechungsdauer',
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => 0, 'max' => 8760]),
                ],
            ])
            ->add('financialImpactPerHour', MoneyType::class, [
                'label' => 'Finanzieller Schaden pro Stunde',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('financialImpactPerDay', MoneyType::class, [
                'label' => 'Finanzieller Schaden pro Tag',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('reputationalImpact', ChoiceType::class, [
                'label' => 'Reputationsschaden',
                'choices' => [
                    'Sehr gering (1)' => 1,
                    'Gering (2)' => 2,
                    'Mittel (3)' => 3,
                    'Hoch (4)' => 4,
                    'Sehr hoch (5)' => 5,
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('regulatoryImpact', ChoiceType::class, [
                'label' => 'Regulatorische Auswirkungen',
                'choices' => [
                    'Sehr gering (1)' => 1,
                    'Gering (2)' => 2,
                    'Mittel (3)' => 3,
                    'Hoch (4)' => 4,
                    'Sehr hoch (5)' => 5,
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('operationalImpact', ChoiceType::class, [
                'label' => 'Operationale Auswirkungen',
                'choices' => [
                    'Sehr gering (1)' => 1,
                    'Gering (2)' => 2,
                    'Mittel (3)' => 3,
                    'Hoch (4)' => 4,
                    'Sehr hoch (5)' => 5,
                ],
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ])
            ->add('dependenciesUpstream', TextareaType::class, [
                'label' => 'Abhängigkeiten (Upstream)',
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'help' => 'Von welchen Prozessen/Systemen ist dieser Prozess abhängig?',
                'required' => false,
            ])
            ->add('dependenciesDownstream', TextareaType::class, [
                'label' => 'Abhängigkeiten (Downstream)',
                'attr' => ['class' => 'form-control', 'rows' => 3],
                'help' => 'Welche Prozesse/Systeme sind von diesem Prozess abhängig?',
                'required' => false,
            ])
            ->add('recoveryStrategy', TextareaType::class, [
                'label' => 'Wiederherstellungsstrategie',
                'attr' => ['class' => 'form-control', 'rows' => 4],
                'required' => false,
            ])
            ->add('supportingAssets', EntityType::class, [
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'label' => 'Unterstützende Assets',
                'attr' => ['class' => 'form-select', 'size' => 5],
                'help' => 'Welche IT-Assets unterstützen diesen Prozess?',
            ])
            ->add('identifiedRisks', EntityType::class, [
                'class' => Risk::class,
                'choice_label' => 'title',
                'multiple' => true,
                'required' => false,
                'label' => 'Identifizierte Risiken',
                'attr' => ['class' => 'form-select', 'size' => 5],
                'help' => 'Welche Risiken betreffen diesen Prozess?',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessProcess::class,
        ]);
    }
}
