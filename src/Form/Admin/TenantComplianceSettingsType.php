<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use App\Form\DataTransformer\JsonArrayTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tier-1 Compliance Settings on the Tenant entity (locale, timezone,
 * financial year, TLP, DPO contact). Supervisory-authorities and
 * retention-policies are JSON blobs and edited in dedicated sub-forms
 * (or as JSON for now).
 */
final class TenantComplianceSettingsType extends AbstractType
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
                'constraints' => [new Assert\Length(['max' => 255])],
            ])
            ->add('dpoContactEmail', EmailType::class, [
                'label' => 'admin.tenant_settings.dpo_email',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Email(), new Assert\Length(['max' => 255])],
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

        // JSON-fields with prettty-print transformer.
        $jsonFields = [
            'supervisoryAuthorities' => 'admin.tenant_settings.supervisory_authorities',
            'dataRetentionPolicies'  => 'admin.tenant_settings.data_retention_policies',
            'notificationPreferences' => 'admin.tenant_settings.notification_preferences',
            'csirtEndpoints'         => 'admin.tenant_settings.csirt_endpoints',
            'crisisTeamOnCall'       => 'admin.tenant_settings.crisis_team_on_call',
        ];
        foreach ($jsonFields as $name => $label) {
            $builder->add($name, TextareaType::class, [
                'label' => $label,
                'help' => $label . '_help',
                'required' => false,
                'translation_domain' => 'admin',
                'attr' => ['rows' => 8, 'class' => 'fa-form-control font-monospace small', 'spellcheck' => 'false'],
            ]);
            $builder->get($name)->addModelTransformer(new JsonArrayTransformer());
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
            'translation_domain' => 'admin',
        ]);
    }
}
