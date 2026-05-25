<?php

declare(strict_types=1);

namespace App\Form\Entry;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Single competency row inside User.competencies.
 *
 * Backs the ISO 27001 §7.2 Competence shape per entity docblock:
 *   {name, category, level: 1-5, certifiedBy, certifiedAt, expiresAt}
 *
 * Adds an explicit `framework` field (ISO27001 / ISO27701 / NIS2 / DORA /
 * BSI / other) so the same competency row can document training against
 * specific compliance frameworks — the value lands in the same JSON column
 * (extra associative key, harmless to legacy rows).
 *
 * S5 Bucket 5 — replaces JsonStructuredType freetext-JSON editing.
 */
final class CompetencyEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'user.competency.field.name',
                'required' => false,
                'attr' => [
                    'maxlength' => 200,
                    'placeholder' => 'user.competency.placeholder.name',
                    'class' => 'form-control form-control-sm',
                ],
            ])
            ->add('framework', ChoiceType::class, [
                'label' => 'user.competency.field.framework',
                'required' => false,
                'placeholder' => 'user.competency.placeholder.framework',
                'choices' => [
                    'user.competency.framework.iso27001' => 'iso27001',
                    'user.competency.framework.iso27701' => 'iso27701',
                    'user.competency.framework.iso22301' => 'iso22301',
                    'user.competency.framework.iso31000' => 'iso31000',
                    'user.competency.framework.nis2' => 'nis2',
                    'user.competency.framework.dora' => 'dora',
                    'user.competency.framework.bsi' => 'bsi',
                    'user.competency.framework.gdpr' => 'gdpr',
                    'user.competency.framework.other' => 'other',
                ],
                'attr' => ['class' => 'form-select form-select-sm'],
            ])
            ->add('level', ChoiceType::class, [
                'label' => 'user.competency.field.level',
                'required' => false,
                'placeholder' => 'user.competency.placeholder.level',
                'choices' => [
                    'user.competency.level.basic' => 'basic',
                    'user.competency.level.intermediate' => 'intermediate',
                    'user.competency.level.expert' => 'expert',
                ],
                'attr' => ['class' => 'form-select form-select-sm'],
            ])
            ->add('certifiedAt', DateType::class, [
                'label' => 'user.competency.field.certified_at',
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
            'translation_domain' => 'user',
            'empty_data' => static fn (): array => [
                'name' => '',
                'framework' => null,
                'level' => null,
                'certifiedAt' => null,
            ],
        ]);
    }
}
