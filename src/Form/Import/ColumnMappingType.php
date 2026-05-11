<?php

declare(strict_types=1);

namespace App\Form\Import;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step-2 Bulk-Import form: confirm / override auto-detected column mappings.
 *
 * For each spreadsheet column header a ChoiceType field is rendered,
 * pre-filled with the best heuristic suggestion when confidence ≥ 0.6.
 * Users may override or choose "ignore" (null) for any column.
 *
 * The hidden `confirmedMapping` field is populated client-side via the
 * Stimulus bulk-import controller and carries the serialised final mapping
 * back to the server for persistence.
 *
 * Options consumed by the form:
 *   - headers        list<string>   spreadsheet column headers
 *   - entity_fields  list<string>   valid property names for the active entity type
 *   - auto_mappings  array<string, array{target: string, confidence: float}>
 *                    output of HeaderHeuristicMapper::suggestMappings()
 */
class ColumnMappingType extends AbstractType
{
    /** Minimum confidence required to pre-select a suggestion. */
    private const PREFILL_THRESHOLD = 0.6;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $headers */
        $headers = $options['headers'];
        /** @var list<string> $entityFields */
        $entityFields = $options['entity_fields'];
        /** @var array<string, array{target: string, confidence: float}> $autoMappings */
        $autoMappings = $options['auto_mappings'];

        // Build choices: entity fields + "— ignorieren —" opt.
        // The ignore sentinel uses the empty string '' (not null) because
        // Symfony's ChoiceType cannot reliably round-trip null as a choice
        // value when other non-null values are present.  The controller
        // treats '' as "skip this column".
        $fieldChoices = [];
        foreach ($entityFields as $field) {
            $fieldChoices['data_import.mapping.field.' . $field] = $field;
        }
        $fieldChoices['data_import.mapping.ignore'] = '';

        foreach ($headers as $index => $header) {
            $fieldName = 'column_' . $index;

            // Pre-fill when auto-mapping exists and confidence is sufficient.
            // Default to '' (ignore sentinel) when no mapping is confident enough.
            $defaultValue = '';
            if (
                isset($autoMappings[$header])
                && $autoMappings[$header]['confidence'] >= self::PREFILL_THRESHOLD
            ) {
                $defaultValue = $autoMappings[$header]['target'];
            }

            $builder->add($fieldName, ChoiceType::class, [
                'label'    => $header,
                'choices'  => $fieldChoices,
                'required' => false,
                'data'     => $defaultValue,
                'attr'     => [
                    'data-column-header' => $header,
                    'data-column-index'  => $index,
                ],
            ]);
        }

        $builder->add('confirmedMapping', HiddenType::class, [
            'label'    => false,
            'required' => false,
            'mapped'   => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'translation_domain' => 'data_import',
            'data_class'         => null,
            // defaults for required options (useful in tests; controller always passes these)
            'headers'        => [],
            'entity_fields'  => [],
            'auto_mappings'  => [],
        ]);

        $resolver->setRequired(['headers', 'entity_fields', 'auto_mappings']);

        $resolver->setAllowedTypes('headers', 'array');
        $resolver->setAllowedTypes('entity_fields', 'array');
        $resolver->setAllowedTypes('auto_mappings', 'array');
    }
}
