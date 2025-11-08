<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
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

class ComplianceRequirementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('framework', EntityType::class, [
                'label' => 'Framework',
                'class' => ComplianceFramework::class,
                'choice_label' => function (ComplianceFramework $framework) {
                    return $framework->getName() . ' (' . $framework->getCode() . ')';
                },
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie ein Framework aus.'])
                ]
            ])
            ->add('requirementId', TextType::class, [
                'label' => 'Anforderungs-ID',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. 5.1, A.8.1, DORA-ART-21'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine Anforderungs-ID ein.']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Die ID darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ],
                'help' => 'Eindeutige ID der Anforderung im Framework'
            ])
            ->add('title', TextType::class, [
                'label' => 'Titel',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Policies für Informationssicherheit'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Titel ein.']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Der Titel darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Detaillierte Beschreibung der Anforderung'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine Beschreibung ein.'])
                ]
            ])
            ->add('category', TextType::class, [
                'label' => 'Kategorie',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Organisational, Technical, Physical'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Die Kategorie darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priorität',
                'choices' => [
                    'Kritisch' => 'critical',
                    'Hoch' => 'high',
                    'Mittel' => 'medium',
                    'Niedrig' => 'low',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie eine Priorität aus.'])
                ]
            ])
            ->add('requirementType', ChoiceType::class, [
                'label' => 'Anforderungstyp',
                'choices' => [
                    'Kern-Anforderung' => 'core',
                    'Detail-Anforderung' => 'detailed',
                    'Unter-Anforderung' => 'sub_requirement',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Typ der Anforderung in der Hierarchie'
            ])
            ->add('parentRequirement', EntityType::class, [
                'label' => 'Übergeordnete Anforderung',
                'class' => ComplianceRequirement::class,
                'choice_label' => function (ComplianceRequirement $req) {
                    return $req->getRequirementId() . ' - ' . $req->getTitle();
                },
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Falls dies eine Detail- oder Unter-Anforderung ist'
            ])
            ->add('applicable', CheckboxType::class, [
                'label' => 'Anwendbar',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'help' => 'Ist diese Anforderung für Ihre Organisation anwendbar?',
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ])
            ->add('applicabilityJustification', TextareaType::class, [
                'label' => 'Begründung der Anwendbarkeit',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Begründung, warum diese Anforderung anwendbar oder nicht anwendbar ist'
                ]
            ])
            ->add('fulfillmentPercentage', IntegerType::class, [
                'label' => 'Erfüllungsgrad (%)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                    'placeholder' => 'z.B. 75'
                ],
                'help' => 'Erfüllungsgrad von 0 bis 100%'
            ])
            ->add('fulfillmentNotes', TextareaType::class, [
                'label' => 'Erfüllungs-Notizen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Notizen zum aktuellen Erfüllungsstand'
                ]
            ])
            ->add('evidenceDescription', TextareaType::class, [
                'label' => 'Nachweis-Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Beschreibung der Nachweise für die Erfüllung'
                ]
            ])
            ->add('mappedControls', EntityType::class, [
                'label' => 'Zugeordnete Controls',
                'class' => Control::class,
                'choice_label' => function (Control $control) {
                    return $control->getControlId() . ' - ' . $control->getTitle();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5
                ],
                'help' => 'ISO 27001 Controls, die diese Anforderung erfüllen'
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'Verantwortliche Person',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name des Verantwortlichen'
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Name darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('targetDate', DateType::class, [
                'label' => 'Zieldatum',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Zieldatum für die vollständige Erfüllung'
            ])
            ->add('lastAssessmentDate', DateType::class, [
                'label' => 'Letztes Bewertungsdatum',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Datum der letzten Bewertung dieser Anforderung'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceRequirement::class,
        ]);
    }
}
