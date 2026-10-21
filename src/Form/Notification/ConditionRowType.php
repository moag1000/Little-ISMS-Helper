<?php

declare(strict_types=1);

namespace App\Form\Notification;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
            ])
            // Stable key of the row's original array value (see ConditionRowOriginals).
            ->add(ConditionRowTransformer::ROW_KEY, HiddenType::class, ['required' => false]);

        $builder->addModelTransformer(new ConditionRowTransformer(
            $options['originals'] ?? new ConditionRowOriginals(),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'translation_domain' => 'notification',
            // Shared by every row of one collection (NotificationRuleType), so a
            // row's original value is found by its key, not by its index.
            'originals'          => null,
        ]);
        $resolver->setAllowedTypes('originals', ['null', ConditionRowOriginals::class]);
    }
}
