<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Entity\User;
use App\Form\DataTransformer\JsonArrayTransformer;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ManagementReviewType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentTenant = $this->tenantContext->getCurrentTenant();
        $builder
            ->add('title', TextType::class, [
                'label' => 'management_review.field.title',
                'attr' => [
                    'placeholder' => 'management_review.placeholder.title',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'management_review.field.review_date',
                'widget' => 'single_text',
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
                'help' => 'management_review.help.reviewed_by',
            ])
            ->add('reviewedByPerson', EntityType::class, [
                'label' => 'management_review.field.reviewed_by_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder' => 'management_review.placeholder.reviewed_by_person',
                'required' => false,
                'help' => 'management_review.help.reviewed_by_person',
            ])
            ->add('reviewedByDeputyPersons', EntityType::class, [
                'label' => 'management_review.field.reviewed_by_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'management_review.help.reviewed_by_deputy_persons',
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
                'choice_translation_domain' => 'management_review',
            ])
            ->add('performanceEvaluation', TextareaType::class, [
                'label' => 'management_review.field.performance_evaluation',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'management_review.help.performance_evaluation',
            ])
            ->add('changesRelevantToISMS', TextareaType::class, [
                'label' => 'management_review.field.changes_relevant_to_isms',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.changes_relevant_to_isms',
            ])
            ->add('feedbackFromInterestedParties', TextareaType::class, [
                'label' => 'management_review.field.feedback_from_interested_parties',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.feedback_from_interested_parties',
            ])
            ->add('auditResults', TextareaType::class, [
                'label' => 'management_review.field.audit_results',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.audit_results',
            ])
            ->add('nonconformitiesReview', TextareaType::class, [
                'label' => 'management_review.field.nonconformities_review',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.nonconformities_review',
            ])
            ->add('incidentsReview', TextareaType::class, [
                'label' => 'management_review.field.incidents_review',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.incidents_review',
            ])
            ->add('risksReview', TextareaType::class, [
                'label' => 'management_review.field.risks_review',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.risks_review',
            ])
            ->add('objectivesReview', TextareaType::class, [
                'label' => 'management_review.field.objectives_review',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.objectives_review',
            ])
            ->add('contextChanges', TextareaType::class, [
                'label' => 'management_review.field.context_changes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.context_changes',
            ])
            ->add('previousReviewActions', TextareaType::class, [
                'label' => 'management_review.field.previous_review_actions',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.previous_review_actions',
            ])
            ->add('nonConformitiesStatus', TextareaType::class, [
                'label' => 'management_review.field.non_conformities_status',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.non_conformities_status',
            ])
            ->add('correctiveActionsStatus', TextareaType::class, [
                'label' => 'management_review.field.corrective_actions_status',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.corrective_actions_status',
            ])
            ->add('improvementOpportunities', TextareaType::class, [
                'label' => 'management_review.field.improvement_opportunities',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'management_review.help.improvement_opportunities',
            ])
            ->add('opportunitiesForImprovement', TextareaType::class, [
                'label' => 'management_review.field.opportunities_for_improvement',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'management_review.help.opportunities_for_improvement',
            ])
            ->add('decisions', TextareaType::class, [
                'label' => 'management_review.field.decisions',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                ],
                'help' => 'management_review.help.decisions',
            ])
            ->add('actionItems', TextareaType::class, [
                'label' => 'management_review.field.action_items',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                ],
                'help' => 'management_review.help.action_items',
            ])
            ->add('resourceNeeds', TextareaType::class, [
                'label' => 'management_review.field.resource_needs',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
                'help' => 'management_review.help.resource_needs',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'management_review.field.summary',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
                'help' => 'management_review.help.summary',
            ])
            // ── ISO 27001 §9.3 norm fields (T31.2.5) ──────────────────────
            ->add('topManagementAttended', CheckboxType::class, [
                'label' => 'management_review.field.top_management_attended',
                'required' => false,
                'help' => 'management_review.help.top_management_attended',
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'management_review.field.next_review_date',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'help' => 'management_review.help.next_review_date',
            ])
            ->add('meetingMinutesDocument', EntityType::class, [
                'label' => 'management_review.field.meeting_minutes_document',
                'class' => Document::class,
                'choice_label' => fn(Document $d): string => $d->getOriginalFilename() ?? $d->getFilename() ?? (string) $d->getId(),
                'placeholder' => 'management_review.placeholder.meeting_minutes_document',
                'required' => false,
                'help' => 'management_review.help.meeting_minutes_document',
                'query_builder' => function (EntityRepository $er) use ($currentTenant): \Doctrine\ORM\QueryBuilder {
                    $qb = $er->createQueryBuilder('d')
                        ->where('d.status != :deleted')
                        ->setParameter('deleted', 'deleted')
                        ->orderBy('d.originalFilename', 'ASC');
                    if ($currentTenant !== null) {
                        $qb->andWhere('d.tenant = :tenant')
                           ->setParameter('tenant', $currentTenant);
                    }
                    return $qb;
                },
            ])
            ->add('riskTreatmentEffectiveness', TextareaType::class, [
                'label' => 'management_review.field.risk_treatment_effectiveness',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'management_review.placeholder.risk_treatment_effectiveness',
                ],
                'help' => 'management_review.help.risk_treatment_effectiveness',
            ])
            ->add('policyReviewOutcome', TextareaType::class, [
                'label' => 'management_review.field.policy_review_outcome',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'management_review.placeholder.policy_review_outcome',
                ],
                'help' => 'management_review.help.policy_review_outcome',
            ])
            ->add('actionItemsWithDeadlines', TextareaType::class, [
                'label' => 'management_review.field.action_items_with_deadlines',
                'required' => false,
                'attr' => [
                    'rows' => 6,
                    'placeholder' => 'management_review.placeholder.action_items_with_deadlines',
                ],
                'help' => 'management_review.help.action_items_with_deadlines',
            ]);

        $builder->get('actionItemsWithDeadlines')
            ->addModelTransformer(new JsonArrayTransformer());

        if ($this->isModuleActive('compliance')) {
            $builder->add('frameworkComplianceStatus', TextareaType::class, [
                'label' => 'management_review.field.framework_compliance_status',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'management_review.placeholder.framework_compliance_status',
                ],
                'help' => 'management_review.help.framework_compliance_status',
            ]);
            $builder->get('frameworkComplianceStatus')
                ->addModelTransformer(new JsonArrayTransformer());
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagementReview::class,
            'translation_domain' => 'management_review',
            'constraints' => [
                new Callback([$this, 'validateReviewedBySlot']),
            ],
        ]);
    }

    public function validateReviewedBySlot(?ManagementReview $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getReviewedBy() === null && $entity->getReviewedByPerson() === null) {
            $context->buildViolation('management_review.error.owner_required_user_or_person')
                ->atPath('reviewedBy')
                ->addViolation();
        }
    }
}
