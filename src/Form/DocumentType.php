<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Form\SectionMapInterface;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\SystemSettingsRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class DocumentType extends AbstractType implements SectionMapInterface
{
    use ModuleAwareFormTrait;

    public static function getSectionMap(): array
    {
        return [
            'overview'        => ['originalFilename', 'description', 'category', 'status'],
            'classification'  => ['tisaxInformationClassification'],
            'lifecycle'       => [
                'version',
                'reviewIntervalMonths',
                'requiresAcknowledgement',
                // Junior-ISB-Audit C3-03 (S14, 2026-05-23) — ISO 27001 Cl. 7.3 + A.6.3
                // audience-picker that follows the requiresAcknowledgement toggle.
                'acknowledgementAudience',
            ],
            'ownership'       => ['inheritable', 'overrideAllowed'],
            'content'         => ['file', 'policyBody'],
            // S14 Cluster A C1-04 — Control linkage. Unmapped multi-select;
            // DocumentController syncs DocumentControlLink rows on submit.
            'linkage'         => ['linkedControls'],
        ];
    }

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Document|null $document */
        $document = $builder->getData();
        $defaultClassification = (string) $this->systemSettingsRepository->getSetting(
            'document',
            'default_classification',
            'internal'
        );
        // Use existing value when editing; fall back to setting for new documents.
        $classificationDefault = ($document instanceof Document && $document->getTisaxInformationClassification() !== null)
            ? $document->getTisaxInformationClassification()
            : $defaultClassification;
        $builder
            ->add('originalFilename', TextType::class, [
                'label' => 'document.field.name',
                'required' => false,
                'mapped' => false, // Will be set from uploaded file
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'document.placeholder.name',
                ],
                'help' => 'document.help.name_optional',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'document.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'document.placeholder.description',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'document.field.document_type',
                'choices' => [
                    'document.type.asset' => 'asset',
                    'document.type.risk' => 'risk',
                    'document.type.incident' => 'incident',
                    'document.type.control' => 'control',
                    'document.type.audit' => 'audit',
                    'document.type.compliance' => 'compliance',
                    'document.type.training' => 'training',
                    'document.type.general' => 'general',
                    'document.type.communication_plan' => 'communication_plan',
                ],
                'required' => true,
                    'choice_translation_domain' => 'document',
            ])
            // ── Status field is READ-ONLY (Lifecycle-bypass fix) ──────────────
            // Owned by `document_lifecycle` Symfony Workflow. Transitions via
            // LifecycleService::transition() only — direct form edits are
            // ignored (`disabled => true`). YAML enforces 4-eyes on `publish`,
            // RBAC, audit-log + tenant-guard.
            ->add('status', EnumType::class, [
                'label' => 'document.field.status',
                'help' => 'document.help.status_readonly',
                'class' => DocumentStatus::class,
                // Deleted is a terminal soft-delete state reached only via the
                // `soft_delete` workflow transition — never user-pickable.
                'choices' => array_filter(
                    DocumentStatus::cases(),
                    static fn(DocumentStatus $s): bool => $s !== DocumentStatus::Deleted,
                ),
                'choice_label' => fn(DocumentStatus $s): string => 'document.status.' . $s->value,
                // Status is stored as VARCHAR (?string) on the entity; accept either
                // an enum case OR its raw string value so EnumType can resolve the
                // currently-selected option from both Doctrine hydration paths.
                'choice_value' => fn(DocumentStatus|string|null $c): ?string =>
                    $c instanceof DocumentStatus ? $c->value : $c,
                'required' => false,
                'disabled' => true,
                'placeholder' => false,
                // mapped=false: entity status stays untouched regardless of POST value.
                // Status transitions are owned exclusively by LifecycleService.
                'mapped' => false,
                'choice_translation_domain' => 'document',
            ])
            // V3 W2-Bug2 — version label + acknowledgement-requirement.
            ->add('version', TextType::class, [
                'label' => 'document.field.version',
                'help' => 'document.help.version',
                'required' => false,
                'attr' => [
                    'maxlength' => 32,
                    'placeholder' => 'document.placeholder.version',
                ],
            ])
            ->add('requiresAcknowledgement', CheckboxType::class, [
                'label' => 'document.field.requires_acknowledgement',
                'help' => 'document.help.requires_acknowledgement',
                'required' => false,
                'attr' => [
                    // Junior-ISB-Audit C3-03 — explicit ID so the Stimulus
                    // conditional-fields controller can target the
                    // acknowledgementAudience picker via data-depends-on.
                    'id' => 'document_requiresAcknowledgement',
                ],
            ])
            // Junior-ISB-Audit C3-03 (S14, 2026-05-23) — ISO 27001 Cl. 7.3
            // (Awareness) + A.6.3 (Awareness, education and training):
            // when a document requires user acknowledgement the audience
            // must be auditable. Empty selection preserves legacy fan-out
            // (every active tenant user). Hidden via Stimulus
            // conditional-fields controller when
            // `requiresAcknowledgement = false`.
            ->add('acknowledgementAudience', EntityType::class, [
                'label' => 'document.field.acknowledgement_audience',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'by_reference' => false,
                'help' => 'document.help.acknowledgement_audience',
                'attr' => [
                    'data-controller' => 'tom-select',
                    'data-depends-on' => 'document_requiresAcknowledgement',
                ],
            ])
            // V3 W2-LB-8 — Review-cycle cadence. Used by
            // DocumentApprovalListener to populate nextReviewDate
            // when the document is approved.
            //
            // Junior-ISB-Audit C3-04 (S14, 2026-05-23) — explicit default
            // 12 months when the entity has no value (new documents).
            // ISO 27001 Cl. 7.5.3 expects documented information to be
            // reviewed at a defined cadence; "annual" is the published
            // industry baseline.
            ->add('reviewIntervalMonths', IntegerType::class, [
                'label' => 'document.field.review_interval_months',
                'required' => false,
                'help' => 'document.help.review_interval_months',
                // Symfony coerces this scalar through the IntegerType
                // DataTransformer; the setter normalises (max(1, ...)).
                'empty_data' => '12',
                'attr' => [
                    'min' => 1,
                    'max' => 60,
                    'step' => 1,
                    'placeholder' => '12',
                ],
            ])
            // Phase 9.P2.1 — holding policy inheritance flags. Only
            // meaningful on a holding tenant; standalone tenants don't see
            // them at all (Junior-ISB-Audit T8.7 — gating instead of
            // perma-visible + perma-default).
            //
            // Holding-CISO is wired through tenant.parentTenantId +
            // ROLE_GROUP_CISO, gated on the tenant graph itself
            // (Tenant::isPartOfCorporateStructure). Intentionally not a
            // module-key gate — module activation is per-tenant, but
            // holding-membership is a tenant-graph property.
        ;
        $currentTenant = $this->tenantContext->getCurrentTenant();
        $isCorporate = $currentTenant !== null && $currentTenant->isPartOfCorporateStructure();
        if ($isCorporate) {
            $builder
                ->add('inheritable', CheckboxType::class, [
                    'label' => 'document.field.inheritable',
                    'help' => 'document.help.inheritable',
                    'required' => false,
                ])
                ->add('overrideAllowed', CheckboxType::class, [
                    'label' => 'document.field.override_allowed',
                    'help' => 'document.help.override_allowed',
                    'required' => false,
                ]);
        }
        $builder
            ->add('file', FileType::class, [
                'label' => 'document.field.file',
                'mapped' => false, // File upload is handled separately
                'required' => $options['is_new'] ?? true,
                'constraints' => [
                    new File(
                        maxSize: '10M',
                        mimeTypes: [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'text/plain',
                        ],
                        mimeTypesMessage: 'file_upload.validation.mime_type_invalid',
                        maxSizeMessage: 'file_upload.validation.max_size_exceeded',
                    ),
                ],
                'attr' => [
                    'accept' => '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt',
                ],
                'help' => 'document.help.file',
            ])
        ;

        // ── TISAX VDA-ISA 6.0 information classification — only when 'tisax'
        // module is active (T31/S2-P6). The TISAX label vocabulary (public /
        // internal / confidential / strictly_confidential) on documents is
        // automotive-supplier specific. Non-TISAX tenants keep documents
        // unclassified or use the generic category field.
        if ($this->isModuleActive('tisax')) {
            $builder->add('tisaxInformationClassification', ChoiceType::class, [
                'label' => 'document.field.data_classification',
                'required' => false,
                'placeholder' => 'document.placeholder.data_classification',
                'data' => $classificationDefault,
                'choices' => [
                    'document.classification.public' => 'public',
                    'document.classification.internal' => 'internal',
                    'document.classification.confidential' => 'confidential',
                    'document.classification.strictly_confidential' => 'strictly_confidential',
                ],
                'choice_translation_domain' => 'document',
                'help' => 'document.help.data_classification',
            ]);
        }

        // S14 Cluster A C1-04 — Multi-select for Controls covered by this
        // document (ISO 27001 Cl. 7.5 evidence linkage). Unmapped on Document
        // because the canonical link entity is DocumentControlLink (with
        // provenance metadata); DocumentController syncs rows on submit.
        // Pre-fills from existing DocumentControlLink rows for editing.
        $builder->add('linkedControls', EntityType::class, [
            'class'         => Control::class,
            'choice_label'  => function (Control $c): string {
                $code = $c->getControlNumber() ?? '';
                $name = $c->getName() ?? '';
                return trim($code . ' — ' . $name, ' —');
            },
            'multiple'      => true,
            'expanded'      => false,
            'required'      => false,
            'mapped'        => false,
            'label'         => 'document.field.linked_controls',
            'help'          => 'document.help.linked_controls',
            'attr'          => [
                'data-controller' => 'tom-select',
            ],
            'query_builder' => function (ControlRepository $r) {
                $qb = $r->createQueryBuilder('c')->orderBy('c.controlId', 'ASC');
                $tenant = $this->tenantContext->getCurrentTenant();
                if ($tenant !== null) {
                    $qb->andWhere('c.tenant = :tenant OR c.tenant IS NULL')
                       ->setParameter('tenant', $tenant);
                }
                return $qb;
            },
        ]);

        // Pre-populate linkedControls from existing DocumentControlLink rows.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $document = $event->getData();
            if (!$document instanceof Document || $document->getId() === null) {
                return;
            }
            $form = $event->getForm();
            if (!$form->has('linkedControls')) {
                return;
            }
            // We can't access the repo without DI here — preload happens via
            // controller (see DocumentController::edit). This block is left
            // as a deliberate seam: when the entity loads from DB, no
            // preloaded controls exist on the form-state side, so the
            // controller must pass them via the data argument. Pragmatically,
            // the controller passes the document and we read links via the
            // DocumentControlLinkRepository in the controller's pre-bind step.
        });

        // Editable policy body — only for wizard-generated documents.
        // The field is added conditionally via PRE_SET_DATA so it
        // never surfaces on uploaded files (would be confusing) and
        // never on freshly-uploaded forms (no template provenance yet).
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!$data instanceof Document) {
                return;
            }
            if ($data->getGeneratedFromTemplate() === null) {
                return;
            }
            $event->getForm()->add('policyBody', TextareaType::class, [
                'label' => 'document.policy_body.label',
                'required' => false,
                'help' => 'document.policy_body.help',
                'attr' => [
                    'rows' => 18,
                    'class' => 'font-monospace',
                    'spellcheck' => 'true',
                    'data-policy-body-editor' => 'true',
                ],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'translation_domain' => 'document',
            'is_new' => true,
        ]);
    }
}
