<?php

namespace App\Form;

use App\Entity\ISMSContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ISMSContextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'Organisationsname',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'ISMS-Geltungsbereich',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'Definieren Sie den Geltungsbereich des ISMS...',
                ],
                'help' => 'ISO 27001 Clause 4.3: Definieren Sie Grenzen und Anwendbarkeit des ISMS',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('contextType', ChoiceType::class, [
                'label' => 'Kontext-Typ',
                'choices' => [
                    'Interner Kontext' => 'internal',
                    'Externer Kontext' => 'external',
                    'Interessierte Partei' => 'interested_party',
                    'Rechtliche Anforderung' => 'legal_requirement',
                    'Regulatorische Anforderung' => 'regulatory_requirement',
                    'Vertragliche Anforderung' => 'contractual_requirement',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name / Titel',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. DSGVO, Cloud-Computing Strategie, etc.',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Detaillierte Beschreibung des Kontexts oder der Anforderung',
            ])
            ->add('requirements', TextareaType::class, [
                'label' => 'Anforderungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Spezifische Anforderungen die sich aus diesem Kontext ergeben',
            ])
            ->add('impact', ChoiceType::class, [
                'label' => 'Auswirkung auf ISMS',
                'choices' => [
                    'Sehr hoch' => 'very_high',
                    'Hoch' => 'high',
                    'Mittel' => 'medium',
                    'Niedrig' => 'low',
                    'Sehr niedrig' => 'very_low',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Aktiv' => 'active',
                    'In Bearbeitung' => 'in_progress',
                    'Abgeschlossen' => 'completed',
                    'Überholt' => 'obsolete',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('interestedPartyName', TextType::class, [
                'label' => 'Name der interessierten Partei',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Kunden, Regulierungsbehörden, Lieferanten, etc.',
                ],
                'help' => 'Relevant wenn contextType = "interested_party"',
            ])
            ->add('interestedPartyExpectations', TextareaType::class, [
                'label' => 'Erwartungen der interessierten Partei',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Was erwartet diese Partei vom ISMS?',
            ])
            ->add('externalIssues', TextareaType::class, [
                'label' => 'Externe Themen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'ISO 27001 Clause 4.1: Externe Themen (gesetzlich, technologisch, wettbewerblich, etc.)',
            ])
            ->add('internalIssues', TextareaType::class, [
                'label' => 'Interne Themen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'ISO 27001 Clause 4.1: Interne Themen (Werte, Kultur, Wissen, Performance, etc.)',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notizen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSContext::class,
        ]);
    }
}
