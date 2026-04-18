<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\KpiThresholdConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class KpiThresholdConfigType extends AbstractType
{
    /** List of tunable KPI keys; extend as new KPIs gain tenant-configurable thresholds. */
    public const KPI_KEYS = [
        'kpi_threshold.key.control_compliance' => 'control_compliance',
        'kpi_threshold.key.control_compliance_weighted' => 'control_compliance_weighted',
        'kpi_threshold.key.risk_treatment_rate' => 'risk_treatment_rate',
        'kpi_threshold.key.asset_classification_rate' => 'asset_classification_rate',
        'kpi_threshold.key.training_completion_rate' => 'training_completion_rate',
        'kpi_threshold.key.supplier_assessment_rate' => 'supplier_assessment_rate',
        'kpi_threshold.key.bia_coverage' => 'bia_coverage',
        'kpi_threshold.key.implementation_readiness' => 'implementation_readiness',
        'kpi_threshold.key.isms_health_score' => 'isms_health_score',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kpiKey', ChoiceType::class, [
                'label' => 'kpi_threshold.field.kpi_key',
                'choices' => self::KPI_KEYS,
                'placeholder' => 'kpi_threshold.placeholder.kpi_key',
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'help' => 'kpi_threshold.help.kpi_key',
            ])
            ->add('goodThreshold', IntegerType::class, [
                'label' => 'kpi_threshold.field.good_threshold',
                'required' => true,
                'attr' => ['min' => 0, 'max' => 100],
                'constraints' => [new Assert\Range(min: 0, max: 100)],
                'help' => 'kpi_threshold.help.good_threshold',
            ])
            ->add('warningThreshold', IntegerType::class, [
                'label' => 'kpi_threshold.field.warning_threshold',
                'required' => true,
                'attr' => ['min' => 0, 'max' => 100],
                'constraints' => [new Assert\Range(min: 0, max: 100)],
                'help' => 'kpi_threshold.help.warning_threshold',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => KpiThresholdConfig::class,
            'translation_domain' => 'admin',
        ]);
    }
}
