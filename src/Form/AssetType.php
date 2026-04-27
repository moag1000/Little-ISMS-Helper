<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AssetType extends AbstractType
{
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
            ->add('ownerUser', EntityType::class, [
                'label' => 'asset.field.owner',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'asset.placeholder.owner_user',
                'attr' => ['class' => 'form-select'],
                'help' => 'asset.help.owner_user',
            ])
            ->add('owner', TextType::class, [
                'label' => 'asset.field.owner_legacy',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'asset.placeholder.owner',
                ],
                'help' => 'asset.help.owner',
            ])
            ->add('physicalLocation', EntityType::class, [
                'label' => 'asset.field.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'asset.placeholder.location_select',
                'attr' => [
                    'class' => 'form-select',
                ],
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
                    'class' => 'form-select',
                    'data-controller' => 'tom-select',
                ],
                'help' => 'asset.help.depends_on',
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
            ->add('status', ChoiceType::class, [
                'label' => 'asset.field.status',
                'choices' => [
                    'asset.status.active' => 'active',
                    'asset.status.inactive' => 'inactive',
                    'asset.status.in_use' => 'in_use',
                    'asset.status.returned' => 'returned',
                    'asset.status.retired' => 'retired',
                    'asset.status.disposed' => 'disposed',
                ],
                'required' => true,
                'help' => 'asset.help.status',
                'choice_translation_domain' => 'asset',
            ])
            // ── AI-Agent fields (only relevant when assetType = 'ai_agent') ──
            // Erfüllt EU AI Act Art. 6/9-16, ISO 42001 Annex A, MRIS MHC-13.
            // All fields nullable — only meaningful for ai_agent subtype.
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
            ->add('aiAgentCapabilityScope', TextareaType::class, [
                'label' => 'asset.ai_agent.field.capability_scope',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.ai_agent.placeholder.capability_scope',
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
            ->add('aiAgentExtensionAllowlist', TextareaType::class, [
                'label' => 'asset.ai_agent.field.extension_allowlist',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'asset.ai_agent.placeholder.extension_allowlist',
                    'data-depends-on' => 'asset_form_assetType',
                    'data-depends-on-value' => 'ai_agent',
                ],
                'help' => 'asset.ai_agent.help.extension_allowlist',
            ])
        ;

        // Array <-> textarea (one entry per line) transformers for the two
        // JSON columns. Empty input persists as null; otherwise lines are
        // trimmed and empty lines dropped.
        $arrayTransformer = new CallbackTransformer(
            // model (?array) -> view (string)
            static function (?array $value): string {
                if ($value === null || $value === []) {
                    return '';
                }

                return implode("\n", $value);
            },
            // view (?string) -> model (?array)
            static function (?string $value): ?array {
                if ($value === null) {
                    return null;
                }
                $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
                $cleaned = array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));

                return $cleaned === [] ? null : $cleaned;
            }
        );

        $builder->get('aiAgentCapabilityScope')->addModelTransformer($arrayTransformer);
        $builder->get('aiAgentExtensionAllowlist')->addModelTransformer($arrayTransformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
            'translation_domain' => 'asset',
        ]);
    }
}
