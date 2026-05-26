<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DoraDataFlow;
use App\Entity\Supplier;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DoraDataFlowType — FormType for {@see DoraDataFlow} (DORA RoI RT_03).
 *
 * Module-gated on `nis2_dora`. Controller drops to a 403/redirect before
 * this form is rendered; this gate is a belt-and-braces additional check
 * that omits the entire body if the module is somehow inactive (e.g. when
 * the form is rendered programmatically outside the standard controller).
 *
 * SectionPolicy (S4 Foundation P-2): explicit section-map across 4
 * sections; covers all 7 editable fields.
 */
final class DoraDataFlowType extends AbstractType implements SectionMapInterface
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getSectionMap(): array
    {
        return [
            'overview'        => ['supplier', 'direction', 'processingPurpose'],
            'data_categories' => ['dataCategories', 'dataVolume'],
            'security'        => ['securityMeasures'],
            'transfer'        => ['crossBorder', 'receivingCountry'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->isModuleActive('nis2_dora')) {
            // Module-gate fallback — render nothing if the controller bypass
            // was missed. Keeps the form harmless when called out-of-band.
            return;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        $builder
            ->add('supplier', EntityType::class, [
                'label' => 'dora_data_flow.field.supplier',
                'help' => 'dora_data_flow.help.supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'required' => true,
                'placeholder' => 'dora_data_flow.placeholder.supplier',
                'query_builder' => function ($repo) use ($tenant) {
                    $qb = $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC');
                    if ($tenant !== null) {
                        $qb->andWhere('s.tenant = :tenant')->setParameter('tenant', $tenant);
                    }

                    return $qb;
                },
            ])
            ->add('direction', ChoiceType::class, [
                'label' => 'dora_data_flow.field.direction',
                'help' => 'dora_data_flow.help.direction',
                'required' => true,
                'choices' => [
                    'dora_data_flow.direction.inbound' => DoraDataFlow::DIRECTION_INBOUND,
                    'dora_data_flow.direction.outbound' => DoraDataFlow::DIRECTION_OUTBOUND,
                    'dora_data_flow.direction.bidirectional' => DoraDataFlow::DIRECTION_BIDIRECTIONAL,
                ],
                'choice_translation_domain' => 'dora_data_flow',
            ])
            ->add('processingPurpose', TextareaType::class, [
                'label' => 'dora_data_flow.field.processing_purpose',
                'help' => 'dora_data_flow.help.processing_purpose',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'maxlength' => 500,
                    'placeholder' => 'dora_data_flow.placeholder.processing_purpose',
                ],
            ])
            ->add('dataCategories', ChoiceType::class, [
                'label' => 'dora_data_flow.field.data_categories',
                'help' => 'dora_data_flow.help.data_categories',
                'required' => true,
                'multiple' => true,
                'expanded' => false,
                'choices' => $this->commonDataCategoryChoices(),
                'choice_translation_domain' => 'dora_data_flow',
                'attr' => [
                    'data-controller' => 'tom-select',
                    'data-tom-select-create-value' => 'true',
                ],
                'constraints' => [
                    new Assert\Count(min: 1, minMessage: 'dora_data_flow.validation.data_categories_required'),
                ],
            ])
            ->add('dataVolume', TextType::class, [
                'label' => 'dora_data_flow.field.data_volume',
                'help' => 'dora_data_flow.help.data_volume',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'dora_data_flow.placeholder.data_volume',
                ],
            ])
            ->add('securityMeasures', ChoiceType::class, [
                'label' => 'dora_data_flow.field.security_measures',
                'help' => 'dora_data_flow.help.security_measures',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => $this->commonSecurityMeasureChoices(),
                'choice_translation_domain' => 'dora_data_flow',
                'attr' => [
                    'data-controller' => 'tom-select',
                    'data-tom-select-create-value' => 'true',
                ],
            ])
            ->add('crossBorder', CheckboxType::class, [
                'label' => 'dora_data_flow.field.cross_border',
                'help' => 'dora_data_flow.help.cross_border',
                'required' => false,
            ])
            ->add('receivingCountry', TextType::class, [
                'label' => 'dora_data_flow.field.receiving_country',
                'help' => 'dora_data_flow.help.receiving_country',
                'required' => false,
                'attr' => [
                    'maxlength' => 2,
                    'minlength' => 2,
                    'placeholder' => 'DE',
                    'pattern' => '[A-Za-z]{2}',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
        ;

        // Allow the ChoiceType to accept user-typed values (when the
        // tom-select adapter is unavailable JS-side, the form still has to
        // accept the legacy free-form value). Re-normalise into the entity
        // setter on SUBMIT.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            foreach (['dataCategories', 'securityMeasures'] as $jsonField) {
                if (isset($data[$jsonField]) && !is_array($data[$jsonField])) {
                    // Accept comma- or newline-separated free-form fallback.
                    $raw = (string) $data[$jsonField];
                    $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
                    $data[$jsonField] = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
                }
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DoraDataFlow::class,
            'translation_domain' => 'dora_data_flow',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function commonDataCategoryChoices(): array
    {
        // Curated ESA-aligned starter set; users can extend free-form via
        // the tom-select create-value adapter.
        return [
            'dora_data_flow.category.pii' => 'PII',
            'dora_data_flow.category.financial' => 'financial',
            'dora_data_flow.category.health' => 'health',
            'dora_data_flow.category.authentication' => 'authentication',
            'dora_data_flow.category.transactional' => 'transactional',
            'dora_data_flow.category.metadata' => 'metadata',
            'dora_data_flow.category.logs' => 'logs',
            'dora_data_flow.category.backup' => 'backup',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function commonSecurityMeasureChoices(): array
    {
        return [
            'dora_data_flow.security.encryption_in_transit' => 'encryption_in_transit',
            'dora_data_flow.security.encryption_at_rest' => 'encryption_at_rest',
            'dora_data_flow.security.tokenisation' => 'tokenisation',
            'dora_data_flow.security.pseudonymisation' => 'pseudonymisation',
            'dora_data_flow.security.anonymisation' => 'anonymisation',
            'dora_data_flow.security.mfa' => 'mfa',
            'dora_data_flow.security.vpn' => 'vpn',
            'dora_data_flow.security.mtls' => 'mtls',
            'dora_data_flow.security.access_control' => 'access_control',
        ];
    }
}
