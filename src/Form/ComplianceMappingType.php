<?php

namespace App\Form;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
                'label' => 'Quell-Anforderung',
                'class' => ComplianceRequirement::class,
                'choice_label' => function (ComplianceRequirement $req) {
                    return $req->getFramework()->getCode() . ' ' . $req->getRequirementId() . ' - ' . $req->getTitle();
                },
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie eine Quell-Anforderung aus.'])
                ],
                'help' => 'Die Anforderung, die erfüllt wird'
            ])
            ->add('targetRequirement', EntityType::class, [
                'label' => 'Ziel-Anforderung',
                'class' => ComplianceRequirement::class,
                'choice_label' => function (ComplianceRequirement $req) {
                    return $req->getFramework()->getCode() . ' ' . $req->getRequirementId() . ' - ' . $req->getTitle();
                },
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie eine Ziel-Anforderung aus.'])
                ],
                'help' => 'Die Anforderung, die durch die Quell-Anforderung (teilweise) erfüllt wird'
            ])
            ->add('mappingPercentage', IntegerType::class, [
                'label' => 'Zuordnungsgrad (%)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 150,
                    'placeholder' => 'z.B. 100'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Zuordnungsgrad ein.']),
                    new Assert\Range([
                        'min' => 0,
                        'max' => 150,
                        'notInRangeMessage' => 'Der Zuordnungsgrad muss zwischen {{ min }}% und {{ max }}% liegen.'
                    ])
                ],
                'help' => 'Wie stark erfüllt die Quell-Anforderung die Ziel-Anforderung? (0-49: schwach, 50-99: teilweise, 100: vollständig, >100: übererfüllt)'
            ])
            ->add('mappingType', ChoiceType::class, [
                'label' => 'Zuordnungstyp',
                'choices' => [
                    'Schwach (<50%)' => 'weak',
                    'Teilweise (50-99%)' => 'partial',
                    'Vollständig (100%)' => 'full',
                    'Übererfüllt (>100%)' => 'exceeds',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie einen Zuordnungstyp aus.'])
                ],
                'help' => 'Wird automatisch basierend auf dem Zuordnungsgrad gesetzt'
            ])
            ->add('mappingRationale', TextareaType::class, [
                'label' => 'Begründung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Begründung, warum diese Zuordnung existiert und wie stark sie ist'
                ]
            ])
            ->add('bidirectional', CheckboxType::class, [
                'label' => 'Bidirektional',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'help' => 'Erfüllt die Ziel-Anforderung auch die Quell-Anforderung?',
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ])
            ->add('confidence', ChoiceType::class, [
                'label' => 'Vertrauensniveau',
                'choices' => [
                    'Niedrig' => 'low',
                    'Mittel' => 'medium',
                    'Hoch' => 'high',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Wie sicher ist diese Zuordnung?'
            ])
            ->add('verifiedBy', TextType::class, [
                'label' => 'Verifiziert von',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name der Person, die diese Zuordnung verifiziert hat'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Name darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('verificationDate', DateType::class, [
                'label' => 'Verifikationsdatum',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Datum, an dem diese Zuordnung verifiziert wurde'
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
