<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * JsonTagsType — single-line tag input bound to a JSON-array entity column.
 *
 * Replaces raw `TextareaType` for `#[ORM\Column(type: Types::JSON)]` properties
 * that store a flat list of strings (User.competencies, Vulnerability.references,
 * InternalAudit.objectives, …). Users get a chip-style tom-select widget with
 * `create:true` instead of having to remember JSON syntax.
 *
 * View model:  CSV string  (e.g. "iso27001,pci-dss,bsi-it-gs")
 * Norm model:  list<string> (deduplicated, trimmed, empty entries dropped)
 *
 * Wire-up:
 *   $builder->add('competencies', JsonTagsType::class, [
 *       'label'       => 'user.field.competencies',
 *       'placeholder' => 'user.placeholder.competencies',
 *   ]);
 *
 * The accompanying Stimulus controller `tom-select` with `data-tom-select-
 * create-value="true"` enables the chip-input + free-tag UX.
 */
final class JsonTagsType extends AbstractType
{
    public function getParent(): string
    {
        return TextType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            // norm (array<string>) -> view (CSV string)
            static function ($value): string {
                if (!is_array($value)) {
                    return '';
                }
                return implode(',', array_map(static fn ($v): string => (string) $v, $value));
            },
            // view (CSV string) -> norm (array<string>)
            static function ($value): array {
                if (!is_string($value) || $value === '') {
                    return [];
                }
                $parts = array_map('trim', explode(',', $value));
                $parts = array_filter($parts, static fn (string $p): bool => $p !== '');
                return array_values(array_unique($parts));
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'attr' => [],
            'placeholder' => null,
        ]);
        $resolver->setAllowedTypes('placeholder', ['null', 'string']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $existing = $view->vars['attr'] ?? [];
        $view->vars['attr'] = array_merge([
            'data-controller'                   => 'tom-select',
            'data-tom-select-create-value'      => 'true',
            'data-tom-select-remove-button-value' => 'true',
            'data-tom-select-delimiter-value'   => ',',
            'data-tom-select-placeholder-value' => (string) ($options['placeholder'] ?? ''),
            'autocomplete'                      => 'off',
            'data-1p-ignore'                    => 'true',
        ], $existing);
    }
}
