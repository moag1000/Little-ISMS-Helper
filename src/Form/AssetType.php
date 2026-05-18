<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Form\Trait\OwnerPickerFormTrait;
use App\Form\Type\JsonTagsType;
use App\Service\ModuleConfigurationService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AssetType extends AbstractType
{
    use ModuleAwareFormTrait;
    use OwnerPickerFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'asset.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'asset.placeholder.name',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'asset.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.placeholder.description',
                ],
                'help' => 'asset.help.description',
            ])
            ->add('assetType', ChoiceType::class, [
                'label' => 'asset.field.type',
                'choices' => [
                    'asset.type.information' => 'Information',
                    'asset.type.software' => 'Software',
                    'asset.type.hardware' => 'Hardware',
                    'asset.type.service' => 'Service',
                    'asset.type.personnel' => 'Personnel',
                    'asset.type.physical' => 'Physical',
                    'asset.type.ai_agent' => 'ai_agent',
                ],
                'required' => true,
                'choice_translation_domain' => 'asset',
                'attr' => [
                    'id' => 'asset_form_assetType',
                    'data-asset-form-target' => 'assetTypeSelect',
                ],
            ])
        ;

        // ── Owner cluster (audit-s4 P-1) ────────────────────────────────────
        // Replaces 4 hand-rolled add() calls (ownerUser/ownerPerson/
        // ownerDeputyPersons/owner) with one shared helper. Pattern A
        // dual-state semantics are preserved at the entity layer
        // (validateOwnerSlot stays below, getEffectiveOwner stays in Asset).
        $this->addOwnerPicker($builder, [
            'user_field'         => 'ownerUser',
            'person_field'       => 'ownerPerson',
            'deputies_field'     => 'ownerDeputyPersons',
            'legacy_field'       => 'owner',
            'translation_prefix' => 'asset',
            'user_label'         => 'asset.field.owner',
            'user_placeholder'   => 'asset.placeholder.owner_user',
            'legacy_label'       => 'asset.field.owner_legacy',
            'legacy_help'        => 'asset.help.owner',
            'legacy_placeholder' => 'asset.placeholder.owner',
        ]);

        $builder
            ->add('physicalLocation', EntityType::class, [
                'label' => 'asset.field.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'asset.placeholder.location_select',
                                'help' => 'asset.help.physical_location',
            ])
            ->add('dependsOn', EntityType::class, [
                'label' => 'asset.field.depends_on',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'query_builder' => static function ($repo) {
                    return $repo->createQueryBuilder('a')->orderBy('a.name', 'ASC');
                },
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'asset.help.depends_on',
            ])
            // V3 W2-Bug3 — linked Processing Activities (M:N inverse).
            // Edit from either side; the owning side lives on
            // ProcessingActivity::$assets.
            // @no-module-gate-required: M:N inverse to ProcessingActivity. When privacy is off,
            //   no PA exists, so the EntityType list is empty and the field hides itself visually.
            //   No data leak.
            ->add('processingActivities', EntityType::class, [
                'label' => 'asset.field.processing_activities',
                'help' => 'asset.help.processing_activities',
                'class' => ProcessingActivity::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'query_builder' => static function ($repo) {
                    return $repo->createQueryBuilder('p')->orderBy('p.name', 'ASC');
                },
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
            ])
            ->add('acquisitionValue', NumberType::class, [
                'label' => 'asset.field.acquisition_value',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00',
                ],
                'help' => 'asset.help.acquisition_value',
            ])
            ->add('currentValue', NumberType::class, [
                'label' => 'asset.field.current_value',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => '0.00',
                ],
                'help' => 'asset.help.current_value',
            ])
            ->add('confidentialityValue', IntegerType::class, [
                'label' => 'asset.field.confidentiality',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.confidentiality',
            ])
            ->add('integrityValue', IntegerType::class, [
                'label' => 'asset.field.integrity',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.integrity',
            ])
            ->add('availabilityValue', IntegerType::class, [
                'label' => 'asset.field.availability',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
                'help' => 'asset.help.availability',
            ])
            // monetaryValue removed from form (Junior-Finding #9): redundant to
            // acquisitionValue + currentValue. DB column preserved for
            // backwards compatibility; existing values surface via asset.show
            // if ever needed, but not maintained through the form anymore.
            ->add('dataClassification', ChoiceType::class, [
                'label' => 'asset.field.data_classification',
                'choices' => [
                    'asset.classification.public' => 'public',
                    'asset.classification.internal' => 'internal',
                    'asset.classification.confidential' => 'confidential',
                    'asset.classification.restricted' => 'restricted',
                ],
                'required' => false,
                'placeholder' => 'asset.placeholder.data_classification',
                'help' => 'asset.help.data_classification',
                'choice_translation_domain' => 'asset',
            ])
            ->add('acceptableUsePolicy', TextareaType::class, [
                'label' => 'asset.field.acceptable_use_policy',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'asset.placeholder.acceptable_use_policy',
                ],
                'help' => 'asset.help.acceptable_use_policy',
            ])
            ->add('handlingInstructions', TextareaType::class, [
                'label' => 'asset.field.handling_instructions',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.placeholder.handling_instructions',
                ],
                'help' => 'asset.help.handling_instructions',
            ])
            ->add('returnDate', null, [
                'label' => 'asset.field.return_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'asset.help.return_date',
            ])
            ->add('status', EnumType::class, [
                'label' => 'asset.field.status',
                'class' => AssetStatus::class,
                'choice_label' => fn(AssetStatus $s): string => 'asset.status.' . $s->value,
                // Status is stored as VARCHAR (?string) on the entity; accept either
                // an enum case OR its raw string value so EnumType can resolve the
                // currently-selected option from both Doctrine hydration paths.
                'choice_value' => fn(AssetStatus|string|null $c): ?string =>
                    $c instanceof AssetStatus ? $c->value : $c,
                'required' => true,
                'help' => 'asset.help.status',
                'choice_translation_domain' => 'asset',
            ])
        ;

        // ── DORA scope flag — only when nis2_dora module is active ──────────
        // DORA Art. 28 — Register of Information. Flag marks this asset as
        // in-scope for the ICT-risk monitoring obligation.
        if ($this->isModuleActive('nis2_dora')) {
            $builder->add('isDoraRelevant', CheckboxType::class, [
                'label'    => 'asset.field.is_dora_relevant',
                'help'     => 'asset.help.is_dora_relevant',
                'required' => false,
            ]);
        }

        // ── TISAX VDA-ISA 6.0 information-classification overlay — only when
        // 'tisax' module is active. Sits orthogonal to the generic
        // dataClassification field so the TISAX label vocabulary
        // (public/internal/confidential/strictly_confidential/prototype) does
        // not clutter non-automotive tenants. (T31/S2-P6)
        if ($this->isModuleActive('tisax')) {
            $builder->add('tisaxInformationClassification', ChoiceType::class, [
                'label' => 'asset.field.tisax_information_classification',
                'choices' => [
                    'asset.tisax_classification.public' => 'public',
                    'asset.tisax_classification.internal' => 'internal',
                    'asset.tisax_classification.confidential' => 'confidential',
                    'asset.tisax_classification.strictly_confidential' => 'strictly_confidential',
                    'asset.tisax_classification.prototype' => 'prototype',
                ],
                'required' => false,
                'placeholder' => 'asset.placeholder.tisax_information_classification',
                'help' => 'asset.help.tisax_information_classification',
                'choice_translation_domain' => 'asset',
            ]);
        }

        // ── AI-Agent fields: only added when 'ai_governance' module is active ──
        // Erfüllt EU AI Act Art. 6/9-16, ISO 42001 Annex A, MRIS MHC-13.
        // Stimulus show/hide via data-depends-on (assetType=ai_agent) is kept
        // intact — module gate is the outer guard.
        if ($this->isModuleActive('ai_governance')) {
            $this->addAiAgentFields($builder);
        }

        // Note: aiAgentCapabilityScope + aiAgentExtensionAllowlist used to
        // get a CallbackTransformer here (textarea-as-newline-list ↔ ?array).
        // Both fields now use JsonTagsType which ships its own array↔CSV
        // DataTransformer + tom-select chip-input UX. Adding a second
        // transformer would double-encode and break form submission.
    }

    /**
     * Adds all 9 AI-Agent inventory fields to the builder.
     * Called only when the 'ai_governance' module is active.
     * All fields nullable — only meaningful for ai_agent subtype.
     * Stimulus show/hide (data-depends-on assetType=ai_agent) is preserved.
     */
    private function addAiAgentFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('aiAgentClassification', ChoiceType::class, [
                'label' => 'asset.ai_agent.field.classification',
                'choices' => [
                    'asset.ai_agent.classification.prohibited' => 'prohibited',
                    'asset.ai_agent.classification.high_risk' => 'high_risk',
                    'asset.ai_agent.classification.limited_risk' => 'limited_risk',
                    'asset.ai_agent.classification.minimal_risk' => 'minimal_risk',
                ],
                'required' => false,
                'placeholder' => 'asset.ai_agent.placeholder.classification',
                'help' => 'asset.ai_agent.help.classification',
                'choice_translation_domain' => 'asset',
                'attr' => [
                    'id' => 'asset_form_aiAgentClassification',
                    'data-asset-form-target' => 'classification',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
            ])
            ->add('aiAgentPurpose', TextareaType::class, [
                'label' => 'asset.ai_agent.field.purpose',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'asset.ai_agent.placeholder.purpose',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.purpose',
            ])
            ->add('aiAgentDataSources', TextareaType::class, [
                'label' => 'asset.ai_agent.field.data_sources',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'asset.ai_agent.placeholder.data_sources',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.data_sources',
            ])
            ->add('aiAgentOversightMechanism', TextType::class, [
                'label' => 'asset.ai_agent.field.oversight_mechanism',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'asset.ai_agent.placeholder.oversight_mechanism',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.oversight_mechanism',
            ])
            ->add('aiAgentProvider', TextType::class, [
                'label' => 'asset.ai_agent.field.provider',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'asset.ai_agent.placeholder.provider',
                    'list' => 'asset_ai_agent_provider_suggestions',
                    'id' => 'asset_form_aiAgentProvider',
                    'data-asset-form-target' => 'provider',
                    'data-action' => 'change->asset-form#suggestClassification input->asset-form#suggestClassification',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.provider',
            ])
            ->add('aiAgentModelVersion', TextType::class, [
                'label' => 'asset.ai_agent.field.model_version',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'asset.ai_agent.placeholder.model_version',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.model_version',
            ])
            ->add('aiAgentCapabilityScope', JsonTagsType::class, [
                'label' => 'asset.ai_agent.field.capability_scope',
                'required' => false,
                'placeholder' => 'asset.ai_agent.placeholder.capability_scope',
                'attr' => [
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.capability_scope',
            ])
            ->add('aiAgentThreatModelDocId', IntegerType::class, [
                'label' => 'asset.ai_agent.field.threat_model_doc_id',
                'required' => false,
                'attr' => [
                    'min' => 1,
                    'placeholder' => 'asset.ai_agent.placeholder.threat_model_doc_id',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.threat_model_doc_id',
            ])
            ->add('aiAgentExtensionAllowlist', JsonTagsType::class, [
                'label' => 'asset.ai_agent.field.extension_allowlist',
                'required' => false,
                'placeholder' => 'asset.ai_agent.placeholder.extension_allowlist',
                'attr' => [
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.extension_allowlist',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
            'translation_domain' => 'asset',
            'constraints' => [
                new Callback([$this, 'validateOwnerSlot']),
            ],
        ]);
    }

    public function validateOwnerSlot(?Asset $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getOwnerUser() === null && $entity->getOwnerPerson() === null) {
            $context->buildViolation('asset.error.owner_required_user_or_person')
                ->atPath('ownerUser')
                ->addViolation();
        }
    }
}
