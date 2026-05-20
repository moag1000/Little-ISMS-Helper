<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * JsonStructuredType — textarea input bound to a structured JSON-array entity
 * column (map or list-of-objects).
 *
 * Replaces raw `TextareaType` for `#[ORM\Column(type: Types::JSON)]` properties
 * whose shape is more than a flat list of strings (use `JsonTagsType` for those).
 * Users still author JSON by hand, but the value is now round-tripped through
 * `JsonArrayTransformer` so invalid JSON produces a user-friendly
 * `TransformationFailedException` instead of a silent `null` overwrite or a
 * crash deeper in the persistence layer (regression-bug-pattern Section 15).
 *
 * Wire-up:
 *   $builder->add('settings', JsonStructuredType::class, [
 *       'label' => 'tenant.field.settings',
 *       'attr'  => ['rows' => 10, 'class' => 'font-monospace'],
 *   ]);
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
