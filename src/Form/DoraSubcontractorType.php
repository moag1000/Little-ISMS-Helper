<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DoraSubcontractor;
use App\Entity\Supplier;
use App\Service\TenantContext;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DoraSubcontractorType extends AbstractType implements SectionMapInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getSectionMap(): array
    {
        return [
            'overview' => ['name', 'parentSupplier', 'parentSubcontractor', 'tier'],
            'identification' => ['leiCode', 'country', 'serviceDescription'],
            'risk' => ['criticality', 'substitutability'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $builder
            ->add('name', TextType::class, [
                'label' => 'dora_subcontractor.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'dora_subcontractor.placeholder.name',
                ],
            ])
            ->add('parentSupplier', EntityType::class, [
                'label' => 'dora_subcontractor.field.parent_supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'required' => true,
                'placeholder' => 'dora_subcontractor.placeholder.parent_supplier',
                'help' => 'dora_subcontractor.help.parent_supplier',
                'query_builder' => function (EntityRepository $repo) use ($tenant) {
                    $qb = $repo->createQueryBuilder('s')
                        ->orderBy('s.name', 'ASC');
                    if ($tenant !== null) {
                        $qb->where('s.tenant = :tenant')->setParameter('tenant', $tenant);
                    }

                    return $qb;
                },
            ])
            ->add('parentSubcontractor', EntityType::class, [
                'label' => 'dora_subcontractor.field.parent_subcontractor',
                'class' => DoraSubcontractor::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'dora_subcontractor.placeholder.parent_subcontractor',
                'help' => 'dora_subcontractor.help.parent_subcontractor',
                'query_builder' => function (EntityRepository $repo) use ($tenant) {
                    $qb = $repo->createQueryBuilder('s')
                        ->orderBy('s.name', 'ASC');
                    if ($tenant !== null) {
                        $qb->where('s.tenant = :tenant')->setParameter('tenant', $tenant);
                    }

                    return $qb;
                },
            ])
            ->add('tier', IntegerType::class, [
                'label' => 'dora_subcontractor.field.tier',
                'required' => true,
                'attr' => [
                    'min' => 2,
                    'max' => 5,
                ],
                'help' => 'dora_subcontractor.help.tier',
            ])
            // @no-module-gate-required: DoraSubcontractor form is DORA-scoped end-to-end —
            //   controller (DoraSubcontractorController) gates `nis2_dora` on every action,
            //   the entity exists exclusively for DORA RT_04 subcontractor-chain reporting.
            ->add('leiCode', TextType::class, [
                'label' => 'dora_subcontractor.field.lei_code',
                'required' => false,
                'attr' => [
                    'maxlength' => 20,
                    'placeholder' => 'dora_subcontractor.placeholder.lei_code',
                    'pattern' => '[A-Za-z0-9]{20}',
                ],
                'help' => 'dora_subcontractor.help.lei_code',
            ])
            // @legacy-freetext: ISO-3166-1 alpha-2 country code — kept as TextType in line with
            //   SupplierType.countryOfHeadOffice / LocationType.country (both baselined).
            //   Migration to CountryType deferred to a cross-cutting i18n-country sweep.
            ->add('country', TextType::class, [
                'label' => 'dora_subcontractor.field.country',
                'required' => false,
                'attr' => [
                    'maxlength' => 2,
                    'placeholder' => 'dora_subcontractor.placeholder.country',
                    'pattern' => '[A-Za-z]{2}',
                ],
                'help' => 'dora_subcontractor.help.country',
            ])
            ->add('serviceDescription', TextareaType::class, [
                'label' => 'dora_subcontractor.field.service_description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'dora_subcontractor.placeholder.service_description',
                ],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'dora_subcontractor.field.criticality',
                'required' => true,
                'choices' => [
                    'dora_subcontractor.criticality.critical' => 'critical',
                    'dora_subcontractor.criticality.important' => 'important',
                    'dora_subcontractor.criticality.standard' => 'standard',
                ],
                'choice_translation_domain' => 'dora',
                'help' => 'dora_subcontractor.help.criticality',
            ])
            ->add('substitutability', ChoiceType::class, [
                'label' => 'dora_subcontractor.field.substitutability',
                'required' => true,
                'choices' => [
                    'dora_subcontractor.substitutability.high' => 'high',
                    'dora_subcontractor.substitutability.medium' => 'medium',
                    'dora_subcontractor.substitutability.low' => 'low',
                ],
                'choice_translation_domain' => 'dora',
                'help' => 'dora_subcontractor.help.substitutability',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DoraSubcontractor::class,
            'translation_domain' => 'dora',
        ]);
    }
}
