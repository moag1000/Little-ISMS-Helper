<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\Tenant;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * Industry / size profiles that map to a curated `active_modules` set.
 *
 * Foundation P-14. Five typical organisational shapes:
 *  - `kmu`               — small/medium business
 *  - `konzern`           — group / holding
 *  - `verein`            — small association / not-for-profit
 *  - `finanzdienstleister` — financial services (BaFin/DORA/MaRisk)
 *  - `automotive_supplier` — automotive supplier (TISAX/Prototype Protection)
 *
 * The entityClass is `Tenant::class` and `prefill['activeModules']` carries
 * the module-key list. The Apply-Controller has a dedicated branch that
 * passes this list to `ModuleConfigurationService::setActiveModules()` (or
 * the equivalent migration path) rather than creating a new entity row.
 *
 * Junior-ISB-Audit-2026-05-22 M-04: Auto-Zulieferer (automotive_supplier)
 * added as 5th profile to address TISAX/Prototype-Protection scope.
 */
final class ModuleProfileProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        $profiles = [
            'kmu' => [
                'name_de' => 'KMU / Mittelstand',
                'name_en' => 'SMB / Mid-market',
                'description_de' => 'Klassisches mittelständisches Unternehmen mit ISO 27001-Ambition. Aktiviert die Basis-Module + Datenschutz.',
                'description_en' => 'Classic SMB targeting ISO 27001 certification. Activates core modules plus privacy.',
                'modules' => [
                    'core',
                    'assets',
                    'risks',
                    'controls',
                    'incidents',
                    'audits',
                    'training',
                    'documents',
                    'suppliers',
                    'privacy',
                    'workflows',
                ],
            ],
            'konzern' => [
                'name_de' => 'Konzern / Holding',
                'name_en' => 'Group / Holding',
                'description_de' => 'Konzernstruktur mit zentraler ISMS-Steuerung, mehreren Tochtergesellschaften, Konzern-CISO. Aktiviert alle KMU-Module plus Holding-Berichtswesen und erweiterte Compliance.',
                'description_en' => 'Group structure with central ISMS governance, multiple subsidiaries, group CISO. Activates all SMB modules plus group reporting and extended compliance.',
                'modules' => [
                    'core',
                    'assets',
                    'risks',
                    'controls',
                    'incidents',
                    'audits',
                    'reviews',
                    'training',
                    'documents',
                    'suppliers',
                    'privacy',
                    'bcm',
                    'compliance',
                    'workflows',
                    'analytics',
                    'portfolio_reports',
                    'corrective_actions',
                    'evidence',
                ],
            ],
            'verein' => [
                'name_de' => 'Verein / Non-Profit',
                'name_en' => 'Association / Non-profit',
                'description_de' => 'Kleiner Verein oder Non-Profit ohne ISO-Ambition, primär DSGVO-Fokus. Aktiviert nur Privacy + BCM-Basis.',
                'description_en' => 'Small association or non-profit without ISO ambition, GDPR-focused. Activates privacy + BCM basics only.',
                'modules' => [
                    'core',
                    'documents',
                    'privacy',
                    'bcm',
                    'workflows',
                ],
            ],
            'finanzdienstleister' => [
                'name_de' => 'Finanzdienstleister (BaFin / DORA)',
                'name_en' => 'Financial services (BaFin / DORA)',
                'description_de' => 'BaFin-beaufsichtigtes Institut unter DORA + MaRisk. Aktiviert alle Standard-ISMS-Module plus DORA, NIS2, ICT-Risk und Auslagerungsmanagement.',
                'description_en' => 'BaFin-supervised institution under DORA + MaRisk. Activates all standard ISMS modules plus DORA, NIS2, ICT-risk, and outsourcing management.',
                'modules' => [
                    'core',
                    'assets',
                    'risks',
                    'controls',
                    'incidents',
                    'audits',
                    'reviews',
                    'training',
                    'documents',
                    'suppliers',
                    'privacy',
                    'bcm',
                    'compliance',
                    'workflows',
                    'analytics',
                    'corrective_actions',
                    'evidence',
                    'marisk',
                    'quantitative_risk',
                    'eu_authority_reporting',
                    'notifications',
                ],
            ],
            // Junior-ISB-Audit-2026-05-22 M-04: Automotive supplier profile.
            // Driven by OEM-mandated TISAX assessment + Prototype-Protection
            // requirements. Suppliers in this segment carry NIS2 / DORA exposure
            // through their banking + IT-services value chain, but their core
            // certification driver is TISAX.
            'automotive_supplier' => [
                'name_de' => 'Auto-Zulieferer (TISAX / Prototypenschutz)',
                'name_en' => 'Automotive supplier (TISAX / Prototype Protection)',
                'description_de' => 'Automotive-Zulieferer mit TISAX-Pflicht durch OEM. Aktiviert Standard-ISMS-Module plus TISAX, TISAX-ISA, Prototypenschutz und Lieferanten-Management.',
                'description_en' => 'Automotive supplier with TISAX requirement from OEM. Activates standard ISMS modules plus TISAX, TISAX-ISA, prototype protection, and supplier management.',
                'modules' => [
                    'core',
                    'assets',
                    'risks',
                    'controls',
                    'incidents',
                    'audits',
                    'training',
                    'documents',
                    'suppliers',
                    'privacy',
                    'workflows',
                    'tisax',
                    'tisax_isa',
                ],
            ],
        ];

        foreach (['de', 'en'] as $lang) {
            foreach ($profiles as $key => $p) {
                $de = $lang === 'de';
                yield new SystemTemplate(
                    key: 'tenant.profile.' . $key . '.' . $lang,
                    entityClass: Tenant::class,
                    module: null,  // tenant-level templates are not module-gated
                    language: $lang,
                    name: $de ? $p['name_de'] : $p['name_en'],
                    description: $de ? $p['description_de'] : $p['description_en'],
                    prefill: [
                        'profileKey' => $key,
                        'activeModules' => $p['modules'],
                    ],
                );
            }
        }
    }
}
