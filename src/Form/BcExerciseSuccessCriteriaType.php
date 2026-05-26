<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\SuccessCriteriaShapeTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * BcExerciseSuccessCriteriaType — proper FormType wrapper around the
 * Stimulus-driven `_fa_success_criteria.html.twig` JsonBuilder.
 *
 * Closes Bucket 5 item 5.3 (DEFERRED). The widget itself stays — but now
 * it's behind a real FormType that owns the shape-normalisation via
 * `SuccessCriteriaShapeTransformer`, so call-sites no longer have to know
 * about the two co-existing storage shapes (Shape A rich list vs Shape B
 * legacy flat map).
 *
 * Render path:
 *   1. Entity attribute → SuccessCriteriaShapeTransformer::transform
 *      coerces Shape B → Shape A → emits pretty-printed JSON.
 *   2. The form theme block `_bc_exercise_successCriteria_row`
 *      (templates/bc_exercise/_bc_exercise_form_theme.html.twig)
 *      forwards `form.vars.value` (= JSON string) plus
 *      `success_criteria_prefill` template var to the
 *      `_fa_success_criteria.render(...)` macro.
 *
 * Submit path:
 *   1. Stimulus controller serialises the editor state into the hidden
 *      textarea named after this field.
 *   2. TextareaType reverse-transforms via
 *      `SuccessCriteriaShapeTransformer::reverseTransform` → array|null.
 *
 * Wire-up (replaces `JsonStructuredType::class` in BCExerciseType):
 *   $builder->add('successCriteria', BcExerciseSuccessCriteriaType::class, [
 *       'label' => 'bc_exercises.field.success_criteria',
 *       'help'  => 'bc_exercises.help.success_criteria_json',
 *   ]);
 */
final class BcExerciseSuccessCriteriaType extends AbstractType
{
    public function getParent(): string
    {
        // We extend TextareaType so the form theme block
        // `_bc_exercise_successCriteria_row` continues to match (Symfony
        // form themes resolve by block-prefix; the prefix stays the same).
        return TextareaType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new SuccessCriteriaShapeTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'attr' => [
                'rows' => 4,
                'class' => 'font-monospace',
            ],
            'invalid_message' => 'form.json.invalid',
            'translation_domain' => 'bc_exercises',
        ]);
    }
}
