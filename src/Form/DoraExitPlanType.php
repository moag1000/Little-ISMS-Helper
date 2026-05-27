<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Entity\DoraExitPlan;
use App\Entity\Supplier;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * DORA Art. 28 RT_06 Exit-Plan form. Covers supplier link, trigger, data-return,
 * deletion confirmation + certificate, migration path, rehearsal date,
 * estimated duration + cost.
 */
final class DoraExitPlanType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier', EntityType::class, [
                'label' => 'dora_exit_plan.field.supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'placeholder' => 'dora_exit_plan.placeholder.supplier',
                'required' => true,
                'help' => 'dora_exit_plan.help.supplier',
                'query_builder' => function ($repo) {
                    // Only critical / DORA-relevant suppliers are eligible for
                    // an exit plan. Two filters: isDoraRelevant flag OR
                    // ictCriticality=critical, OR generic criticality=critical.
                    return $repo->createQueryBuilder('s')
                        ->where('s.isDoraRelevant = :dora')
                        ->orWhere('s.ictCriticality = :critIct')
                        ->orWhere('s.criticality = :critGen')
                        ->setParameter('dora', true)
                        ->setParameter('critIct', 'critical')
                        ->setParameter('critGen', 'critical')
                        ->orderBy('s.name', 'ASC');
                },
            ])
            ->add('exitTrigger', ChoiceType::class, [
                'label' => 'dora_exit_plan.field.exit_trigger',
                'choices' => [
                    'dora_exit_plan.trigger.planned-renewal'    => DoraExitPlan::TRIGGER_PLANNED_RENEWAL,
                    'dora_exit_plan.trigger.concentration-risk' => DoraExitPlan::TRIGGER_CONCENTRATION_RISK,
                    'dora_exit_plan.trigger.force-majeure'      => DoraExitPlan::TRIGGER_FORCE_MAJEURE,
                    'dora_exit_plan.trigger.breach'             => DoraExitPlan::TRIGGER_BREACH,
                    'dora_exit_plan.trigger.insolvency'         => DoraExitPlan::TRIGGER_INSOLVENCY,
                ],
                'required' => true,
                'help' => 'dora_exit_plan.help.exit_trigger',
                'choice_translation_domain' => 'dora_exit_plan',
            ])
            ->add('dataReturnFormat', TextareaType::class, [
                'label' => 'dora_exit_plan.field.data_return_format',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'maxlength' => 500,
                    'placeholder' => 'dora_exit_plan.placeholder.data_return_format',
                ],
                'help' => 'dora_exit_plan.help.data_return_format',
            ])
            ->add('dataDeletionConfirmation', CheckboxType::class, [
                'label' => 'dora_exit_plan.field.data_deletion_confirmation',
                'required' => false,
                'help' => 'dora_exit_plan.help.data_deletion_confirmation',
            ])
            ->add('deletionCertificateDoc', EntityType::class, [
                'label' => 'dora_exit_plan.field.deletion_certificate_doc',
                'class' => Document::class,
                'choice_label' => 'originalFilename',
                'placeholder' => 'dora_exit_plan.placeholder.deletion_certificate_doc',
                'required' => false,
                'help' => 'dora_exit_plan.help.deletion_certificate_doc',
            ])
            ->add('migrationPath', TextareaType::class, [
                'label' => 'dora_exit_plan.field.migration_path',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'maxlength' => 1000,
                    'placeholder' => 'dora_exit_plan.placeholder.migration_path',
                ],
                'help' => 'dora_exit_plan.help.migration_path',
            ])
            ->add('testedAt', DateTimeType::class, [
                'label' => 'dora_exit_plan.field.tested_at',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'dora_exit_plan.help.tested_at',
            ])
            ->add('estimatedDurationDays', IntegerType::class, [
                'label' => 'dora_exit_plan.field.estimated_duration_days',
                'required' => false,
                'attr' => ['min' => 0, 'placeholder' => 'dora_exit_plan.placeholder.estimated_duration_days'],
                'help' => 'dora_exit_plan.help.estimated_duration_days',
            ])
            ->add('estimatedCost', MoneyType::class, [
                'label' => 'dora_exit_plan.field.estimated_cost',
                'required' => false,
                'currency' => 'EUR',
                'scale' => 2,
                'attr' => ['placeholder' => 'dora_exit_plan.placeholder.estimated_cost'],
                'help' => 'dora_exit_plan.help.estimated_cost',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DoraExitPlan::class,
            'translation_domain' => 'dora_exit_plan',
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'overview' => [
                'supplier',
                'exitTrigger',
            ],
            'recovery' => [
                'dataReturnFormat',
                'dataDeletionConfirmation',
                'deletionCertificateDoc',
                'migrationPath',
            ],
            'testing' => [
                'testedAt',
            ],
            'resources' => [
                'estimatedDurationDays',
                'estimatedCost',
            ],
        ];
    }
}
