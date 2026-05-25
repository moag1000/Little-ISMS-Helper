<?php

declare(strict_types=1);

namespace App\Form\Entry;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Single evidence-artifact row inside BCExercise.evidenceArtifacts.
 *
 * ISO 22301 §8.6 audit evidence. Per entity docblock the legacy shape is:
 *   {type: 'photo'|'log'|'report'|'screenshot', reference, description}
 *
 * This refactor moves the user-facing fields to:
 *   {type, name, url, capturedAt} — `reference` is preserved as a legacy
 * alias via data_class=null (extra keys round-trip safely through Symfony's
 * array-form mapping). `description` likewise survives on existing rows.
 *
 * S5 Bucket 5 — replaces JsonStructuredType freetext-JSON editing.
 */
final class EvidenceArtifactEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'bc_exercises.evidence.field.type',
                'required' => false,
                'placeholder' => 'bc_exercises.evidence.placeholder.type',
                'choices' => [
                    'bc_exercises.evidence.type.photo' => 'photo',
                    'bc_exercises.evidence.type.log' => 'log',
                    'bc_exercises.evidence.type.report' => 'report',
                    'bc_exercises.evidence.type.screenshot' => 'screenshot',
                    'bc_exercises.evidence.type.video' => 'video',
                    'bc_exercises.evidence.type.transcript' => 'transcript',
                    'bc_exercises.evidence.type.signoff' => 'signoff',
                    'bc_exercises.evidence.type.other' => 'other',
                ],
                'attr' => ['class' => 'form-select form-select-sm'],
            ])
            ->add('name', TextType::class, [
                'label' => 'bc_exercises.evidence.field.name',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'bc_exercises.evidence.placeholder.name',
                    'class' => 'form-control form-control-sm',
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'bc_exercises.evidence.field.url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'maxlength' => 2048,
                    'placeholder' => 'bc_exercises.evidence.placeholder.url',
                    'class' => 'form-control form-control-sm',
                ],
            ])
            ->add('capturedAt', DateTimeType::class, [
                'label' => 'bc_exercises.evidence.field.captured_at',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'attr' => ['class' => 'form-control form-control-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'bc_exercises',
            'empty_data' => static fn (): array => [
                'type' => null,
                'name' => '',
                'url' => '',
                'capturedAt' => null,
            ],
        ]);
    }
}
