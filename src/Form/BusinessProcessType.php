<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\BusinessProcess;
use App\Entity\Risk;
use App\Form\Trait\OwnerPickerFormTrait;
use App\Repository\BusinessProcessRepository;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use App\Form\SectionMapInterface;
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

/**
 * Junior-ISB-Audit-2026-05-22 S14 #17: BP-Tooltips Drawer Pattern — UX-Polish.
 *
 * The `help.<field>` translation keys hold the 30-sec summaries displayed
 * inline. The verbose ISO-22301 reference (RPO/RTO/MTPD definitions, BIA
 * impact tables for criticality + reputational impact) lives in
 * `business_process.tooltip.<field>` and is opened on demand via the Aurora
 * `fa-drawer` side-sheet. Wiring lives in `templates/business_process/new.html.twig`
 * + `edit.html.twig` (`drawer_keys` map on the `_auto_form` include).
 */
final class BusinessProcessType extends AbstractType implements SectionMapInterface
{
    use OwnerPickerFormTrait;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    // Junior-ISB-Audit-2026-05-22 4.11: Owner pre-fill — UX-Polish
    protected function getSecurityForOwnerPicker(): ?Security
    {
        return $this->security;
    }

    public static function getSectionMap(): array
    {
        return [
            'overview'        => ['name', 'description', 'criticality'],
            'owner'           => ['processOwnerUser', 'processOwnerPerson', 'processOwnerDeputyPersons', 'processOwner'],
            'criticality'     => ['reputationalImpact', 'regulatoryImpact', 'operationalImpact', 'financialImpactPerHour', 'financialImpactPerDay'],
            'recovery_targets'=> ['rto', 'rpo', 'mtpd'],
            'dependencies'    => ['upstreamProcesses', 'downstreamProcesses', 'dependenciesUpstream', 'dependenciesDownstream', 'recoveryStrategy'],
            'resources'       => ['supportingAssets', 'identifiedRisks'],
        ];
    }

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
            // Junior-ISB-Audit-2026-05-22 4.11: Owner pre-fill — UX-Polish
            'default_to_current_user' => true,
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
            // BIA-draft survival (UX-P0 #4.2): rto/rpo/mtpd are required for
            // ISO 22301 Cl. 8.2.2 certification BUT must not block save-as-draft.
            // Constraint dropped at form-level — finalization-time gate happens
            // via the BIA-completeness lifecycle transition (see workflow).
            ->add('rto', IntegerType::class, [
                'label' => 'business_process.field.rto',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rto',
                ],
                'help' => 'business_process.help.rto',
                'constraints' => [
                    new Range(min: 0, max: 8760),
                ],
            ])
            ->add('rpo', IntegerType::class, [
                'label' => 'business_process.field.rpo',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.rpo',
                ],
                'help' => 'business_process.help.rpo',
                'constraints' => [
                    new Range(min: 0, max: 8760),
                ],
            ])
            ->add('mtpd', IntegerType::class, [
                'label' => 'business_process.field.mtpd',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'business_process.placeholder.mtpd',
                ],
                'help' => 'business_process.help.mtpd',
                'constraints' => [
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
            // T4.2 Save-as-Draft: BIA-impact fields are no longer NotBlank.
            // Juniors can save a process incrementally without all impact ratings.
            // Completeness is surfaced via the criticality-alignment validator
            // + show-page progress indicator.
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
                'required' => false,
                'placeholder' => 'business_process.placeholder.impact_unset',
                'help' => 'business_process.help.bia_optional',
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
                'required' => false,
                'placeholder' => 'business_process.placeholder.impact_unset',
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
                'required' => false,
                'placeholder' => 'business_process.placeholder.impact_unset',
            ])
            // Junior-ISB-Audit TODO_2026-05-22 §17 — Typed M2M dependencies.
            // Structured Multi-Select replaces free-text textareas. Both
            // rendered side-by-side during transition; textareas flagged
            // @deprecated and will be dropped after data backfill.
            ->add('upstreamProcesses', EntityType::class, [
                'label' => 'business_process.field.upstream_processes',
                'class' => BusinessProcess::class,
                'multiple' => true,
                'required' => false,
                'choice_label' => fn(BusinessProcess $p) => $p->getName(),
                'help' => 'business_process.help.upstream_processes',
                'attr' => ['data-controller' => 'tom-select'],
                'query_builder' => function (BusinessProcessRepository $r) use ($options) {
                    $qb = $r->createQueryBuilder('p');
                    $selfId = isset($options['data']) && $options['data']?->getId() !== null
                        ? $options['data']->getId()
                        : null;
                    if ($selfId !== null) {
                        $qb->andWhere('p.id != :selfId')->setParameter('selfId', $selfId);
                    }
                    $tenant = $this->tenantContext->getCurrentTenant();
                    if ($tenant !== null) {
                        $qb->andWhere('p.tenant = :tenant')->setParameter('tenant', $tenant);
                    }
                    return $qb->orderBy('p.name', 'ASC');
                },
            ])
            ->add('downstreamProcesses', EntityType::class, [
                'label' => 'business_process.field.downstream_processes',
                'class' => BusinessProcess::class,
                'multiple' => true,
                'required' => false,
                'choice_label' => fn(BusinessProcess $p) => $p->getName(),
                'help' => 'business_process.help.downstream_processes',
                'attr' => ['data-controller' => 'tom-select'],
                'query_builder' => function (BusinessProcessRepository $r) use ($options) {
                    $qb = $r->createQueryBuilder('p');
                    $selfId = isset($options['data']) && $options['data']?->getId() !== null
                        ? $options['data']->getId()
                        : null;
                    if ($selfId !== null) {
                        $qb->andWhere('p.id != :selfId')->setParameter('selfId', $selfId);
                    }
                    $tenant = $this->tenantContext->getCurrentTenant();
                    if ($tenant !== null) {
                        $qb->andWhere('p.tenant = :tenant')->setParameter('tenant', $tenant);
                    }
                    return $qb->orderBy('p.name', 'ASC');
                },
            ])
            // @legacy-freetext: kept during transition while upstream/downstream
            // free-text data is backfilled into the typed M2M collection above.
            ->add('dependenciesUpstream', TextareaType::class, [
                'label' => 'business_process.field.dependencies_upstream',
                'attr' => ['rows' => 3],
                'help' => 'business_process.help.dependencies_upstream',
                'required' => false,
            ])
            // @legacy-freetext: kept during transition (see upstream above)
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
                new Callback([$this, 'validateRecoveryChain']),
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

    /**
     * S11 §1 (M-01) — ISO 22301 Cl. 8.2.2 / 8.3.2 recovery chain monotonicity.
     *
     * The three BCM windows must satisfy:  RPO ≤ RTO ≤ MTPD
     *   RPO  — maximum acceptable data loss (since last good backup)
     *   RTO  — maximum acceptable downtime (until service is back)
     *   MTPD — maximum tolerable period of disruption (until org failure)
     *
     * Any window may be NULL (not yet measured) — only enforce when both
     * sides of a comparison are set.
     */
    public function validateRecoveryChain(?BusinessProcess $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }

        $rpo  = $entity->getRpo();
        $rto  = $entity->getRto();
        $mtpd = $entity->getMtpd();

        if ($rpo !== null && $rto !== null && $rpo > $rto) {
            $context->buildViolation('business_process.error.recovery_chain_rpo_gt_rto')
                ->atPath('rpo')
                ->addViolation();
        }

        if ($rto !== null && $mtpd !== null && $rto > $mtpd) {
            $context->buildViolation('business_process.error.recovery_chain_rto_gt_mtpd')
                ->atPath('rto')
                ->addViolation();
        }
    }
}
