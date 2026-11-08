<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\RiskApprovalConfig;
use App\Form\SectionMapInterface;
use App\Form\Type\JsonBuilderType;
use App\Risk\RiskMatrixThresholds;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Risikokriterien (ISO 27001 6.1.2 a/b): acceptance thresholds and the risk
 * scale (band edges, what each level means). Top management approves both
 * together with the decision type „Risikokriterien“.
 */
final class RiskApprovalConfigType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'risk_assessment' => ['bandCriticalMin', 'bandHighMin', 'bandMediumMin', 'probabilityCriteria', 'impactCriteria'],
            'acceptance' => ['thresholdAutomatic', 'thresholdManager', 'thresholdExecutive', 'note'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('bandCriticalMin', IntegerType::class, self::band('critical'))
            ->add('bandHighMin', IntegerType::class, self::band('high'))
            ->add('bandMediumMin', IntegerType::class, self::band('medium'))
            ->add('probabilityCriteria', JsonBuilderType::class, self::criteria('probability'))
            ->add('impactCriteria', JsonBuilderType::class, self::criteria('impact'));

        $builder
            ->add('thresholdAutomatic', IntegerType::class, [
                'label' => 'risk_approval_config.field.automatic',
                'help' => 'risk_approval_config.help.automatic',
                'attr' => ['min' => 1, 'max' => RiskMatrixThresholds::SCORE_MAX, 'step' => 1],
            ])
            ->add('thresholdManager', IntegerType::class, [
                'label' => 'risk_approval_config.field.manager',
                'help' => 'risk_approval_config.help.manager',
                'attr' => ['min' => 1, 'max' => RiskMatrixThresholds::SCORE_MAX, 'step' => 1],
            ])
            ->add('thresholdExecutive', IntegerType::class, [
                'label' => 'risk_approval_config.field.executive',
                'help' => 'risk_approval_config.help.executive',
                'attr' => ['min' => 1, 'max' => RiskMatrixThresholds::SCORE_MAX, 'step' => 1],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'risk_approval_config.field.note',
                'help' => 'risk_approval_config.help.note',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
            ]);
    }

    /** @return array<string, mixed> */
    private static function band(string $band): array
    {
        return [
            'label' => 'risk_approval_config.field.band_' . $band,
            'help' => 'risk_approval_config.help.band_' . $band,
            'attr' => ['min' => 2, 'max' => RiskMatrixThresholds::SCORE_MAX, 'step' => 1],
        ];
    }

    /** @return array<string, mixed> What levels 1–5 of an axis mean here (JSON object level => text). */
    private static function criteria(string $axis): array
    {
        $levels = [];
        for ($level = 1; $level <= RiskMatrixThresholds::LEVELS; ++$level) {
            $levels[] = [
                'key' => (string) $level,
                'type' => 'text',
                'label' => 'risk_approval_config.criteria.' . $axis . '_' . $level,
            ];
        }

        return [
            'label' => 'risk_approval_config.field.' . $axis . '_criteria',
            'help' => 'risk_approval_config.help.' . $axis . '_criteria',
            'required' => false,
            'shape' => 'object',
            'fields' => $levels,
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RiskApprovalConfig::class,
            'translation_domain' => 'risk_approval_config',
        ]);
    }
}
