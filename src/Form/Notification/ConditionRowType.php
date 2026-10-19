<?php

declare(strict_types=1);

namespace App\Form\Notification;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single notification-rule condition: field + op + value.
 *
 * The keys are exactly what NotificationRuleEvaluator reads
 * (`{"field": "severity", "op": ">=", "value": "high"}`); the operator list is
 * the evaluator's. `in` takes a list: it is edited as comma-separated text and
 * stored as an array (see the row transformer), so rules/templates that carry
 * array values stay editable. Rendered as a chip row by
 * _fa_condition_builder.html.twig.
 */
final class ConditionRowType extends AbstractType
{
    /** Evaluator operators, label => value. */
    public const OPERATORS = [
        '=' => '==',
        '≠' => '!=',
        '>' => '>',
        '≥' => '>=',
        '<' => '<',
        '≤' => '<=',
        'notification.condition.op.contains' => 'contains',
        'notification.condition.op.in' => 'in',
    ];

    /**
     * Keys of the state the domain events carry (DomainEventDetector) and the
     * notification templates use; offered as datalist suggestions, any key is
     * allowed.
     */
    public const FIELD_SUGGESTIONS = [
        'severity', 'status', 'category', 'title', 'score', 'filename',
        'evidenceOutdated', 'criticality', 'hours_elapsed', 'exceeds_appetite',
        'step_status', 'verification_overdue',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', TextType::class, [
                'label'       => 'notification.condition.field',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 64)],
                'attr'        => [
                    'placeholder' => 'notification.condition.field_placeholder',
                    'class'       => 'fa-condition-chip__control',
                    'list'        => 'fa-condition-fields',
                ],
            ])
            ->add('op', ChoiceType::class, [
                'label'       => 'notification.condition.operator',
                'choices'     => self::OPERATORS,
                'constraints' => [new Assert\NotBlank()],
                'attr'        => ['class' => 'fa-condition-chip__control fa-condition-chip__control--op'],
            ])
            ->add('value', TextType::class, [
                'label'       => 'notification.condition.value',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
                'attr'        => [
                    'placeholder' => 'notification.condition.value_placeholder',
                    'class'       => 'fa-condition-chip__control',
                ],
            ]);

        $builder->addModelTransformer(new CallbackTransformer(
            // array value (op `in`) -> "a, b" for the text input
            static function (mixed $row): mixed {
                if (is_array($row) && is_array($row['value'] ?? null)) {
                    $row['value'] = implode(', ', array_map(static fn (mixed $v): string => (string) (is_bool($v) ? (int) $v : $v), $row['value']));
                }

                return $row;
            },
            // "a, b" -> ['a', 'b'] when the operator is `in`
            static function (mixed $row): mixed {
                if (is_array($row) && ($row['op'] ?? null) === 'in' && is_string($row['value'] ?? null)) {
                    $items = [];
                    foreach (explode(',', $row['value']) as $part) {
                        $part = trim($part);
                        if ($part === '') {
                            continue;
                        }
                        // Keep numbers numeric: the evaluator compares `in` strictly.
                        $items[] = match (true) {
                            (string) (int) $part === $part => (int) $part,
                            is_numeric($part) && (string) (float) $part === $part => (float) $part,
                            default => $part,
                        };
                    }
                    $row['value'] = $items;
                }

                return $row;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'translation_domain' => 'notification',
        ]);
    }
}
