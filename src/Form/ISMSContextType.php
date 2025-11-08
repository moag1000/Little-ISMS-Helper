<?php

namespace App\Form;

use App\Entity\ISMSContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ISMSContextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'Organisationsname',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name der Organisation'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Organisationsnamen ein.']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Der Name darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('ismsScope', TextareaType::class, [
                'label' => 'ISMS-Geltungsbereich',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'Definieren Sie den Geltungsbereich des ISMS (z.B. Abteilungen, Standorte, Prozesse)...'
                ],
                'help' => 'ISO 27001 Clause 4.3: Definieren Sie Grenzen und Anwendbarkeit des ISMS'
            ])
            ->add('scopeExclusions', TextareaType::class, [
                'label' => 'Ausschlüsse vom Geltungsbereich',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Bereiche, die ausdrücklich vom ISMS ausgeschlossen sind...'
                ],
                'help' => 'Begründen Sie, warum bestimmte Bereiche ausgeschlossen werden'
            ])
            ->add('externalIssues', TextareaType::class, [
                'label' => 'Externe Themen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Gesetzliche, technologische, wettbewerbliche, kulturelle und wirtschaftliche Themen...'
                ],
                'help' => 'ISO 27001 Clause 4.1: Externe Faktoren, die das ISMS beeinflussen'
            ])
            ->add('internalIssues', TextareaType::class, [
                'label' => 'Interne Themen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Organisationskultur, Werte, Wissen, Performance, Ressourcen...'
                ],
                'help' => 'ISO 27001 Clause 4.1: Interne Faktoren, die das ISMS beeinflussen'
            ])
            ->add('interestedParties', TextareaType::class, [
                'label' => 'Interessierte Parteien',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Kunden, Regulierungsbehörden, Lieferanten, Mitarbeiter, Aktionäre...'
                ],
                'help' => 'ISO 27001 Clause 4.2: Stakeholder, die Anforderungen an das ISMS stellen'
            ])
            ->add('interestedPartiesRequirements', TextareaType::class, [
                'label' => 'Anforderungen interessierter Parteien',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Welche Anforderungen stellen die interessierten Parteien?'
                ],
                'help' => 'Spezifische Anforderungen der Stakeholder bezüglich Informationssicherheit'
            ])
            ->add('legalRequirements', TextareaType::class, [
                'label' => 'Rechtliche Anforderungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'DSGVO, IT-Sicherheitsgesetz, KRITIS, etc.'
                ],
                'help' => 'Gesetzliche Verpflichtungen und rechtliche Rahmenbedingungen'
            ])
            ->add('regulatoryRequirements', TextareaType::class, [
                'label' => 'Regulatorische Anforderungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Branchenspezifische Vorschriften, Standards, etc.'
                ],
                'help' => 'Regulatorische Vorgaben und Branchenstandards'
            ])
            ->add('contractualObligations', TextareaType::class, [
                'label' => 'Vertragliche Verpflichtungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'SLAs, Vertraulichkeitsvereinbarungen, Kundenverträge...'
                ],
                'help' => 'Vertragliche Anforderungen an Informationssicherheit'
            ])
            ->add('ismsPolicy', TextareaType::class, [
                'label' => 'ISMS-Richtlinie',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'Übergeordnete Informationssicherheitsrichtlinie der Organisation...'
                ],
                'help' => 'ISO 27001 Clause 5.2: Festlegung der Informationssicherheitsrichtlinie'
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'Rollen und Verantwortlichkeiten',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'ISMS-Verantwortlicher, Informationssicherheitsbeauftragter, etc.'
                ],
                'help' => 'ISO 27001 Clause 5.3: Definition von Rollen und Verantwortlichkeiten im ISMS'
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'Letztes Überprüfungsdatum',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Wann wurde der ISMS-Kontext zuletzt überprüft?'
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'Nächstes Überprüfungsdatum',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Wann soll die nächste Überprüfung stattfinden? (Empfohlen: jährlich)'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSContext::class,
        ]);
    }
}
