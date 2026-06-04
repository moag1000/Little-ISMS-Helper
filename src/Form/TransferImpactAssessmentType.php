<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TransferImpactAssessment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * TIA form — GDPR Art. 46/49 Transfer Impact Assessment.
 *
 * SectionPolicy (P-2): implements SectionMapInterface because the form has > 6 fields
 * and several fields are regulatorily critical (Art. 46 / 49 compliance).
 *
 * Sections:
 *   overview   — destination + recipient + transfer mechanism
 *   assessment — surveillance law risk + supplementary measures
 *   conclusion — residual risk + conclusion text + status
 */
final class TransferImpactAssessmentType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── Section: overview ────────────────────────────────────────────
            ->add('destinationCountry', TextType::class, [
                'label'       => 'tia.field.destination_country',
                'help'        => 'tia.help.destination_country',
                'required'    => true,
                'attr'        => ['placeholder' => 'US', 'maxlength' => 10],
            ])
            ->add('recipientName', TextType::class, [
                'label'       => 'tia.field.recipient_name',
                'help'        => 'tia.help.recipient_name',
                'required'    => true,
                'attr'        => ['placeholder' => 'e.g. AWS Inc.'],
            ])
            ->add('transferMechanism', ChoiceType::class, [
                'label'    => 'tia.field.transfer_mechanism',
                'help'     => 'tia.help.transfer_mechanism',
                'required' => true,
                'choices'  => [
                    'tia.mechanism.scc'              => 'scc',
                    'tia.mechanism.bcr'              => 'bcr',
                    'tia.mechanism.adequacy'         => 'adequacy',
                    'tia.mechanism.certification'    => 'certification',
                    'tia.mechanism.codes_of_conduct' => 'codes_of_conduct',
                    'tia.mechanism.derogation'       => 'derogation',
                ],
                'choice_translation_domain' => 'tia',
                'placeholder' => 'tia.placeholder.transfer_mechanism',
            ])

            // ── Section: assessment ──────────────────────────────────────────
            ->add('lawSurveillanceRisk', TextareaType::class, [
                'label'    => 'tia.field.law_surveillance_risk',
                'help'     => 'tia.help.law_surveillance_risk',
                'required' => true,
                'attr'     => ['rows' => 6],
            ])
            ->add('supplementaryMeasures', TextareaType::class, [
                'label'    => 'tia.field.supplementary_measures',
                'help'     => 'tia.help.supplementary_measures',
                'required' => false,
                'attr'     => ['rows' => 5],
            ])

            // ── Section: conclusion ──────────────────────────────────────────
            ->add('residualRiskRating', ChoiceType::class, [
                'label'    => 'tia.field.residual_risk_rating',
                'help'     => 'tia.help.residual_risk_rating',
                'required' => true,
                'choices'  => [
                    'tia.risk.low'    => 'low',
                    'tia.risk.medium' => 'medium',
                    'tia.risk.high'   => 'high',
                ],
                'choice_translation_domain' => 'tia',
                'placeholder' => 'tia.placeholder.residual_risk_rating',
            ])
            ->add('conclusion', TextareaType::class, [
                'label'    => 'tia.field.conclusion',
                'help'     => 'tia.help.conclusion',
                'required' => false,
                'attr'     => ['rows' => 4],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => TransferImpactAssessment::class,
            'translation_domain' => 'tia',
        ]);
    }

    /**
     * SectionMapInterface — P-2 SectionPolicy.
     *
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'overview'   => ['destinationCountry', 'recipientName', 'transferMechanism'],
            'assessment' => ['lawSurveillanceRisk', 'supplementaryMeasures'],
            'conclusion' => ['residualRiskRating', 'conclusion'],
        ];
    }
}
