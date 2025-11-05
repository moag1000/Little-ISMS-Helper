<?php

namespace App\Form;

use App\Entity\ManagementReview;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ManagementReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Review-Titel',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Management Review Q1 2025',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'Review-Datum',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reviewedBy', EntityType::class, [
                'label' => 'Durchgeführt von',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'placeholder' => '-- Bitte wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'Üblicherweise ein Mitglied des Top-Managements',
            ])
            ->add('participants', EntityType::class, [
                'label' => 'Teilnehmer',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 6,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Geplant' => 'planned',
                    'Durchgeführt' => 'completed',
                    'Follow-up erforderlich' => 'follow_up_required',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('performanceEvaluation', TextareaType::class, [
                'label' => 'Performance-Bewertung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Bewertung der ISMS-Performance und KPIs',
            ])
            ->add('nonconformitiesReview', TextareaType::class, [
                'label' => 'Review der Nichtkonformitäten',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status offener Nichtkonformitäten aus Audits',
            ])
            ->add('incidentsReview', TextareaType::class, [
                'label' => 'Review der Security Incidents',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Analyse von Sicherheitsvorfällen seit letztem Review',
            ])
            ->add('risksReview', TextareaType::class, [
                'label' => 'Review der Risiken',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Änderungen im Risikoprofil',
            ])
            ->add('objectivesReview', TextareaType::class, [
                'label' => 'Review der ISMS-Ziele',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status der ISMS-Ziele und Zielerreichung',
            ])
            ->add('contextChanges', TextareaType::class, [
                'label' => 'Änderungen im Kontext',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Änderungen in internen/externen Anforderungen',
            ])
            ->add('improvementOpportunities', TextareaType::class, [
                'label' => 'Verbesserungsmöglichkeiten',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Identifizierte Chancen zur Verbesserung des ISMS',
            ])
            ->add('decisions', TextareaType::class, [
                'label' => 'Managemententscheidungen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'Getroffene Entscheidungen und Maßnahmen',
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'Maßnahmen / Action Items',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'Konkrete Maßnahmen mit Verantwortlichkeiten und Fristen',
            ])
            ->add('resourcesNeeded', TextareaType::class, [
                'label' => 'Benötigte Ressourcen',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Budget, Personal oder sonstige Ressourcen',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'Zusammenfassung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Executive Summary des Management Reviews',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagementReview::class,
        ]);
    }
}
