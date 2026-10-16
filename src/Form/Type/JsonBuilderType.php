<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\JsonArrayTransformer;
use App\Validator\Constraint\JsonShape;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * JsonBuilderType — structured editor for a JSON-array entity column.
 *
 * One generic, schema-driven builder instead of a bespoke macro per field:
 * the FormType declares the shape and its typed fields, the form theme
 * (templates/form/json_builder.html.twig) renders the Aurora builder
 * (_fa_json_builder.html.twig + json_builder_controller.js), and JsonShape
 * re-validates the submitted structure server-side. Users never see or type
 * JSON; the hidden textarea only transports the serialised value.
 *
 * Shapes:
 *   list   — [{field: value, …}, …]           e.g. action items, on-call rota
 *   map    — {key: {field: value, …}, …}      e.g. authorities per jurisdiction
 *            {key: scalar, …} with scalar_value=true (fields[0] is the value)
 *   object — {field: value, …}                 e.g. channel config, conditions
 *
 * Field types: text, textarea, email, url, number, date (Y-m-d), bool,
 * select (choices: label => value), tags (list of strings; item_type: email).
 * Field keys: key, type, label (translation key), required, choices,
 * suggestions (datalist values), help, placeholder, item_type.
 *
 * Keys not declared in `fields` survive a round-trip untouched (the builder
 * edits only what it knows) and keys in `hidden_keys` are not shown at all —
 * e.g. the `_global` block of Tenant.notificationPreferences, which has its
 * own settings page.
 *
 * Wire-up:
 *   $builder->add('actionItemsWithDeadlines', JsonBuilderType::class, [
 *       'label'  => 'management_review.field.action_items_with_deadlines',
 *       'shape'  => 'list',
 *       'fields' => [
 *           ['key' => 'action', 'type' => 'text', 'label' => '…', 'required' => true],
 *           ['key' => 'deadline', 'type' => 'date', 'label' => '…'],
 *       ],
 *   ]);
 *
 * View model:  JSON string (hidden textarea)
 * Norm model:  array<mixed, mixed>|null
 */
final class JsonBuilderType extends AbstractType
{
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'url', 'number', 'date', 'bool', 'select', 'tags'];

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'json_builder';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new JsonArrayTransformer());
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['json_builder'] = [
            'shape' => $options['shape'],
            'fields' => $options['fields'],
            'scalar_value' => $options['scalar_value'],
            'hidden_keys' => $options['hidden_keys'],
            'key_label' => $options['key_label'],
            'key_suggestions' => $options['key_suggestions'],
            'add_label' => $options['add_label'],
            'empty_label' => $options['empty_label'],
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['shape', 'fields']);
        $resolver->setDefaults([
            'scalar_value' => false,
            'hidden_keys' => [],
            'key_label' => null,
            'key_suggestions' => [],
            'add_label' => null,
            'empty_label' => null,
            'invalid_message' => 'json_builder.invalid_structure',
            'constraints' => static fn (Options $options): array => [
                new JsonShape(
                    shape: $options['shape'],
                    fields: $options['fields'],
                    scalarValue: $options['scalar_value'],
                    translationDomain: is_string($options['translation_domain']) ? $options['translation_domain'] : 'messages',
                ),
            ],
        ]);
        $resolver->setAllowedValues('shape', ['list', 'map', 'object']);
        $resolver->setAllowedTypes('fields', 'array');
        $resolver->setAllowedTypes('scalar_value', 'bool');
        $resolver->setAllowedTypes('hidden_keys', 'string[]');
        $resolver->setAllowedTypes('key_label', ['null', 'string']);
        $resolver->setAllowedTypes('key_suggestions', 'string[]');
        $resolver->setNormalizer('fields', static function (Options $options, array $fields): array {
            $normalized = [];
            foreach ($fields as $field) {
                if (!is_array($field) || !isset($field['key'], $field['type'])
                    || !in_array($field['type'], self::FIELD_TYPES, true)) {
                    throw new InvalidOptionsException('JsonBuilderType: every field needs a "key" and a "type" out of ' . implode(', ', self::FIELD_TYPES) . '.');
                }
                $normalized[] = $field + ['label' => $field['key'], 'required' => false];
            }
            if ($normalized === []) {
                throw new InvalidOptionsException('JsonBuilderType: "fields" must not be empty.');
            }

            return $normalized;
        });
    }
}
