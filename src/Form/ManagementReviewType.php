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
                'label' => 'management_review.field.title',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Management Review Q1 2025',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'management_review.field.review_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reviewedBy', EntityType::class, [
                'label' => 'management_review.field.reviewed_by',
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
                'label' => 'management_review.field.participants',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName();
                },
                'multiple' => true,
                // wichtig für ManyToMany-Collections, damit add/remove-Methoden genutzt werden
                'by_reference' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-select',
                    'size' => 6,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'management_review.field.status',
                'choices' => [
                    'Geplant' => 'planned',
                    'Durchgeführt' => 'completed',
                    'Follow-up erforderlich' => 'follow_up_required',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('performanceEvaluation', TextareaType::class, [
                'label' => 'management_review.field.performance_evaluation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Bewertung der ISMS-Performance und KPIs',
            ])
            ->add('changesRelevantToISMS', TextareaType::class, [
                'label' => 'management_review.field.changes_relevant_to_isms',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Änderungen, die für das ISMS relevant sind (z. B. gesetzliche, organisatorische, technologische)',
            ])
            ->add('feedbackFromInterestedParties', TextareaType::class, [
                'label' => 'management_review.field.feedback_from_interested_parties',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Rückmeldungen interessierter Parteien (Kunden, Partner, Behörden, Mitarbeitende)',
            ])
            ->add('auditResults', TextareaType::class, [
                'label' => 'management_review.field.audit_results',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Ergebnisse interner/externer Audits seit dem letzten Review',
            ])
            ->add('nonconformitiesReview', TextareaType::class, [
                'label' => 'management_review.field.nonconformities_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status offener Nichtkonformitäten aus Audits',
            ])
            ->add('incidentsReview', TextareaType::class, [
                'label' => 'management_review.field.incidents_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Analyse von Sicherheitsvorfällen seit letztem Review',
            ])
            ->add('risksReview', TextareaType::class, [
                'label' => 'management_review.field.risks_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Änderungen im Risikoprofil',
            ])
            ->add('objectivesReview', TextareaType::class, [
                'label' => 'management_review.field.objectives_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status der ISMS-Ziele und Zielerreichung',
            ])
            ->add('contextChanges', TextareaType::class, [
                'label' => 'management_review.field.context_changes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Änderungen in internen/externen Anforderungen',
            ])
            ->add('previousReviewActions', TextareaType::class, [
                'label' => 'management_review.field.previous_review_actions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status der Maßnahmen aus dem vorherigen Management Review',
            ])
            ->add('nonConformitiesStatus', TextareaType::class, [
                'label' => 'management_review.field.non_conformities_status',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Übersicht offener Nichtkonformitäten und deren Bearbeitungsstand',
            ])
            ->add('correctiveActionsStatus', TextareaType::class, [
                'label' => 'management_review.field.corrective_actions_status',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Status der Korrekturmaßnahmen (geplant, in Bearbeitung, abgeschlossen)',
            ])
            ->add('improvementOpportunities', TextareaType::class, [
                'label' => 'management_review.field.improvement_opportunities',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Identifizierte Chancen zur Verbesserung des ISMS',
            ])
            ->add('opportunitiesForImprovement', TextareaType::class, [
                'label' => 'management_review.field.opportunities_for_improvement',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'Verbesserungsmöglichkeiten (Alias-Feld, kompatibel mit bestehendem Template)',
            ])
            ->add('decisions', TextareaType::class, [
                'label' => 'management_review.field.decisions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'Getroffene Entscheidungen und Maßnahmen',
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'management_review.field.action_items',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'Konkrete Maßnahmen mit Verantwortlichkeiten und Fristen',
            ])
            ->add('resourceNeeds', TextareaType::class, [
                'label' => 'management_review.field.resource_needs',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'Budget, Personal oder sonstige Ressourcen',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'management_review.field.summary',
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
