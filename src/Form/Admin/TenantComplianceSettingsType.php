<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\SectionMapInterface;
use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use App\Controller\Admin\SystemSettings\DataRetentionController;
use App\Form\Type\JsonBuilderType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tier-1 Compliance Settings on the Tenant entity (locale, timezone,
 * financial year, TLP, DPO contact). The JSON configuration columns
 * (authorities, retention, notifications, CSIRT endpoints, on-call rota) are
 * edited with the structured JsonBuilderType — shapes per Tenant docblocks.
 */
// SectionMap replaced by fa-tabs in edit.html.twig (JSON-config fields + sidebar preserved).
// Tabs: Defaults/Frameworks/Modules/Contact/Advanced (structured JSON builders).
// Global-settings sidebar is a sibling col-lg-4 alongside the form col-lg-8.
final class TenantComplianceSettingsType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', ChoiceType::class, [
                'label' => 'admin.tenant_settings.locale',
                'choices' => [
                    'Deutsch (Deutschland)' => 'de_DE',
                    'Deutsch (Österreich)' => 'de_AT',
                    'Deutsch (Schweiz)' => 'de_CH',
                    'English (United States)' => 'en_US',
                    'English (United Kingdom)' => 'en_GB',
                    'Français (France)' => 'fr_FR',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'admin.tenant_settings.timezone',
                'choices' => array_combine(
                    \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE),
                    \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE),
                ),
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('financialYearStartMonth', ChoiceType::class, [
                'label' => 'admin.tenant_settings.financial_year_start',
                'choices' => [
                    'Januar' => 1, 'Februar' => 2, 'März' => 3, 'April' => 4,
                    'Mai' => 5, 'Juni' => 6, 'Juli' => 7, 'August' => 8,
                    'September' => 9, 'Oktober' => 10, 'November' => 11, 'Dezember' => 12,
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('tlpDefault', ChoiceType::class, [
                'label' => 'admin.tenant_settings.tlp_default',
                'help' => 'admin.tenant_settings.tlp_default_help',
                'choices' => [
                    'TLP:CLEAR (öffentlich teilbar)' => 'clear',
                    'TLP:GREEN (Community)' => 'green',
                    'TLP:AMBER (Need-to-Know, Empfohlen)' => 'amber',
                    'TLP:AMBER+STRICT' => 'amber+strict',
                    'TLP:RED (nur Empfänger)' => 'red',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('dpoContactName', TextType::class, [
                'label' => 'admin.tenant_settings.dpo_name',
                'help' => 'admin.tenant_settings.dpo_help',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('dpoContactEmail', EmailType::class, [
                'label' => 'admin.tenant_settings.dpo_email',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Email(), new Assert\Length(max: 255)],
            ])
            // GDPR Art. 30(1)(a) — controller postal address (M-5)
            ->add('addressStreet', TextType::class, [
                'label' => 'admin.tenant_settings.address_street',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('addressPostalCode', TextType::class, [
                'label' => 'admin.tenant_settings.address_postal_code',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 20)],
            ])
            ->add('addressCity', TextType::class, [
                'label' => 'admin.tenant_settings.address_city',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 120)],
            ])
            ->add('addressCountry', TextType::class, [
                'label' => 'admin.tenant_settings.address_country',
                'help' => 'admin.tenant_settings.address_country_help',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 2)],
            ])
            // GDPR Art. 27 — representative for non-EU controllers (M-5)
            ->add('representativeName', TextType::class, [
                'label' => 'admin.tenant_settings.representative_name',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('representativeContact', TextType::class, [
                'label' => 'admin.tenant_settings.representative_contact',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            // BDSG § 38 / GDPR Art. 37 — DPO appointment register (N-4)
            ->add('dpoAppointmentDate', DateType::class, [
                'label' => 'admin.tenant_settings.dpo_appointment_date',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'translation_domain' => 'admin',
            ])
            ->add('dpoIsExternal', CheckboxType::class, [
                // Tenant exposes isDpoExternal(), which PropertyAccess cannot
                // derive from "dpoIsExternal" — without an explicit getter the
                // form crashed as soon as the privacy module was active.
                'getter' => static fn (Tenant $tenant): bool => $tenant->isDpoExternal(),
                'label' => 'admin.tenant_settings.dpo_is_external',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('dpoAuthorityNotifiedAt', DateType::class, [
                'label' => 'admin.tenant_settings.dpo_authority_notified_at',
                'help' => 'admin.tenant_settings.dpo_authority_notified_at_help',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'translation_domain' => 'admin',
            ])
            ->add('dpoDeputyName', TextType::class, [
                'label' => 'admin.tenant_settings.dpo_deputy_name',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('riskMethodology', ChoiceType::class, [
                'label' => 'admin.tenant_settings.risk_methodology',
                'help' => 'admin.tenant_settings.risk_methodology_help',
                'choices' => [
                    'ISO/IEC 27005 (Risk-Mgmt nach ISO)' => 'iso_27005',
                    'NIST SP 800-30 (Risk-Assessment-Guide)' => 'nist_800_30',
                    'FAIR (Factor Analysis of Information Risk)' => 'fair',
                    'Custom (eigene Methodik)' => 'custom',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('riskMatrixSize', ChoiceType::class, [
                'label' => 'admin.tenant_settings.risk_matrix_size',
                'help' => 'admin.tenant_settings.risk_matrix_size_help',
                'choices' => [
                    '3 × 3 (KMU-pragmatisch)' => 3,
                    '4 × 4 (mittelstaendisch)' => 4,
                    '5 × 5 (Standard nach ISO 27005)' => 5,
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('wizardMaturityTarget', ChoiceType::class, [
                'label' => 'admin.tenant_settings.wizard_maturity_target',
                'help' => 'admin.tenant_settings.wizard_maturity_target_help',
                'choices' => [
                    'Baseline (KMU-Reife / Pragmatisch)' => 'baseline',
                    'Enhanced (Audit-ready / Continuous-Improvement)' => 'enhanced',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('apiRateLimitPerMinute', IntegerType::class, [
                'label' => 'admin.tenant_settings.api_rate_limit',
                'help' => 'admin.tenant_settings.api_rate_limit_help',
                'required' => false,
                'translation_domain' => 'admin',
                'attr' => ['min' => 0, 'max' => 100000],
            ])
            // @no-module-gate-required: TenantComplianceSettingsType is a per-tenant *configuration*
            //   form — the DORA category itself drives the activation of the nis2_dora module,
            //   so the field must always be visible to admins.
            ->add('doraEntityCategory', ChoiceType::class, [
                'label'              => 'admin.tenant_settings.dora_entity_category',
                'help'               => 'admin.tenant_settings.dora_entity_category_help',
                'choices'            => [
                    'admin.tenant_settings.dora_entity_category.none'                     => \App\Entity\Tenant::DORA_NONE,
                    'admin.tenant_settings.dora_entity_category.financial_entity'         => \App\Entity\Tenant::DORA_FINANCIAL_ENTITY,
                    'admin.tenant_settings.dora_entity_category.critical_ict_third_party' => \App\Entity\Tenant::DORA_CRITICAL_ICT_THIRD_PARTY,
                ],
                'required'           => true,
                'translation_domain' => 'admin',
            ])
        ;

        $tr = 'admin.tenant_settings.json_builder.';

        // Supervisory authorities keyed by jurisdiction/role (BSI, BaFin, LDA …).
        $builder->add('supervisoryAuthorities', JsonBuilderType::class, [
            'label' => 'admin.tenant_settings.supervisory_authorities',
            'help' => 'admin.tenant_settings.supervisory_authorities_help',
            'required' => false,
            'shape' => 'map',
            'key_label' => $tr . 'authority_key',
            'key_suggestions' => ['BSI', 'BaFin', 'LDA', 'CSIRT-Bund', 'ENISA'],
            'add_label' => $tr . 'authority_add',
            'fields' => [
                ['key' => 'name', 'type' => 'text', 'label' => $tr . 'name', 'required' => true],
                ['key' => 'email', 'type' => 'email', 'label' => $tr . 'email'],
                ['key' => 'phone', 'type' => 'text', 'label' => $tr . 'phone'],
                ['key' => 'scope', 'type' => 'text', 'label' => $tr . 'scope'],
            ],
        ]);

        // GDPR retention per data category — same shape as /admin/settings/data-retention.
        $builder->add('dataRetentionPolicies', JsonBuilderType::class, [
            'label' => 'admin.tenant_settings.data_retention_policies',
            'help' => 'admin.tenant_settings.data_retention_policies_help',
            'required' => false,
            'shape' => 'map',
            'key_label' => $tr . 'retention_key',
            'key_suggestions' => array_keys(DataRetentionController::DEFAULTS),
            'add_label' => $tr . 'retention_add',
            'fields' => [
                ['key' => 'retention_days', 'type' => 'number', 'label' => $tr . 'retention_days', 'required' => true],
                ['key' => 'auto_delete', 'type' => 'bool', 'label' => $tr . 'auto_delete'],
            ],
        ]);

        // Delivery channels per event type. `_global` (digest, webhook timeout)
        // belongs to /admin/settings/notifications and is kept untouched.
        $builder->add('notificationPreferences', JsonBuilderType::class, [
            'label' => 'admin.tenant_settings.notification_preferences',
            'help' => 'admin.tenant_settings.notification_preferences_help',
            'required' => false,
            'shape' => 'map',
            'hidden_keys' => ['_global'],
            'key_label' => $tr . 'event_key',
            'key_suggestions' => ['incident', 'breach', 'audit_finding', 'training_overdue'],
            'add_label' => $tr . 'event_add',
            'fields' => [
                ['key' => 'email', 'type' => 'bool', 'label' => $tr . 'channel_email'],
                ['key' => 'slack', 'type' => 'bool', 'label' => $tr . 'channel_slack'],
                ['key' => 'teams', 'type' => 'bool', 'label' => $tr . 'channel_teams'],
            ],
        ]);

        // CSIRT endpoints for automated incident reporting (BSI, sectoral CSIRT, ENISA).
        $builder->add('csirtEndpoints', JsonBuilderType::class, [
            'label' => 'admin.tenant_settings.csirt_endpoints',
            'help' => 'admin.tenant_settings.csirt_endpoints_help',
            'required' => false,
            'shape' => 'map',
            'key_label' => $tr . 'csirt_key',
            'key_suggestions' => ['BSI-CERT', 'CERT-Bund', 'ENISA'],
            'add_label' => $tr . 'csirt_add',
            'fields' => [
                ['key' => 'url', 'type' => 'url', 'label' => $tr . 'url'],
                ['key' => 'contact', 'type' => 'text', 'label' => $tr . 'contact'],
                ['key' => 'auth_type', 'type' => 'text', 'label' => $tr . 'auth_type', 'suggestions' => ['none', 'api_key', 'oauth2', 'mtls']],
            ],
        ]);

        // Crisis-team on-call rotation (date, primary, deputy).
        $builder->add('crisisTeamOnCall', JsonBuilderType::class, [
            'label' => 'admin.tenant_settings.crisis_team_on_call',
            'help' => 'admin.tenant_settings.crisis_team_on_call_help',
            'required' => false,
            'shape' => 'list',
            'add_label' => $tr . 'on_call_add',
            'fields' => [
                ['key' => 'date', 'type' => 'date', 'label' => $tr . 'on_call_date', 'required' => true],
                ['key' => 'primary', 'type' => 'text', 'label' => $tr . 'on_call_primary'],
                ['key' => 'deputy', 'type' => 'text', 'label' => $tr . 'on_call_deputy'],
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
            'translation_domain' => 'admin',
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'defaults'              => ['locale', 'timezone', 'financialYearStartMonth', 'tlpDefault'],
            'address'               => ['addressStreet', 'addressPostalCode', 'addressCity', 'addressCountry'],
            'privacy'               => ['dpoContactName', 'dpoContactEmail', 'dpoAppointmentDate', 'dpoIsExternal', 'dpoAuthorityNotifiedAt', 'dpoDeputyName', 'representativeName', 'representativeContact'],
            'risk_assessment'       => ['riskMethodology', 'riskMatrixSize', 'wizardMaturityTarget'],
            'entity_classification' => ['doraEntityCategory'],
            'advanced'              => ['apiRateLimitPerMinute', 'supervisoryAuthorities', 'dataRetentionPolicies', 'notificationPreferences', 'csirtEndpoints', 'crisisTeamOnCall'],
        ];
    }
}
