<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\BusinessProcess;
use App\Entity\Risk;
use App\Form\Trait\OwnerPickerFormTrait;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class BusinessProcessType extends AbstractType
{
    use OwnerPickerFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'business_process.field.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'business_process.field.description',
                'attr' => ['rows' => 4],
                'required' => false,
            ])
        ;

        // ── Process Owner cluster (audit-s4 P-1) ────────────────────────────
        // Replaces 4 hand-rolled add() calls (processOwnerUser /
        // processOwnerPerson / processOwnerDeputyPersons / processOwner).
        $this->addOwnerPicker($builder, [
            'user_field'         => 'processOwnerUser',
            'person_field'       => 'processOwnerPerson',
            'deputies_field'     => 'processOwnerDeputyPersons',
            'legacy_field'       => 'processOwner',
            'translation_prefix' => 'business_process',
            'user_label'         => 'business_process.field.process_owner',
            'user_placeholder'   => 'business_process.placeholder.process_owner_user',
            'user_help'          => 'business_process.help.process_owner_user',
            'person_label'       => 'business_process.field.process_owner_person',
            'person_placeholder' => 'business_process.placeholder.process_owner_person',
            'person_help'        => 'business_process.help.process_owner_person',
            'deputies_label'     => 'business_process.field.process_owner_deputies',
            'deputies_help'      => 'business_process.help.process_owner_deputies',
            'legacy_label'       => 'business_process.field.process_owner_legacy',
        ]);

        $builder
            ->add('criticality', ChoiceType::class, [
                'label' => 'business_process.field.criticality',
                'choices' => [
                    'business_process.criticality.critical' => 'critical',
                    'business_process.criticality.high' => 'high',
                    'business_process.criticality.medium' => 'medium',
                    'business_process.criticality.low' => 'low',
                ],
                'choice_translation_domain' => 'business_process',
                'constraints' => [new NotBlank()],
            ])
            ->add('rto', IntegerType::class, [
                'label' => 'business_process.field.rto',
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rto',
                ],
                'help' => 'business_process.help.rto',
                'constraints' => [
                    new NotBlank(),
                    new Range(min: 0, max: 8760),
                ],
            ])
            ->add('rpo', IntegerType::class, [
                'label' => 'business_process.field.rpo',
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rpo',
                ],
                'help' => 'business_process.help.rpo',
                'constraints' => [
                    new NotBlank(),
                    new Range(min: 0, max: 8760),
                ],
            ])
            ->add('mtpd', IntegerType::class, [
                'label' => 'business_process.field.mtpd',
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.mtpd',
                ],
                'help' => 'business_process.help.mtpd',
                'constraints' => [
                    new NotBlank(),
                    new Range(min: 0, max: 8760),
                ],
            ])
            ->add('financialImpactPerHour', MoneyType::class, [
                'label' => 'business_process.field.financial_impact_per_hour',
                'currency' => 'EUR',
                'required' => false,
            ])
            ->add('financialImpactPerDay', MoneyType::class, [
                'label' => 'business_process.field.financial_impact_per_day',
                'currency' => 'EUR',
                'required' => false,
            ])
            ->add('reputationalImpact', ChoiceType::class, [
                'label' => 'business_process.field.reputational_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'business_process',
                'constraints' => [new NotBlank()],
            ])
            ->add('regulatoryImpact', ChoiceType::class, [
                'label' => 'business_process.field.regulatory_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'business_process',
                'constraints' => [new NotBlank()],
            ])
            ->add('operationalImpact', ChoiceType::class, [
                'label' => 'business_process.field.operational_impact',
                'choices' => [
                    'business_process.impact_level.very_low' => 1,
                    'business_process.impact_level.low' => 2,
                    'business_process.impact_level.medium' => 3,
                    'business_process.impact_level.high' => 4,
                    'business_process.impact_level.very_high' => 5,
                ],
                'choice_translation_domain' => 'business_process',
                'constraints' => [new NotBlank()],
            ])
            ->add('dependenciesUpstream', TextareaType::class, [
                'label' => 'business_process.field.dependencies_upstream',
                'attr' => ['rows' => 3],
                'help' => 'business_process.help.dependencies_upstream',
                'required' => false,
            ])
            ->add('dependenciesDownstream', TextareaType::class, [
                'label' => 'business_process.field.dependencies_downstream',
                'attr' => ['rows' => 3],
                'help' => 'business_process.help.dependencies_downstream',
                'required' => false,
            ])
            ->add('recoveryStrategy', TextareaType::class, [
                'label' => 'business_process.field.recovery_strategy',
                'attr' => ['rows' => 4],
                'required' => false,
            ])
            ->add('supportingAssets', EntityType::class, [
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'label' => 'business_process.field.supporting_assets',
                'attr' => ['size' => 5,
                    'data-controller' => 'tom-select',
                ],
                'help' => 'business_process.help.supporting_assets',
            ])
            ->add('identifiedRisks', EntityType::class, [
                'class' => Risk::class,
                'choice_label' => 'title',
                'multiple' => true,
                'required' => false,
                'label' => 'business_process.field.identified_risks',
                'attr' => ['size' => 5,
                    'data-controller' => 'tom-select',
                ],
                'help' => 'business_process.help.identified_risks',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BusinessProcess::class,
            'translation_domain' => 'business_process',
            'constraints' => [
                new Callback([$this, 'validateProcessOwnerSlot']),
            ],
        ]);
    }

    public function validateProcessOwnerSlot(?BusinessProcess $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getProcessOwnerUser() === null && $entity->getProcessOwnerPerson() === null) {
            $context->buildViolation('business_process.error.owner_required_user_or_person')
                ->atPath('processOwnerUser')
                ->addViolation();
        }
    }
}
