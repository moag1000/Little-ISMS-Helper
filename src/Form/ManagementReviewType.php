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
                    'placeholder' => 'management_review.placeholder.title',
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
                'choice_label' => fn(User $user): string => $user->getFirstName() . ' ' . $user->getLastName(),
                'placeholder' => 'common.please_select',
                'required' => false,
                'attr' => ['class' => 'form-select'],
                'help' => 'management_review.help.reviewed_by',
            ])
            ->add('participants', EntityType::class, [
                'label' => 'management_review.field.participants',
                'class' => User::class,
                'choice_label' => fn(User $user): string => $user->getFirstName() . ' ' . $user->getLastName(),
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
                    'management_review.status.planned' => 'planned',
                    'management_review.status.completed' => 'completed',
                    'management_review.status.follow_up_required' => 'follow_up_required',
                ],
                'attr' => ['class' => 'form-select'],
                'choice_translation_domain' => 'management_review',
            ])
            ->add('performanceEvaluation', TextareaType::class, [
                'label' => 'management_review.field.performance_evaluation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'management_review.help.performance_evaluation',
            ])
            ->add('changesRelevantToISMS', TextareaType::class, [
                'label' => 'management_review.field.changes_relevant_to_isms',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.changes_relevant_to_isms',
            ])
            ->add('feedbackFromInterestedParties', TextareaType::class, [
                'label' => 'management_review.field.feedback_from_interested_parties',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.feedback_from_interested_parties',
            ])
            ->add('auditResults', TextareaType::class, [
                'label' => 'management_review.field.audit_results',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.audit_results',
            ])
            ->add('nonconformitiesReview', TextareaType::class, [
                'label' => 'management_review.field.nonconformities_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.nonconformities_review',
            ])
            ->add('incidentsReview', TextareaType::class, [
                'label' => 'management_review.field.incidents_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.incidents_review',
            ])
            ->add('risksReview', TextareaType::class, [
                'label' => 'management_review.field.risks_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.risks_review',
            ])
            ->add('objectivesReview', TextareaType::class, [
                'label' => 'management_review.field.objectives_review',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.objectives_review',
            ])
            ->add('contextChanges', TextareaType::class, [
                'label' => 'management_review.field.context_changes',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.context_changes',
            ])
            ->add('previousReviewActions', TextareaType::class, [
                'label' => 'management_review.field.previous_review_actions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.previous_review_actions',
            ])
            ->add('nonConformitiesStatus', TextareaType::class, [
                'label' => 'management_review.field.non_conformities_status',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.non_conformities_status',
            ])
            ->add('correctiveActionsStatus', TextareaType::class, [
                'label' => 'management_review.field.corrective_actions_status',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.corrective_actions_status',
            ])
            ->add('improvementOpportunities', TextareaType::class, [
                'label' => 'management_review.field.improvement_opportunities',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'management_review.help.improvement_opportunities',
            ])
            ->add('opportunitiesForImprovement', TextareaType::class, [
                'label' => 'management_review.field.opportunities_for_improvement',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'management_review.help.opportunities_for_improvement',
            ])
            ->add('decisions', TextareaType::class, [
                'label' => 'management_review.field.decisions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'management_review.help.decisions',
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'management_review.field.action_items',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                ],
                'help' => 'management_review.help.action_items',
            ])
            ->add('resourceNeeds', TextareaType::class, [
                'label' => 'management_review.field.resource_needs',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
                'help' => 'management_review.help.resource_needs',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'management_review.field.summary',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                ],
                'help' => 'management_review.help.summary',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagementReview::class,
            'translation_domain' => 'management_review',
        ]);
    }
}
