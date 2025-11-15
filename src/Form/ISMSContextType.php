<?php

namespace App\Form;

use App\Entity\ISMSContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ISMSContextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'context.field.organization_name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Name der Organisation',
                ],
                'help' => 'Dieser Name wird automatisch vom zugeordneten Mandanten übernommen. Um ihn zu ändern, bearbeiten Sie den Mandanten in der Mandantenverwaltung.',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'context.validation.organization_name_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'context.validation.name_max_length'
                    ])
                ]
            ])
            ->add('ismsScope', TextareaType::class, [
                'label' => 'context.field.isms_scope',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'Definieren Sie den Geltungsbereich des ISMS (z.B. Abteilungen, Standorte, Prozesse)...'
                ],
                'help' => 'ISO 27001 Clause 4.3: Definieren Sie Grenzen und Anwendbarkeit des ISMS'
            ])
            ->add('scopeExclusions', TextareaType::class, [
                'label' => 'context.field.scope_exclusions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Bereiche, die ausdrücklich vom ISMS ausgeschlossen sind...'
                ],
                'help' => 'Begründen Sie, warum bestimmte Bereiche ausgeschlossen werden'
            ])
            ->add('externalIssues', TextareaType::class, [
                'label' => 'context.field.external_issues',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Gesetzliche, technologische, wettbewerbliche, kulturelle und wirtschaftliche Themen...'
                ],
                'help' => 'ISO 27001 Clause 4.1: Externe Faktoren, die das ISMS beeinflussen'
            ])
            ->add('internalIssues', TextareaType::class, [
                'label' => 'context.field.internal_issues',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Organisationskultur, Werte, Wissen, Performance, Ressourcen...'
                ],
                'help' => 'ISO 27001 Clause 4.1: Interne Faktoren, die das ISMS beeinflussen'
            ])
            ->add('interestedParties', TextareaType::class, [
                'label' => 'context.field.interested_parties',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Kunden, Regulierungsbehörden, Lieferanten, Mitarbeiter, Aktionäre...'
                ],
                'help' => 'ISO 27001 Clause 4.2: Stakeholder, die Anforderungen an das ISMS stellen'
            ])
            ->add('interestedPartiesRequirements', TextareaType::class, [
                'label' => 'context.field.interested_parties_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Welche Anforderungen stellen die interessierten Parteien?'
                ],
                'help' => 'Spezifische Anforderungen der Stakeholder bezüglich Informationssicherheit'
            ])
            ->add('legalRequirements', TextareaType::class, [
                'label' => 'context.field.legal_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'DSGVO, IT-Sicherheitsgesetz, KRITIS, etc.'
                ],
                'help' => 'Gesetzliche Verpflichtungen und rechtliche Rahmenbedingungen'
            ])
            ->add('regulatoryRequirements', TextareaType::class, [
                'label' => 'context.field.regulatory_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Branchenspezifische Vorschriften, Standards, etc.'
                ],
                'help' => 'Regulatorische Vorgaben und Branchenstandards'
            ])
            ->add('contractualObligations', TextareaType::class, [
                'label' => 'context.field.contractual_obligations',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'SLAs, Vertraulichkeitsvereinbarungen, Kundenverträge...'
                ],
                'help' => 'Vertragliche Anforderungen an Informationssicherheit'
            ])
            ->add('ismsPolicy', TextareaType::class, [
                'label' => 'context.field.isms_policy',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'Übergeordnete Informationssicherheitsrichtlinie der Organisation...'
                ],
                'help' => 'ISO 27001 Clause 5.2: Festlegung der Informationssicherheitsrichtlinie'
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'context.field.roles_and_responsibilities',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'ISMS-Verantwortlicher, Informationssicherheitsbeauftragter, etc.'
                ],
                'help' => 'ISO 27001 Clause 5.3: Definition von Rollen und Verantwortlichkeiten im ISMS'
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'context.field.last_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Wann wurde der ISMS-Kontext zuletzt überprüft?'
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'context.field.next_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Wann soll die nächste Überprüfung stattfinden? (Empfohlen: jährlich)'
            ])
        ;

        // Dynamically set organizationName to readonly if tenant is assigned
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $context = $event->getData();
            $form = $event->getForm();

            if ($context && $context->getTenant() !== null) {
                // Re-add organizationName field with readonly attribute
                $form->add('organizationName', TextType::class, [
                    'label' => 'context.field.organization_name',
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => 'Name der Organisation',
                        'readonly' => true,
                    ],
                    'help' => 'Dieser Name wird automatisch vom zugeordneten Mandanten übernommen. Um ihn zu ändern, bearbeiten Sie den Mandanten in der Mandantenverwaltung.',
                    'constraints' => [
                        new Assert\NotBlank(['message' => 'context.validation.organization_name_required']),
                        new Assert\Length([
                            'max' => 255,
                            'maxMessage' => 'context.validation.name_max_length'
                        ])
                    ]
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSContext::class,
        ]);
    }
}
