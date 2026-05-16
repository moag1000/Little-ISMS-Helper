<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\ProcessingActivity;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * GDPR Art. 30 standard VVT starter set.
 *
 * Foundation P-14. Five everyday processing activities every German SMB has:
 * Bewerbermanagement, Lohnbuchhaltung, Kundenstamm, Marketing-Newsletter,
 * Vertragspartner. Each template is a single ProcessingActivity prefilled
 * with `dataSubjectCategories`, `personalDataCategories`, `legalBasis`,
 * `retentionPeriodDays`, `technicalOrganizationalMeasures` so the DPO can
 * adopt and refine instead of starting from a blank form.
 */
final class VvtStandardSetProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        $defs = [
            [
                'key' => 'recruiting',
                'name_de' => 'Bewerbermanagement',
                'name_en' => 'Recruiting / Applicant management',
                'description_de' => 'Verarbeitung von Bewerberdaten von der Eingangsbestätigung bis zur Absage oder Einstellung.',
                'description_en' => 'Processing of applicant data from acknowledgement to rejection or hire.',
                'purposes_de' => ['Stellenbesetzung', 'Auswahlverfahren', 'Kommunikation mit Bewerbern'],
                'purposes_en' => ['Job filling', 'Selection process', 'Applicant communication'],
                'data_subjects' => ['applicants'],
                'data_categories' => ['identity', 'contact', 'employment_history', 'qualifications'],
                'legal_basis' => 'pre_contractual',  // GDPR Art. 6(1)(b)
                'retention_days' => 180,  // BGB § 15 AGG + Beweiserhalt
                'department' => 'HR',
            ],
            [
                'key' => 'payroll',
                'name_de' => 'Lohn- und Gehaltsabrechnung',
                'name_en' => 'Payroll',
                'description_de' => 'Berechnung und Auszahlung von Löhnen, Sozialabgaben und Steuern.',
                'description_en' => 'Calculation and payment of wages, social contributions, and taxes.',
                'purposes_de' => ['Lohnzahlung', 'Sozialversicherungsmeldungen', 'Steuermeldungen'],
                'purposes_en' => ['Salary payment', 'Social insurance reporting', 'Tax reporting'],
                'data_subjects' => ['employees'],
                'data_categories' => ['identity', 'tax_id', 'bank_account', 'salary', 'social_insurance'],
                'legal_basis' => 'contract',  // GDPR Art. 6(1)(b) + Art. 88
                'retention_days' => 3650,  // 10 Jahre AO § 147
                'department' => 'HR / Accounting',
            ],
            [
                'key' => 'customer_master',
                'name_de' => 'Kundenstammdaten',
                'name_en' => 'Customer master data',
                'description_de' => 'Erfassung und Pflege von Kundenstammdaten zur Vertragsabwicklung und Kommunikation.',
                'description_en' => 'Maintenance of customer master data for contract handling and communication.',
                'purposes_de' => ['Vertragsabwicklung', 'Rechnungsstellung', 'Kundenservice'],
                'purposes_en' => ['Contract handling', 'Invoicing', 'Customer service'],
                'data_subjects' => ['customers'],
                'data_categories' => ['identity', 'contact', 'billing_address', 'order_history'],
                'legal_basis' => 'contract',
                'retention_days' => 3650,  // HGB § 257
                'department' => 'Sales / Operations',
            ],
            [
                'key' => 'marketing_newsletter',
                'name_de' => 'Marketing-Newsletter',
                'name_en' => 'Marketing newsletter',
                'description_de' => 'Versand von Marketing-E-Mails an Interessenten und Bestandskunden mit Double-Opt-In.',
                'description_en' => 'Dispatch of marketing emails to leads and existing customers via double opt-in.',
                'purposes_de' => ['Bestandskunden-Kommunikation', 'Lead-Pflege', 'Produktinformation'],
                'purposes_en' => ['Customer retention', 'Lead nurturing', 'Product updates'],
                'data_subjects' => ['leads', 'customers'],
                'data_categories' => ['email', 'consent_record', 'tracking_pixel_data'],
                'legal_basis' => 'consent',  // GDPR Art. 6(1)(a) + UWG § 7
                'retention_days' => 730,  // 2 Jahre nach Widerruf oder Inaktivität
                'department' => 'Marketing',
            ],
            [
                'key' => 'contract_partner',
                'name_de' => 'Vertragspartner / Lieferantenstamm',
                'name_en' => 'Contract partner / Supplier master',
                'description_de' => 'Erfassung von Ansprechpartnern bei Lieferanten, Beratern und sonstigen Vertragspartnern.',
                'description_en' => 'Master records of contact persons at suppliers, consultants, and other contract partners.',
                'purposes_de' => ['Vertragsabwicklung', 'Eskalationsmanagement', 'Lieferantenbewertung'],
                'purposes_en' => ['Contract handling', 'Escalation management', 'Supplier evaluation'],
                'data_subjects' => ['business_contacts'],
                'data_categories' => ['identity', 'business_contact', 'role'],
                'legal_basis' => 'contract',
                'retention_days' => 3650,
                'department' => 'Procurement',
            ],
        ];

        foreach (['de', 'en'] as $lang) {
            foreach ($defs as $def) {
                $de = $lang === 'de';
                yield new SystemTemplate(
                    key: 'vvt.standard.' . $def['key'] . '.' . $lang,
                    entityClass: ProcessingActivity::class,
                    module: 'privacy',
                    language: $lang,
                    name: $de ? $def['name_de'] : $def['name_en'],
                    description: $de ? $def['description_de'] : $def['description_en'],
                    prefill: [
                        'name' => $de ? $def['name_de'] : $def['name_en'],
                        'description' => $de ? $def['description_de'] : $def['description_en'],
                        'purposes' => $de ? $def['purposes_de'] : $def['purposes_en'],
                        'dataSubjectCategories' => $def['data_subjects'],
                        'personalDataCategories' => $def['data_categories'],
                        'legalBasis' => $def['legal_basis'],
                        'retentionPeriodDays' => $def['retention_days'],
                        'responsibleDepartment' => $def['department'],
                        'technicalOrganizationalMeasures' => $de
                            ? 'Zugriffskontrolle, Verschlüsselung bei Übertragung (TLS 1.2+), Backup (RPO 24h), Logging der Datenzugriffe, Mitarbeiter-Schulung jährlich.'
                            : 'Access control, encryption in transit (TLS 1.2+), backup (RPO 24h), data-access logging, annual staff training.',
                    ],
                );
            }
        }
    }
}
