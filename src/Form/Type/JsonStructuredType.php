<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * JsonStructuredType — transport type for a structured JSON-array entity
 * column whose editor is a bespoke builder theme.
 *
 * The value round-trips through `JsonArrayTransformer` so invalid JSON yields
 * a field error instead of a silent `null` overwrite (regression-bug-pattern
 * Section 15). It is NOT a user-facing editor: every field using it MUST be
 * rendered by a custom `_<form>_<field>_row` theme block that mounts a
 * structured builder (see templates/business_continuity_plan/
 * _bc_plan_form_theme.html.twig, templates/audit_finding/
 * _audit_finding_form_theme.html.twig). Gate 34
 * (scripts/quality/check_raw_json_textarea.py) fails a field without one.
 *
 * For a new JSON field prefer the generic, schema-driven JsonBuilderType —
 * it brings its own builder, theme and server-side shape validation. Flat
 * lists of strings use JsonTagsType.
 *
 * View model:  pretty-printed JSON string
 * Norm model:  array<mixed, mixed>|null
 */
final class JsonStructuredType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new JsonArrayTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => ['rows' => 6, 'class' => 'font-monospace'],
            'invalid_message' => 'form.json.invalid',
        ]);
    }
}
