<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Service\Compliance\FrameworkLoaderRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use Exception;
use App\Repository\ComplianceFrameworkRepository;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Service to manage and load compliance frameworks via UI
 */
final class ComplianceFrameworkLoaderService
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly FrameworkLoaderRegistry $frameworkLoaderRegistry,
        private readonly \App\Service\Tisax\TisaxCatalogueProvider $tisaxCatalogue,
    ) {}

    /**
     * Get list of all available frameworks with their metadata and load status
     */
    public function getAvailableFrameworks(): array
    {
        $loadedFrameworks = $this->complianceFrameworkRepository->findAll();
        $loadedCodes = array_map(fn(ComplianceFramework $f): ?string => $f->getCode(), $loadedFrameworks);

        // Single metadata source: name/version/description come from the YAML via
        // the catalogue provider (no hardcoded drift).
        $tisaxMeta = $this->tisaxCatalogue->getMetadata();

        return [
            [
                'code' => $tisaxMeta['code'],
                'name' => $tisaxMeta['name'],
                'description' => $tisaxMeta['description'],
                'industry' => 'automotive',
                'regulatory_body' => 'VDA (Verband der Automobilindustrie)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.tisax',
                'version' => $tisaxMeta['version'],
                'loaded' => in_array('TISAX', $loadedCodes),
                'icon' => '🚗',
                'required_modules' => ['compliance', 'controls', 'risks', 'assets', 'incidents'],
            ],
            [
                'code' => 'DORA',
                'name' => 'EU-DORA (Digital Operational Resilience Act)',
                'description' => 'Regulation on digital operational resilience for the financial sector',
                'industry' => 'financial_services',
                'regulatory_body' => 'European Union',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.dora',
                'version' => '2022/2554',
                'loaded' => in_array('DORA', $loadedCodes),
                'icon' => '🏦',
                'required_modules' => ['compliance', 'controls', 'risks', 'bcm', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'NIS2',
                'name' => 'NIS2 (Network and Information Security Directive 2)',
                'description' => 'EU directive on measures for a high common level of cybersecurity',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.nis2',
                'version' => '2022/2555',
                'loaded' => in_array('NIS2', $loadedCodes),
                'icon' => '🛡️',
                'required_modules' => ['compliance', 'controls', 'risks', 'bcm', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'BSI_GRUNDSCHUTZ',
                'name' => 'BSI IT-Grundschutz',
                'description' => 'German information security standard by the Federal Office for Information Security',
                'industry' => 'all_sectors',
                'regulatory_body' => 'BSI (Bundesamt für Sicherheit in der Informationstechnik)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.bsi_grundschutz',
                'version' => 'Edition 2023',
                'loaded' => in_array('BSI_GRUNDSCHUTZ', $loadedCodes),
                'icon' => '🇩🇪',
                'required_modules' => ['compliance', 'controls', 'risks', 'assets'],
            ],
            [
                'code' => 'GDPR',
                'name' => 'GDPR (General Data Protection Regulation)',
                'description' => 'EU regulation on data protection and privacy',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => true,
                'applicability' => 'universal',
                'applicability_condition_key' => null,
                'version' => '2016/679',
                'loaded' => in_array('GDPR', $loadedCodes),
                'icon' => '🔒',
                'required_modules' => ['compliance', 'controls', 'audit_logging', 'training', 'incidents'],
            ],
            [
                'code' => 'ISO27001',
                'name' => 'ISO/IEC 27001:2022 - ISMS',
                'description' => 'Information Security Management System - International standard for information security management',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2022',
                'loaded' => in_array('ISO27001', $loadedCodes),
                'icon' => '📋',
                'required_modules' => ['compliance', 'controls'],
            ],
            [
                'code' => 'ISO27701',
                'name' => 'ISO/IEC 27701:2019 - PIMS',
                'description' => 'Privacy Information Management System extension to ISO 27001/27002',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2019',
                'loaded' => in_array('ISO27701', $loadedCodes),
                'icon' => '🔐',
                'required_modules' => ['compliance', 'controls', 'audit_logging'],
            ],
            [
                'code' => 'ISO27701_2025',
                'name' => 'ISO/IEC 27701:2025 - PIMS',
                'description' => 'Privacy Information Management System - Standalone standard with AI governance (Latest)',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2025',
                'loaded' => in_array('ISO27701_2025', $loadedCodes),
                'icon' => '🤖',
                'required_modules' => ['compliance', 'controls', 'audit_logging'],
            ],
            [
                'code' => 'BSI-C5',
                'name' => 'BSI C5:2020 - Cloud Computing Compliance Criteria Catalogue',
                'description' => 'German Federal Office for Information Security criteria catalogue for secure cloud computing (121 criteria in 17 categories)',
                'industry' => 'cloud_services',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2020',
                'loaded' => in_array('BSI-C5', $loadedCodes),
                'icon' => '☁️',
                'required_modules' => ['compliance', 'controls', 'risks', 'audit_logging'],
            ],
            [
                'code' => 'BSI-C5-2026',
                'name' => 'BSI C5:2026 - Cloud Computing Compliance Criteria Catalogue',
                'description' => 'C5:2026 final release with container management, supply chain security, post-quantum cryptography readiness, and EUCS Substantial alignment (mandatory from Jan 2027)',
                'industry' => 'cloud_services',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2026',
                'loaded' => in_array('BSI-C5-2026', $loadedCodes),
                'icon' => '🔮',
                'required_modules' => ['compliance', 'controls', 'risks', 'audit_logging'],
            ],
            [
                'code' => 'EUCS',
                'name' => 'EUCS — European Cybersecurity Certification Scheme for Cloud Services (ENISA)',
                'description' => 'ENISA candidate scheme under the EU Cybersecurity Act (Regulation (EU) 2019/881). Three assurance levels (Basic, Substantial, High) across 20 control categories (OIS … CCM). Aligns with BSI C5:2026 Substantial tier.',
                'industry' => 'cloud_services',
                'regulatory_body' => 'ENISA / European Union',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2024 (candidate)',
                'loaded' => in_array('EUCS', $loadedCodes),
                'icon' => 'asset-cloud',
                'required_modules' => ['compliance', 'controls'],
            ],
            [
                'code' => 'KRITIS',
                'name' => 'KRITIS - Critical Infrastructure Protection (§8a BSIG)',
                'description' => 'German security requirements for operators of critical infrastructure based on IT Security Act 2.0 (135 controls)',
                'industry' => 'critical_infrastructure',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.kritis',
                'version' => '§8a BSIG (IT-SiG 2.0)',
                'loaded' => in_array('KRITIS', $loadedCodes),
                'icon' => '⚡',
                'required_modules' => ['compliance', 'controls', 'risks', 'bcm', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'KRITIS-HEALTH',
                'name' => 'KRITIS Healthcare - Hospital IT Security',
                'description' => 'IT security requirements for hospitals and healthcare facilities (KHPatSiG, KHZG, §75c SGB V, BSI B3S, 37 requirements)',
                'industry' => 'healthcare',
                'regulatory_body' => 'BSI / Bundesgesundheitsministerium',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.kritis_health',
                'version' => 'KHPatSiG/KHZG 2024',
                'loaded' => in_array('KRITIS-HEALTH', $loadedCodes),
                'icon' => '🏥',
                'required_modules' => ['compliance', 'controls', 'risks', 'bcm', 'incidents', 'audit_logging', 'training'],
            ],
            [
                'code' => 'DIGAV',
                'name' => 'DiGAV - Digital Health Applications Regulation',
                'description' => 'Requirements for digital health applications (DiGA) - apps on prescription in Germany (38 requirements)',
                'industry' => 'healthcare',
                'regulatory_body' => 'BfArM - Bundesinstitut für Arzneimittel und Medizinprodukte',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.digav',
                'version' => 'DiGAV 2024',
                'loaded' => in_array('DIGAV', $loadedCodes),
                'icon' => '📱',
                'required_modules' => ['compliance', 'controls', 'audit_logging'],
            ],
            [
                'code' => 'TKG-2024',
                'name' => 'TKG 2024 - Telecommunications Security',
                'description' => 'Security requirements for telecommunications providers (§164-167 TKG, TK-SiV, BNetzA Security Catalog 2.0, 43 requirements)',
                'industry' => 'telecommunications',
                'regulatory_body' => 'Bundesnetzagentur / BSI',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.tkg',
                'version' => 'TKG 2024',
                'loaded' => in_array('TKG-2024', $loadedCodes),
                'icon' => '📡',
                'required_modules' => ['compliance', 'controls', 'risks', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'GXP',
                'name' => 'GxP - Good Practice (Pharmaceutical & Life Sciences)',
                'description' => 'Regulatory requirements for pharmaceutical and life sciences (EU GMP Annex 11, FDA 21 CFR Part 11, GAMP 5, 65+ requirements)',
                'industry' => 'pharmaceutical',
                'regulatory_body' => 'EMA / FDA',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.gxp',
                'version' => 'EU GMP Annex 11 (2024) / FDA 21 CFR Part 11',
                'loaded' => in_array('GXP', $loadedCodes),
                'icon' => '💊',
                'required_modules' => ['compliance', 'controls', 'audit_logging', 'training'],
            ],
            [
                'code' => 'SOC2',
                'name' => 'SOC 2 Type II (AICPA Trust Services Criteria)',
                'description' => 'US SaaS/Cloud-Provider attestation standard covering Security, Availability, Processing Integrity, Confidentiality and Privacy (~50 criteria)',
                'industry' => 'all_sectors',
                'regulatory_body' => 'AICPA',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2017 TSC (revised 2022)',
                'loaded' => in_array('SOC2', $loadedCodes),
                'icon' => '🇺🇸',
                'required_modules' => ['compliance', 'controls', 'audit_logging'],
            ],
            [
                // Canonical DB code is NIST-CSF-2.0 (migration Version20260506213529
                // merged the legacy NIST-CSF row away; the cross-framework mappings
                // and the registry-bound LoadNistCsf2FullCatalogueCommand all use it).
                'code' => 'NIST-CSF-2.0',
                'name' => 'NIST Cybersecurity Framework 2.0',
                'description' => 'US cybersecurity risk management framework with 6 Functions (Govern, Identify, Protect, Detect, Respond, Recover) and ~100 sub-categories',
                'industry' => 'all_sectors',
                'regulatory_body' => 'NIST',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2.0 (2024)',
                'loaded' => in_array('NIST-CSF-2.0', $loadedCodes),
                'icon' => '🛰️',
                'required_modules' => ['compliance', 'controls', 'risks'],
            ],
            [
                'code' => 'CIS-CONTROLS',
                'name' => 'CIS Controls v8.1',
                'description' => 'Center for Internet Security Top-18 Controls with Implementation Groups IG1-IG3 (~150 safeguards)',
                'industry' => 'all_sectors',
                'regulatory_body' => 'Center for Internet Security',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '8.1 (2024)',
                'loaded' => in_array('CIS-CONTROLS', $loadedCodes),
                'icon' => '🛠️',
                'required_modules' => ['compliance', 'controls'],
            ],
            [
                'code' => 'ISO-22301',
                'name' => 'ISO 22301:2019 - Business Continuity Management',
                'description' => 'Business Continuity Management System with ISO 22313:2020 guidance (~50 requirements)',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2019 (+ 22313:2020)',
                'loaded' => in_array('ISO-22301', $loadedCodes),
                'icon' => '🔁',
                'required_modules' => ['compliance', 'bcm'],
            ],
            [
                'code' => 'ISO27005',
                'name' => 'ISO/IEC 27005:2022 - Information Security Risk Management',
                'description' => 'Guidance on information security risk management, complements ISO 27001 risk processes',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'voluntary',
                'applicability_condition_key' => null,
                'version' => '2022',
                'loaded' => in_array('ISO27005', $loadedCodes),
                'icon' => '📊',
                'required_modules' => ['compliance', 'risks'],
            ],
            [
                'code' => 'BDSG',
                'name' => 'Bundesdatenschutzgesetz (BDSG)',
                'description' => 'Deutsches Datenschutzgesetz als Ergänzung zur DSGVO, 12 Anforderungen aus Teil 1-4',
                'industry' => 'all_sectors',
                'regulatory_body' => 'Bundesministerium des Innern',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.bdsg',
                'version' => '2018 (aktuell)',
                'loaded' => in_array('BDSG', $loadedCodes),
                'icon' => '🇩🇪',
                'required_modules' => ['compliance', 'controls', 'audit_logging'],
            ],
            [
                'code' => 'EU-AI-ACT',
                'name' => 'EU AI Act (Regulation (EU) 2024/1689)',
                'description' => 'Risk-based AI regulation — full article-level catalogue (113 Articles + 13 Annexes, Art.X scheme) for Providers/Deployers of High-Risk and GPAI systems',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.eu_ai_act',
                'version' => '2024/1689',
                'loaded' => in_array('EU-AI-ACT', $loadedCodes),
                'icon' => '🤖',
                'required_modules' => ['compliance', 'controls', 'risks', 'audit_logging'],
            ],
            [
                'code' => 'ISO42001',
                'name' => 'ISO/IEC 42001:2023 - AI Management System (AIMS)',
                'description' => 'AI-Managementsystem-Norm: Governance, Risikobeurteilung, 38 Annex-A-Controls für verantwortungsvolle KI + Klauseln 4-10. Ergänzt den EU AI Act (Art.X-Mapping).',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.iso42001',
                'version' => '2023',
                'loaded' => in_array('ISO42001', $loadedCodes),
                'icon' => '🤖',
                'required_modules' => ['compliance', 'controls', 'risks'],
            ],
            [
                'code' => 'ISO27017',
                'name' => 'ISO/IEC 27017:2015 - Cloud Security',
                'description' => 'Cloud-spezifische Sicherheitscontrols (7 CLD-Controls) + cloud-bezogene Umsetzungsleitlinien zu ISO 27002.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.iso27017',
                'version' => '2015',
                'loaded' => in_array('ISO27017', $loadedCodes),
                'icon' => '☁️',
                'required_modules' => ['compliance', 'controls'],
            ],
            [
                'code' => 'ISO27018',
                'name' => 'ISO/IEC 27018:2019 - Cloud Privacy (PII)',
                'description' => 'Schutz personenbezogener Daten (PII) in Public-Cloud-Diensten — Annex-A-Privacy-Controls auf Basis ISO 27002.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.iso27018',
                'version' => '2019',
                'loaded' => in_array('ISO27018', $loadedCodes),
                'icon' => '☁️',
                'required_modules' => ['compliance', 'controls', 'privacy'],
            ],
            [
                'code' => 'EU-CRA',
                'name' => 'EU Cyber Resilience Act (Regulation 2024/2847)',
                'description' => 'Cybersicherheitsanforderungen für Produkte mit digitalen Elementen — Annex-I-Sicherheitsanforderungen + Schwachstellenbehandlung + Hersteller-Pflichten.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.eu_cra',
                'version' => '2024/2847',
                'loaded' => in_array('EU-CRA', $loadedCodes),
                'icon' => '🛡️',
                'required_modules' => ['compliance', 'controls', 'risks'],
            ],
            [
                'code' => 'PCI-DSS-4.0.1',
                'name' => 'PCI DSS v4.0.1 - Payment Card Industry Data Security Standard',
                'description' => '12 Anforderungen für die Sicherheit von Karteninhaberdaten (Netzwerk, Zugriff, Verschlüsselung, Monitoring, Tests).',
                'industry' => 'financial_services',
                'regulatory_body' => 'PCI Security Standards Council',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.pci_dss',
                'version' => '4.0.1',
                'loaded' => in_array('PCI-DSS-4.0.1', $loadedCodes),
                'icon' => '💳',
                'required_modules' => ['compliance', 'controls'],
            ],
            [
                'code' => 'NIS2UMSUCG',
                'name' => 'NIS-2-Umsetzungs- und Cybersicherheitsstärkungsgesetz (NIS2UmsuCG)',
                'description' => 'Deutsche Umsetzung der NIS2-Richtlinie mit zusätzlichen BSI-spezifischen Anforderungen',
                'industry' => 'all_sectors',
                'regulatory_body' => 'BSI / Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.nis2umsucg',
                'version' => '2024',
                'loaded' => in_array('NIS2UMSUCG', $loadedCodes),
                'icon' => '🇩🇪',
                'required_modules' => ['compliance', 'controls', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'MRIS-v1.5',
                'name' => 'MRIS — Mythos-resistente Informationssicherheit v1.5',
                'description' => 'Open-source Zusatzkatalog auf bestehendem ISO-27001-ISMS für Gen-AI-Bedrohungslage. 13 Mythos-Härtungs-Controls (MHC-01..13). CC BY 4.0 (Peddi 2026). Voraussetzung für mehrere Industry-Baselines (z.B. automotive-tisax-al3).',
                'industry' => 'all_sectors',
                'regulatory_body' => 'Independent (Open Standard)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.mris',
                'version' => '1.5',
                'loaded' => in_array('MRIS-v1.5', $loadedCodes),
                'icon' => '🛡️',
                'required_modules' => ['compliance', 'controls'],
            ],
            // ── DACH: Austrian + Swiss frameworks ─────────────────────────────
            [
                'code' => 'NISG-AT',
                'name' => 'NISG 2026 — Netz- und Informationssystemsicherheitsgesetz 2026 (Österreich)',
                'description' => 'Österreichische Umsetzung der NIS-2-Richtlinie (EU 2022/2555). Bundesgesetz zur Gewährleistung eines hohen Cybersicherheitsniveaus (BGBl. I Nr. 94/2025, RIS 20013065). Gilt für wesentliche und wichtige Einrichtungen in 18 Sektoren; tritt 30.09.2026 in Kraft. Quelle: ris.bka.gv.at.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'Bundesamt für Cybersicherheit / Bundesminister für Inneres (AT)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.nisg_at',
                'version' => 'BGBl. I Nr. 94/2025',
                'loaded' => in_array('NISG-AT', $loadedCodes),
                'icon' => '🇦🇹',
                'required_modules' => ['compliance', 'controls', 'risks', 'incidents', 'audit_logging'],
            ],
            [
                'code' => 'REVDSG-CH',
                'name' => 'revDSG / nDSG — Revidiertes Datenschutzgesetz (SR 235.1)',
                'description' => 'Schweizerisches Bundesgesetz über den Datenschutz (nDSG) in Kraft seit 01.09.2023. Das revidierte DSG wurde bewusst an die EU-DSGVO angepasst (EU-Angemessenheitsziel). Reguliert Bearbeitung von Personendaten durch private Personen und Bundesorgane. Zuständige Behörde: EDÖB.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'Bundesrat / Eidgenössischer Datenschutz- und Öffentlichkeitsbeauftragter (EDÖB)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.revdsg_ch',
                'version' => '2023',
                'loaded' => in_array('REVDSG-CH', $loadedCodes),
                'icon' => '🇨🇭',
                'required_modules' => ['compliance', 'privacy'],
            ],
            [
                'code' => 'IKT-MINSTD-CH',
                'name' => 'IKT-Minimalstandard — Minimalstandard zur Verbesserung der IKT-Resilienz (Schweiz)',
                'description' => 'Schweizer IKT-Resilienzstandard des NCSC / BWL (Bundesamt für wirtschaftliche Landesversorgung), Version Mai 2023. 108 Maßnahmen strukturiert auf NIST CSF 1.1 (Identifizieren, Schützen, Erkennen, Reagieren, Wiederherstellen). Quelle: ncsc.admin.ch.',
                'industry' => 'all_sectors',
                'regulatory_body' => 'NCSC / Bundesamt für wirtschaftliche Landesversorgung (BWL)',
                'mandatory' => false,
                'applicability' => 'conditional',
                'applicability_condition_key' => 'admin.compliance.applicability.condition.ikt_minstd_ch',
                'version' => 'Mai 2023',
                'loaded' => in_array('IKT-MINSTD-CH', $loadedCodes),
                'icon' => '🇨🇭',
                'required_modules' => ['compliance', 'controls', 'risks'],
            ],
        ];
    }

    /**
     * Load a specific framework by code
     */
    public function loadFramework(string $code): array
    {
        if (!$this->frameworkLoaderRegistry->has($code)) {
            return [
                'success' => false,
                'message' => 'Framework not found',
            ];
        }

        // Check if already loaded with requirements
        $existingFramework = $this->complianceFrameworkRepository->findOneBy(['code' => $code]);
        if ($existingFramework) {
            $requirementsCount = $existingFramework->requirements->count();
            if ($requirementsCount > 0) {
                return [
                    'success' => false,
                    'message' => sprintf('Framework already loaded with %d requirements', $requirementsCount),
                ];
            }
            // Framework exists but has no requirements - allow loading
        }

        // Execute the loader via the registry
        $bufferedOutput = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $bufferedOutput);

        try {
            $returnCode = $this->frameworkLoaderRegistry->load($code, false, $io);

            if ($returnCode === 0) {
                // Get framework ID for "Start Working" button
                $framework = $this->complianceFrameworkRepository->findOneBy(['code' => $code]);
                $frameworkId = $framework ? $framework->id : null;

                return [
                    'success' => true,
                    'message' => sprintf('Successfully loaded %s framework', $code),
                    'output' => $bufferedOutput->fetch(),
                    'framework_id' => $frameworkId,
                ];
            }
            return [
                'success' => false,
                'message' => 'Failed to load framework',
                'output' => $bufferedOutput->fetch(),
            ];
        } catch (UniqueConstraintViolationException) {
            return [
                'success' => false,
                'message' => 'Framework or requirements already exist in database',
            ];
        } catch (ORMException $e) {
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error loading framework: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get statistics about loaded frameworks
     */
    public function getFrameworkStatistics(): array
    {
        $available = $this->getAvailableFrameworks();
        $loaded = array_filter($available, fn(array $f) => $f['loaded']);
        $mandatory = array_filter($available, fn(array $f) => $f['mandatory']);
        $mandatoryLoaded = array_filter($mandatory, fn(array $f) => $f['loaded']);

        return [
            'total_available' => count($available),
            'total_loaded' => count($loaded),
            'total_not_loaded' => count($available) - count($loaded),
            'mandatory_frameworks' => count($mandatory),
            'mandatory_loaded' => count($mandatoryLoaded),
            'mandatory_not_loaded' => count($mandatory) - count($mandatoryLoaded),
            'compliance_percentage' => count($available) > 0
                ? round((count($loaded) / count($available)) * 100, 1)
                : 0,
        ];
    }
}
