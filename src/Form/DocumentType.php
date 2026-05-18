<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Repository\SystemSettingsRepository;
use App\Service\ModuleConfigurationService;
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

final class DocumentType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly ModuleConfigurationService $moduleConfiguration,
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
            ])
            // V3 W2-LB-8 — Review-cycle cadence. Used by
            // DocumentApprovalListener to populate nextReviewDate
            // when the document is approved.
            ->add('reviewIntervalMonths', IntegerType::class, [
                'label' => 'document.field.review_interval_months',
                'required' => false,
                'help' => 'document.help.review_interval_months',
                'attr' => [
                    'min' => 1,
                    'max' => 60,
                    'step' => 1,
                ],
            ])
            // Phase 9.P2.1 — holding policy inheritance flags. Only
            // meaningful on a holding tenant; standalone tenants can
            // leave both at default.
            //
            // TODO(S2-P6 module-key): no dedicated 'holding' module exists in
            // config/modules.yaml today — the Holding-CISO concept is wired
            // through tenant.parentTenantId + ROLE_GROUP_CISO rather than a
            // feature-module toggle. Once a 'holding' module is registered
            // (S2-A scope), wrap these two fields in
            // $this->isModuleActive('holding') alongside tisaxInformationClassification
            // below. Until then they stay visible on every tenant — harmless
            // because standalone tenants just leave the defaults.
            ->add('inheritable', CheckboxType::class, [
                'label' => 'document.field.inheritable',
                'help' => 'document.help.inheritable',
                'required' => false,
            ])
            ->add('overrideAllowed', CheckboxType::class, [
                'label' => 'document.field.override_allowed',
                'help' => 'document.help.override_allowed',
                'required' => false,
            ])
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
