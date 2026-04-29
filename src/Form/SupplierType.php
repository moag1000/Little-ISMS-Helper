<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Supplier;
use App\Entity\SupplierCriticalityLevel;
use App\Entity\Tenant;
use App\Repository\SupplierCriticalityLevelRepository;
use App\Service\TenantContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SupplierType extends AbstractType
{
    public function __construct(
        private readonly SupplierCriticalityLevelRepository $criticalityRepo,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Build dynamic criticality choices from tenant-specific levels.
        $tenant = $this->tenantContext->getCurrentTenant();
        $criticalityChoices = $this->buildCriticalityChoices($tenant);
        $builder
            ->add('name', TextType::class, [
                'label' => 'supplier.field.name',
                'help' => 'supplier.help.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'supplier.placeholder.name',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'supplier.field.description',
                'help' => 'supplier.help.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.description',
                ],
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'supplier.field.contact_person',
                'help' => 'supplier.help.contact_person',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'supplier.placeholder.contact_person',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'supplier.field.email',
                'help' => 'supplier.help.email',
                'required' => false,
                'attr' => [
                    'placeholder' => 'supplier.placeholder.email',
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'supplier.field.phone',
                'help' => 'supplier.help.phone',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => 'supplier.placeholder.phone',
                ],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'supplier.field.address',
                'help' => 'supplier.help.address',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'supplier.placeholder.address',
                ],
            ])
            ->add('serviceProvided', TextareaType::class, [
                'label' => 'supplier.field.service_provided',
                'help' => 'supplier.help.service_provided',
                'required' => true,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.service_provided',
                ],
            ])
            ->add('criticality', ChoiceType::class, [
                'label' => 'supplier.field.criticality',
                'help' => 'supplier.help.criticality',
                'choices' => $criticalityChoices,
                'choice_translation_domain' => false, // Labels already translated via getLabel()
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'supplier.field.status',
                'help' => 'supplier.help.status',
                'choices' => [
                    'supplier.status.active' => 'active',
                    'supplier.status.inactive' => 'inactive',
                    'supplier.status.evaluation' => 'evaluation',
                    'supplier.status.terminated' => 'terminated',
                ],
                'choice_translation_domain' => 'suppliers',
                'required' => true,
            ])
            ->add('securityScore', IntegerType::class, [
                'label' => 'supplier.field.security_score',
                'help' => 'supplier.help.security_score',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'placeholder' => 'supplier.placeholder.security_score',
                ],
            ])
            ->add('lastSecurityAssessment', DateType::class, [
                'label' => 'supplier.field.last_security_assessment',
                'help' => 'supplier.help.last_assessment',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextAssessmentDate', DateType::class, [
                'label' => 'supplier.field.next_assessment_date',
                'help' => 'supplier.help.next_assessment',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('assessmentFindings', TextareaType::class, [
                'label' => 'supplier.field.assessment_findings',
                'help' => 'supplier.help.assessment_findings',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'supplier.placeholder.assessment_findings',
                ],
            ])
            ->add('nonConformities', TextareaType::class, [
                'label' => 'supplier.field.non_conformities',
                'help' => 'supplier.help.non_conformities',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'supplier.placeholder.non_conformities',
                ],
            ])
            ->add('contractStartDate', DateType::class, [
                'label' => 'supplier.field.contract_start_date',
                'help' => 'supplier.help.contract_start',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('contractEndDate', DateType::class, [
                'label' => 'supplier.field.contract_end_date',
                'help' => 'supplier.help.contract_end',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('securityRequirements', TextareaType::class, [
                'label' => 'supplier.field.security_requirements',
                'help' => 'supplier.help.security_requirements',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'supplier.placeholder.security_requirements',
                ],
            ])
            ->add('hasISO27001', CheckboxType::class, [
                'label' => 'supplier.field.has_iso27001',
                'help' => 'supplier.help.has_iso27001',
                'required' => false,
            ])
            ->add('hasISO22301', CheckboxType::class, [
                'label' => 'supplier.field.has_iso22301',
                'help' => 'supplier.help.has_iso22301',
                'required' => false,
            ])
            ->add('certifications', TextareaType::class, [
                'label' => 'supplier.field.certifications',
                'help' => 'supplier.help.certifications',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'supplier.placeholder.certifications',
                ],
            ])
            ->add('hasDPA', CheckboxType::class, [
                'label' => 'supplier.field.has_dpa',
                'help' => 'supplier.help.has_dpa',
                'required' => false,
            ])
            ->add('dpaSignedDate', DateType::class, [
                'label' => 'supplier.field.dpa_signed_date',
                'help' => 'supplier.help.dpa_signed',
                'widget' => 'single_text',
                'required' => false,
            ])

            // ── WS-3: DORA ROI (Register of Information) ─────────────────────
            ->add('leiCode', TextType::class, [
                'label' => 'supplier.field.lei_code',
                'help' => 'supplier.help.lei_code',
                'required' => false,
                'attr' => ['maxlength' => 20, 'placeholder' => 'LEI (20 chars)'],
            ])
            ->add('naceCode', TextType::class, [
                'label' => 'supplier.field.nace_code',
                'help' => 'supplier.help.nace_code',
                'required' => false,
                'attr' => ['maxlength' => 10, 'placeholder' => 'z.B. 62.01'],
            ])
            ->add('countryOfHeadOffice', TextType::class, [
                'label' => 'supplier.field.country_of_head_office',
                'help' => 'supplier.help.country_of_head_office',
                'required' => false,
                'attr' => ['maxlength' => 2, 'placeholder' => 'DE'],
            ])
            ->add('ictCriticality', ChoiceType::class, [
                'label' => 'supplier.field.ict_criticality',
                'help' => 'supplier.help.ict_criticality',
                'required' => false,
                'placeholder' => 'supplier.value.na',
                'choices' => [
                    'supplier.ict_criticality.non_ict' => 'non_ict',
                    'supplier.ict_criticality.important' => 'important',
                    'supplier.ict_criticality.critical' => 'critical',
                ],
                'choice_translation_domain' => 'suppliers',
            ])
            ->add('ictFunctionType', TextType::class, [
                'label' => 'supplier.field.ict_function_type',
                'help' => 'supplier.help.ict_function_type',
                'required' => false,
                'attr' => ['maxlength' => 100, 'placeholder' => 'Cloud / SaaS / Managed Service'],
            ])
            ->add('substitutability', ChoiceType::class, [
                'label' => 'supplier.field.substitutability',
                'help' => 'supplier.help.substitutability',
                'required' => false,
                'placeholder' => 'supplier.value.na',
                'choices' => [
                    'supplier.substitutability.easy' => 'easy',
                    'supplier.substitutability.medium' => 'medium',
                    'supplier.substitutability.hard' => 'hard',
                ],
                'choice_translation_domain' => 'suppliers',
            ])
            ->add('hasSubcontractors', CheckboxType::class, [
                'label' => 'supplier.field.has_subcontractors',
                'help' => 'supplier.help.has_subcontractors',
                'required' => false,
            ])
            ->add('subcontractorChain', TextareaType::class, [
                'label' => 'supplier.field.subcontractor_chain',
                'help' => 'supplier.help.subcontractor_chain',
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Provider A' . "\n" . 'Provider B' . "\n" . 'Provider C'],
            ])
            ->add('processingLocations', TextareaType::class, [
                'label' => 'supplier.field.processing_locations',
                'help' => 'supplier.help.processing_locations',
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'DE, IE, US'],
            ])
            ->add('lastDoraAuditDate', DateType::class, [
                'label' => 'supplier.field.last_dora_audit_date',
                'help' => 'supplier.help.last_dora_audit_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('hasExitStrategy', CheckboxType::class, [
                'label' => 'supplier.field.has_exit_strategy',
                'help' => 'supplier.help.has_exit_strategy',
                'required' => false,
            ])

            // ── WS-3: DSGVO Art. 28 processor fields ─────────────────────────
            ->add('gdprProcessorStatus', ChoiceType::class, [
                'label' => 'supplier.field.gdpr_processor_status',
                'help' => 'supplier.help.gdpr_processor_status',
                'required' => false,
                'placeholder' => 'supplier.value.na',
                'choices' => [
                    'supplier.gdpr_processor_status.controller' => 'controller',
                    'supplier.gdpr_processor_status.processor' => 'processor',
                    'supplier.gdpr_processor_status.joint_controller' => 'joint_controller',
                    'supplier.gdpr_processor_status.none' => 'none',
                ],
                'choice_translation_domain' => 'suppliers',
            ])
            ->add('gdprTransferMechanism', TextType::class, [
                'label' => 'supplier.field.gdpr_transfer_mechanism',
                'help' => 'supplier.help.gdpr_transfer_mechanism',
                'required' => false,
                'attr' => ['maxlength' => 50, 'placeholder' => 'SCC / Adequacy Decision / BCR'],
            ])
            ->add('gdprAvContractSigned', CheckboxType::class, [
                'label' => 'supplier.field.gdpr_av_contract_signed',
                'help' => 'supplier.help.gdpr_av_contract_signed',
                'required' => false,
            ])
            ->add('gdprAvContractDate', DateType::class, [
                'label' => 'supplier.field.gdpr_av_contract_date',
                'help' => 'supplier.help.gdpr_av_contract_date',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;

        // Sync unmapped textareas back to JSON entity properties
        $builder->addEventListener(
            \Symfony\Component\Form\FormEvents::POST_SUBMIT,
            static function (\Symfony\Component\Form\Event\PostSubmitEvent $event): void {
                $form = $event->getForm();
                $supplier = $form->getData();
                if (!$supplier instanceof Supplier) {
                    return;
                }
                if ($form->has('subcontractorChain')) {
                    $raw = trim((string) ($form->get('subcontractorChain')->getData() ?? ''));
                    $list = [];
                    if (str_starts_with($raw, '[')) {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $entry) {
                                if (is_string($entry) && trim($entry) !== '') {
                                    $list[] = trim($entry);
                                    continue;
                                }
                                if (is_array($entry)) {
                                    $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
                                    if ($name === '') {
                                        continue;
                                    }
                                    $tier = isset($entry['tier']) ? max(1, min(5, (int) $entry['tier'])) : 1;
                                    $list[] = [
                                        'tier' => $tier,
                                        'name' => $name,
                                        'lei' => isset($entry['lei']) ? trim((string) $entry['lei']) : '',
                                        'country' => isset($entry['country']) ? strtoupper(substr(trim((string) $entry['country']), 0, 2)) : '',
                                        'service' => isset($entry['service']) ? trim((string) $entry['service']) : '',
                                        'criticality' => isset($entry['criticality']) && in_array($entry['criticality'], ['low', 'medium', 'high', 'critical'], true)
                                            ? $entry['criticality']
                                            : '',
                                    ];
                                }
                            }
                        }
                    } elseif ($raw !== '') {
                        $list = array_values(array_filter(
                            array_map('trim', preg_split('/\r?\n/', $raw) ?: []),
                            static fn(string $v): bool => $v !== '',
                        ));
                    }
                    $supplier->setSubcontractorChain($list === [] ? null : $list);
                }
                if ($form->has('processingLocations')) {
                    $raw = (string) ($form->get('processingLocations')->getData() ?? '');
                    $list = array_values(array_filter(
                        array_map('trim', preg_split('/[,;\r\n]+/', $raw) ?: []),
                        static fn(string $v): bool => $v !== '',
                    ));
                    $supplier->setProcessingLocations($list === [] ? null : $list);
                }
            },
        );
    }

    /**
     * Hydrate the unmapped textarea fields (subcontractorChain,
     * processingLocations) from entity arrays. Must run in finishView() —
     * children FormViews are only populated after buildView() returns, so
     * `$view['subcontractorChain']` would not yet exist.
     */
    public function finishView(
        \Symfony\Component\Form\FormView $view,
        \Symfony\Component\Form\FormInterface $form,
        array $options,
    ): void {
        $supplier = $form->getData();
        if (!$supplier instanceof Supplier) {
            return;
        }
        if (isset($view['subcontractorChain']) && !$form->get('subcontractorChain')->getViewData()) {
            $chain = $supplier->getSubcontractorChain();
            if (is_array($chain) && $chain !== []) {
                // JSON-encode so the Stimulus editor picks up structured rows.
                // Legacy strings are preserved verbatim in the encoded array.
                $view['subcontractorChain']->vars['value'] = json_encode($chain, JSON_UNESCAPED_UNICODE);
            }
        }
        if (isset($view['processingLocations']) && !$form->get('processingLocations')->getViewData()) {
            $locs = $supplier->getProcessingLocations();
            if (is_array($locs) && $locs !== []) {
                // Defensive: filter out non-scalars (legacy data may contain nested
                // structures); only strings/scalars survive into the textarea value.
                $flat = array_values(array_filter($locs, 'is_scalar'));
                $view['processingLocations']->vars['value'] = implode(', ', array_map('strval', $flat));
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Supplier::class,
            'translation_domain' => 'suppliers',
        ]);
    }

    /**
     * Build criticality choices from tenant-specific levels.
     * Falls back to hardcoded 4-level defaults when no levels are configured
     * (ensures the form always works even on fresh/legacy tenants).
     *
     * @return array<string, string>
     */
    private function buildCriticalityChoices(?Tenant $tenant): array
    {
        if ($tenant instanceof Tenant) {
            $levels = $this->criticalityRepo->findActiveByTenant($tenant);
            if (!empty($levels)) {
                $choices = [];
                foreach ($levels as $level) {
                    $choices[$level->getLabelDe()] = $level->getCode();
                }
                return $choices;
            }
        }

        // Fallback to canonical 4-level defaults (matches seeded records).
        return [
            'Kritisch' => 'critical',
            'Hoch' => 'high',
            'Mittel' => 'medium',
            'Gering' => 'low',
        ];
    }
}
