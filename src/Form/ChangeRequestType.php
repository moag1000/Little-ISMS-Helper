<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditFinding;
use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ChangeRequestType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('changeNumber', TextType::class, [
                'label' => 'change_request.field.change_number',
                'required' => true,
                'attr' => ['maxlength' => 50],
            ])
            ->add('title', TextType::class, [
                'label' => 'change_request.field.title',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('changeType', ChoiceType::class, [
                'label' => 'change_request.field.change_type',
                'choices' => [
                    'change_request.change_type.isms_policy' => 'isms_policy',
                    'change_request.change_type.isms_scope' => 'isms_scope',
                    'change_request.change_type.control' => 'control',
                    'change_request.change_type.asset' => 'asset',
                    'change_request.change_type.process' => 'process',
                    'change_request.change_type.technology' => 'technology',
                    'change_request.change_type.supplier' => 'supplier',
                    'change_request.change_type.organizational' => 'organizational',
                    'change_request.change_type.other' => 'other',
                ],
                'required' => true,
                    'choice_translation_domain' => 'change_requests',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'change_request.field.description',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('justification', TextareaType::class, [
                'label' => 'change_request.field.justification',
                'required' => true,
                'attr' => ['rows' => 3],
            ])
            ->add('requestedBy', TextType::class, [
                'label' => 'change_request.field.requested_by',
                'required' => true,
                'attr' => ['maxlength' => 100],
            ])
            ->add('requestedDate', DateType::class, [
                'label' => 'change_request.field.requested_date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'change_request.field.priority',
                'choices' => [
                    'change_request.priority.critical' => 'critical',
                    'change_request.priority.high' => 'high',
                    'change_request.priority.medium' => 'medium',
                    'change_request.priority.low' => 'low',
                ],
                'required' => true,
                    'choice_translation_domain' => 'change_requests',
            ])
            // ── Status field is READ-ONLY (Lifecycle-bypass fix, Sprint Y.5) ──
            // Owned by `change_request_lifecycle`. ISO 27001 A.8.32 —
            // 4-eyes auf `approve`, `implement`, `close`. Transitions via
            // LifecycleService::transition() only.
            ->add('status', ChoiceType::class, [
                'label' => 'change_request.field.status',
                'help' => 'change_request.help.status_readonly',
                'choices' => [
                    'change_request.status.draft' => 'draft',
                    'change_request.status.submitted' => 'submitted',
                    'change_request.status.under_review' => 'under_review',
                    'change_request.status.approved' => 'approved',
                    'change_request.status.rejected' => 'rejected',
                    'change_request.status.scheduled' => 'scheduled',
                    'change_request.status.implemented' => 'implemented',
                    'change_request.status.verified' => 'verified',
                    'change_request.status.closed' => 'closed',
                    'change_request.status.cancelled' => 'cancelled',
                ],
                'required' => false,
                'disabled' => true,
                // mapped=false: entity status stays untouched regardless of POST value.
                // Status transitions are owned exclusively by LifecycleService.
                'mapped' => false,
                'choice_translation_domain' => 'change_requests',
            ])
            ->add('clauseReference', TextType::class, [
                'label' => 'change_request.field.clause_reference',
                'required' => false,
                'attr' => ['maxlength' => 100, 'placeholder' => 'change_request.placeholder.clause_reference'],
                'help' => 'change_request.help.clause_reference',
            ])
            // Junior-ISB-Audit C4-05 — Lineage to upstream artefacts
            // (AuditFinding / CorrectiveAction). ISO 27001 Cl. 10.1 —
            // continuous-improvement traceability without parsing the
            // free-text justification.
            ->add('relatedFinding', EntityType::class, [
                'label' => 'change_request.field.related_finding',
                'class' => AuditFinding::class,
                'choice_label' => fn(AuditFinding $f): string => ($f->getFindingNumber() ?? '#' . $f->getId()) . ' — ' . ($f->getTitle() ?? ''),
                'placeholder' => 'change_request.placeholder.related_finding',
                'required' => false,
                'help' => 'change_request.help.related_finding',
            ])
            ->add('relatedCorrectiveAction', EntityType::class, [
                'label' => 'change_request.field.related_corrective_action',
                'class' => CorrectiveAction::class,
                'choice_label' => fn(CorrectiveAction $c): string => '#' . $c->getId() . ' — ' . ($c->getTitle() ?? ''),
                'placeholder' => 'change_request.placeholder.related_corrective_action',
                'required' => false,
                'help' => 'change_request.help.related_corrective_action',
            ])
            ->add('ismsImpact', TextareaType::class, [
                'label' => 'change_request.field.isms_impact',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('riskAssessment', TextareaType::class, [
                'label' => 'change_request.field.risk_assessment',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('implementationPlan', TextareaType::class, [
                'label' => 'change_request.field.implementation_plan',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('rollbackPlan', TextareaType::class, [
                'label' => 'change_request.field.rollback_plan',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('testingRequirements', TextareaType::class, [
                'label' => 'change_request.field.testing_requirements',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('plannedImplementationDate', DateType::class, [
                'label' => 'change_request.field.planned_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('actualImplementationDate', DateType::class, [
                'label' => 'change_request.field.actual_implementation_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvedBy', TextType::class, [
                'label' => 'change_request.field.approved_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('approvedDate', DateType::class, [
                'label' => 'change_request.field.approved_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('approvalComments', TextareaType::class, [
                'label' => 'change_request.field.approval_comments',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('implementedBy', TextType::class, [
                'label' => 'change_request.field.implemented_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('implementationNotes', TextareaType::class, [
                'label' => 'change_request.field.implementation_notes',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('verifiedBy', TextType::class, [
                'label' => 'change_request.field.verified_by',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('verifiedDate', DateType::class, [
                'label' => 'change_request.field.verified_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('verificationResults', TextareaType::class, [
                'label' => 'change_request.field.verification_results',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('closedDate', DateType::class, [
                'label' => 'change_request.field.closed_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('closureNotes', TextareaType::class, [
                'label' => 'change_request.field.closure_notes',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    /**
     * S4 Foundation P-2 SectionPolicy — ISO 27001 A.8.32 · ITIL Change Management.
     * Sections: overview · details · impact_assessment · approval · implementation · verification · audit_metadata
     *
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'overview' => [
                'changeNumber',
                'title',
                'changeType',
                'description',
                'justification',
            ],
            'details' => [
                'requestedBy',
                'requestedDate',
                'priority',
                'status',
                'clauseReference',
            ],
            // Junior-ISB-Audit C4-05 — upstream-lineage section so the
            // ISB can wire a ChangeRequest back to the AuditFinding /
            // CorrectiveAction that triggered it (ISO 27001 Cl. 10.1).
            'lineage' => [
                'relatedFinding',
                'relatedCorrectiveAction',
            ],
            'impact_assessment' => [
                'ismsImpact',
                'riskAssessment',
            ],
            'approval' => [
                'approvedBy',
                'approvedDate',
                'approvalComments',
            ],
            'implementation' => [
                'implementationPlan',
                'rollbackPlan',
                'testingRequirements',
                'plannedImplementationDate',
                'actualImplementationDate',
                'implementedBy',
                'implementationNotes',
            ],
            'verification' => [
                'verifiedBy',
                'verifiedDate',
                'verificationResults',
                'closedDate',
                'closureNotes',
            ],
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangeRequest::class,
            'translation_domain' => 'change_requests',
        ]);
    }
}
