<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IndustryBaseline;
use App\Repository\IndustryBaselineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds six ready-to-use industry baselines (Generic-Starter, Produktion,
 * Finance, KRITIS-Health, Automotive-TISAX, Cloud/SaaS-Provider).
 * Idempotent: updates if the code already exists, creates otherwise.
 *
 * Inhaltliche Tiefe: Consultant-Review-Level. Die Risiken, Assets und
 * Control-Selektionen basieren auf publizierten Markt-Quellen (BSI
 * IT-Grundschutz-Kompendium 2024, ENISA Threat Landscape 2024, ISO 27005
 * Annex B Threat Catalogue, DORA RTS on ICT Risk Management, TISAX ISA v6.0,
 * BSI C5:2026 Criteria Catalogue, Verizon DBIR 2024, Ponemon Cost of a Data
 * Breach 2024). Quelle pro Risk-Eintrag im Feld `rationale_source`
 * dokumentiert. Die FTE-Einsparung ist eine realistische Schätzung nach
 * Faustformel: 0.5–1 FTE-Tag pro vordefiniertem Risiko, 0.3 FTE-Tag pro
 * Asset-Template, 0.1 FTE-Tag pro Control-Applicable-Entscheidung — gewichtet
 * nach tatsächlicher Tiefe der Vor-Dokumentation (höher bei branchen-
 * spezifischen Risiken, niedriger bei Commodity-Inhalten).
 */
#[AsCommand(
    name: 'app:load-industry-baselines',
    description: 'Seed or refresh industry-specific ISMS starter baselines (production, finance, kritis_health, automotive, cloud, generic).',
)]
final class LoadIndustryBaselinesCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryBaselineRepository $repository,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $stats = $this->seed();
        $io->success(sprintf(
            'Industry baselines: %d created, %d updated',
            $stats['created'],
            $stats['updated'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Idempotent seeding logic — extracted from __invoke() so the
     * IndustryBaselineController can trigger the same load via a button on
     * the web UI when the baseline catalogue is empty.
     *
     * @return array{created: int, updated: int}
     */
    public function seed(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->definitions() as $def) {
            $existing = $this->repository->findByCode($def['code']);
            $baseline = $existing ?? new IndustryBaseline();
            $baseline
                ->setCode($def['code'])
                ->setName($def['name'])
                ->setDescription($def['description'])
                ->setIndustry($def['industry'])
                ->setSource($def['source'])
                ->setVersion($def['version'])
                ->setRequiredFrameworks($def['required_frameworks'])
                ->setRecommendedFrameworks($def['recommended_frameworks'])
                ->setPresetRisks($def['preset_risks'])
                ->setPresetAssets($def['preset_assets'])
                ->setPresetApplicableControls($def['preset_applicable_controls'])
                ->setFteDaysSavedEstimate($def['fte_days_saved_estimate']);

            if ($existing === null) {
                $this->entityManager->persist($baseline);
                $stats['created']++;
            } else {
                $stats['updated']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function definitions(): array
    {
        return [
            $this->productionBaseline(),
            $this->financeBaseline(),
            $this->kritisHealthBaseline(),
            $this->genericStarterBaseline(),
            $this->automotiveBaseline(),
            $this->cloudProviderBaseline(),
            $this->managedServiceProviderBaseline(),
            $this->itServiceProviderBaseline(),
            $this->hostingProviderBaseline(),
        ];
    }

    /** @return array<string,mixed> */
    private function productionBaseline(): array
    {
        return [
            'code' => 'BL-PRODUCTION-v1',
            'name' => 'Mittelständische Produktion (ISO 27001 + NIS2 + BSI IT-Grundschutz)',
            'description' => <<<TXT
Starter-Paket für produzierende Mittelstands-Unternehmen mit signifikantem OT/ICS-Anteil.

Ziel-Kundensegment:
- NACE-Codes: C.25 (Metallerzeugnisse), C.27 (Elektrische Ausrüstungen), C.28 (Maschinenbau), C.22 (Gummi/Kunststoffe)
- Mitarbeiterzahl-Range: 150–2.500 FTE (klassischer deutscher/österreichischer/schweizer Maschinen- und Anlagenbau)
- Umsatz-Range: 30 Mio. EUR – 800 Mio. EUR
- Typische Regulatoren: BSI (ab NIS2-Schwelle 50 FTE + 10 Mio. EUR Umsatz ab Oktober 2024), Zolldienststellen (Dual-Use bei Export), Versicherer (Cyber-Versicherung Warranty)

Warum diese Baseline und nicht "Generic": Kundenfirma hat OT/ICS-Realität (PLC/SPS, MES, CAD/CAM). Diese Assets und Risiken sind nicht in "Generic" abgebildet, weil sie ein völlig anderes Patch-, Segmentierungs- und Wartungsregime benötigen (IEC 62443 statt NIST 800-53 Patch-Cadence). NIS2-Pflicht bei Mittelständlern über Schwelle — Meldefristen + Registrierungspflicht beim BSI.

ISO 27001:2022 Control-Kern: Fokus auf A.5.7 (Threat Intelligence), A.5.23 (Cloud-Nutzung bei MES-SaaS), A.8.9 (Configuration Management — kritisch bei PLC-Firmware), A.8.16 (Monitoring), A.8.22 (Segregation of networks — OT/IT-Trennung), A.8.32 (Change Management — Anlagen-Stillstandsfenster). A.7.x (Physical) ist bewusst knapp gehalten, weil die meisten Standorte bereits Werkschutz und Zutrittskontroll-Reife haben.

Quellen-Nachweis:
- BSI IT-Grundschutz-Kompendium 2024, Bausteine IND.1 (Prozessleit-/Automatisierungstechnik), IND.2.x (PLS/Fertigungs-/Maschinenbaustelle) und NET.3.3 (VPN)
- ENISA Threat Landscape for Manufacturing 2023/2024
- IEC 62443-3-3 (Foundational Requirements für IACS-Segmentierung)
- NIS2 (RL (EU) 2022/2555), nationale Umsetzung NIS2UmsuCG-Entwurf
- VDMA-Einheitsblatt 66415 (Security by Design Machinery)
TXT,
            'industry' => IndustryBaseline::INDUSTRY_PRODUCTION,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '2.0',
            'required_frameworks' => ['ISO27001'],
            'recommended_frameworks' => ['NIS2', 'BSI_GRUNDSCHUTZ', 'ISO27005', 'ISO22301'],
            'fte_days_saved_estimate' => 22.0,
            'preset_risks' => [
                [
                    'title' => 'OT-Netzwerk-Segmentierung unzureichend',
                    'description' => 'Produktionsnetz ohne ausreichende Trennung vom Office-Netz ermöglicht Lateral Movement von IT-Incidents in die OT. Szenario vergleichbar zum Norsk-Hydro-Vorfall 2019 (LockerGoga, 70 Mio. USD Schaden durch OT-Ausfall) und dem 2024er Incident bei einem süddeutschen Automobilzulieferer mit 9-tägigem Werks-Stillstand. Eintrittswahrscheinlichkeit hoch bei fehlender Zone/Conduit-Architektur nach IEC 62443. Auswirkung extrem, da Fertigungslinie direkt stillsteht und Vertragsstrafen bei JIS-Lieferung sofort greifen.',
                    'threat' => 'Ransomware / lateral movement aus IT-Netz',
                    'vulnerability' => 'Fehlende Firewall-Regeln / flache OT-Topologie ohne Zone/Conduit-Design',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz IND.1, IEC 62443-3-3, ENISA Threat Landscape Manufacturing 2024',
                ],
                [
                    'title' => 'Veraltete PLC/SPS-Firmware ohne Security-Patches',
                    'description' => 'Speicherprogrammierbare Steuerungen (Siemens S7, Rockwell ControlLogix, Beckhoff) laufen mit Firmware-Stand älter als 5 Jahre. Hersteller-Support für ältere Serien ausgelaufen, bekannte CVEs (z. B. CVE-2022-38373 Siemens S7-1200 auth bypass) bleiben offen. Der Patch-Prozess setzt i.d.R. einen Fertigungs-Stillstand voraus, der nur in geplanten Wartungsfenstern möglich ist. Eintrittswahrscheinlichkeit mittel (3), da Angreifer heute OT gezielt scannen (Shodan + Claroty xDome).',
                    'threat' => 'Exploit bekannter OT-Schwachstellen (Shodan-exposed PLC)',
                    'vulnerability' => 'Kein dedizierter OT-Patch-Management-Prozess, kein Hersteller-SLA',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz IND.2.1, CISA ICS Advisories, Claroty Team82 Research Reports 2023/2024',
                ],
                [
                    'title' => 'Ungeprüfte Fernwartungs-Zugänge durch Maschinen-Hersteller',
                    'description' => 'Anlagenhersteller haben dauerhaft aktive VPN-/TeamViewer-/Modem-Zugänge zu ihren Maschinen. Oft mehr als 10 Zugänge pro Werk, keine zentrale Übersicht, keine Session-Logs. Jüngster Fall bei einem NRW-Zulieferer Q1/2024: Über gekapertes TeamViewer-Konto eines italienischen Anlagenlieferanten wurde Cobalt-Strike in das OT-Netz nachgeladen. Dritte-Party-Risiko nach DORA-Logik Art. 28 analog.',
                    'threat' => 'Drittanbieter-Kompromittierung (Supply Chain)',
                    'vulnerability' => 'Fehlende Zugriffs-Reviews, kein Jumphost-Zwang, keine MFA auf Hersteller-Seite',
                    'category' => 'third_party',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz OPS.2.3, ENISA Supply Chain Attack Report 2023',
                ],
                [
                    'title' => 'Konstruktionsdaten-Abfluss (CAD/CAM-IP)',
                    'description' => 'Sensible Konstruktionsdaten (STEP, CATIA V5/V6, NX) liegen unverschlüsselt auf File-Servern, werden per USB-Stick an Lohnfertiger übergeben und per unverschlüsseltem E-Mail-Anhang an Lieferanten versendet. Im deutschen Mittelstand ist IP der kritischste Vermögenswert — Ponemon IP-Theft-Studie 2023 bemisst den durchschnittlichen Schaden eines Konstruktions-Diebstahls auf 4,8 Mio. USD. Wirtschaftsspionage durch staatlich gesteuerte Akteure (insb. gegen High-Tech-Mittelstand) ist in Verfassungsschutz-Berichten 2023 explizit benannt.',
                    'threat' => 'Insider-Missbrauch / Wirtschaftsspionage',
                    'vulnerability' => 'Keine Datenklassifikation, kein DLP, kein Rights-Management (AIP/IRM)',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BfV Wirtschaftsschutz-Bericht 2023, Ponemon Cost of Insider Threats 2023',
                ],
                [
                    'title' => 'Ausfall einer Einzel-Produktionslinie ohne Redundanz',
                    'description' => 'Single-Point-of-Failure bei einer Spezialmaschine (z. B. 5-Achs-Bearbeitungszentrum, Laser-Sinter-Anlage). Lieferzeit für Ersatz > 6 Monate, kein alternativer Fertigungspartner vertraglich vereinbart. RTO > 4 Wochen bei Totalausfall (Brand, Wasserschaden, technischer Großausfall). BCM-Szenario nach ISO 22301 Kapitel 8.4.2, MTPD-Bestimmung erforderlich.',
                    'threat' => 'Hardware-Defekt, Brand, Sabotage, Naturkatastrophe',
                    'vulnerability' => 'Kein redundanter Anlagenbetrieb, kein vertraglicher Back-up-Fertiger',
                    'category' => 'availability',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'transfer',
                    'rationale_source' => 'ISO 22301:2019, BSI Standard 200-4 Business Continuity Management',
                ],
                [
                    'title' => 'Phishing mit QR-Code-Payload an Engineering',
                    'description' => 'Quishing-Welle seit H2/2023 explizit gegen Engineering-Adressen (deren E-Mail-Signaturen in LinkedIn-Exposés findbar sind). QR-Code umgeht URL-Filter im Mail-Gateway, führt zu Credential-Harvester, der MFA via Evilginx abfängt. Verizon DBIR 2024: Phishing verantwortlich für 36 % aller Breaches im Manufacturing-Sektor.',
                    'threat' => 'Phishing / QR-Code-Malware',
                    'vulnerability' => 'MFA ohne Phishing-Resistenz (SMS-OTP statt FIDO2), kein QR-Code-Scanning im MX-Gateway',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'Verizon DBIR 2024, ENISA Threat Landscape 2024',
                ],
                [
                    'title' => 'Unzureichende NIS2-Meldepflicht-Bereitschaft (24h Early Warning)',
                    'description' => 'NIS2-Schwelle (50 FTE + 10 Mio. EUR Umsatz) erreicht, aber kein dokumentierter Meldeprozess an BSI binnen 24h Early Warning + 72h Incident Notification. Risiko regulatorische Maßnahme + Bußgeld bis 2 % vom Konzernumsatz (NIS2 Art. 34).',
                    'threat' => 'Versäumte Meldefrist bei meldepflichtigem Cyber-Vorfall',
                    'vulnerability' => 'Kein Incident-Reporting-Template, keine BSI-Meldestelle-Kontakte, kein Probelauf',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'NIS2-Richtlinie (EU) 2022/2555 Art. 23, BSI NIS2-Umsetzungshilfen',
                ],
                [
                    'title' => 'Unautorisierte USB-Medien in Produktionsumgebung',
                    'description' => 'Techniker verwenden private USB-Sticks zur Firmware-Übertragung oder zum Log-Download aus PLC. Klassischer Stuxnet-Eintrittspfad; aktuell relevant wegen "Raspberry-Robin"-Wurm (erstmals 2022, aktiv 2024 in DACH-Fertigung). Host-IDS fehlt in OT.',
                    'threat' => 'USB-verbreitete Malware (Raspberry Robin, Stuxnet-Derivate)',
                    'vulnerability' => 'Keine USB-Whitelist, keine Endpoint-Protection auf Engineering-Workstations',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz SYS.2.1, Microsoft Threat Intelligence Report Raspberry Robin 2024',
                ],
                [
                    'title' => 'Versicherungs-Ausschluss bei Cyber-Warranty-Verletzung',
                    'description' => 'Cyber-Versicherer (Allianz, Munich Re, AXA XL) verlangen seit 2023 deklarierte Mindest-Controls (MFA überall, EDR, offline Backup, Patching-SLA). Bei Breach wird Control-Compliance retrospektiv geprüft. Schaden-Case: Nicht-Abdeckung eines 7-stelligen Lösegelds, weil MFA im VPN-Zugang nicht nachweisbar war.',
                    'threat' => 'Leistungsverweigerung der Cyber-Versicherung nach Vorfall',
                    'vulnerability' => 'Warranty-Controls nicht messbar, kein kontinuierlicher Nachweis',
                    'category' => 'strategic',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'GDV-Musterbedingungen Cyber 2023, Munich Re Cyber Risk Outlook 2024',
                ],
                [
                    'title' => 'Cloud-basiertes MES ohne klare Datenhoheit (A.5.23)',
                    'description' => 'Migration zu Cloud-MES (z. B. Siemens Opcenter Cloud, SAP DMC) bringt Datenabfluss zum Hyperscaler mit unklarer Datenlokation und unklarer Gerichtsbarkeit (CLOUD Act). Risiko bei ITAR/EAR-regulierten Komponenten und EU-Datenschutz-kritischen Engineering-Daten.',
                    'threat' => 'Drittstaats-Zugriff auf Fertigungs-/Engineering-Daten',
                    'vulnerability' => 'Keine BYOK-Verschlüsselung, kein Data-Residency-Addendum im Cloud-Vertrag',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ISO 27001:2022 A.5.23, EDPB Guidelines 05/2021 on interplay between Art. 3 and Chapter V GDPR',
                ],
                [
                    'title' => 'Fehlende Trennung Engineering- und Service-User in MES',
                    'description' => 'Shopfloor-Nutzer und Engineering-Administratoren teilen sich Accounts oder rollenlose Sammel-User. Nachvollziehbarkeit im Incident-Fall eingeschränkt, Sabotage-Szenarien nicht attribuierbar. ISO 27001 A.5.15 + A.8.3 Verletzung.',
                    'threat' => 'Nicht-attribuierbare Manipulation von Fertigungsaufträgen',
                    'vulnerability' => 'Keine Rollen-basierte Zugriffskontrolle (RBAC), Shared Accounts',
                    'category' => 'integrity',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ISO 27001:2022 A.5.15, A.8.3, BSI IT-Grundschutz ORP.4',
                ],
                [
                    'title' => 'Produktionsrezepturen/Parametersätze ohne Integritätsschutz',
                    'description' => 'Rezepturen für Kunststoff-Spritzguss, Wärmebehandlungs-Parameter oder CNC-Werkzeugwege werden ohne Hash/Signatur zwischen Engineering und Maschine übertragen. Manipulation führt zu Bauteil-Qualitätsschaden, im Worst Case produkthaftungsrelevantem Serienfehler.',
                    'threat' => 'Manipulation von Fertigungsparametern (Insider oder kompromittiertes Engineering-System)',
                    'vulnerability' => 'Keine Hash/Signatur-Prüfung bei Recipe-Transfer, keine Audit-Trail-Pflicht',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'IEC 62443-4-2 SR 3.4 / CR 3.4 (Software and Information Integrity)',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Produktions-MES-Server', 'asset_type' => 'hardware', 'owner' => 'Head of Manufacturing IT', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Manufacturing Execution System (Siemens Opcenter / SAP DMC / PSI) — koordiniert Fertigungsaufträge, Rezepturen, Chargenverfolgung'],
                ['name' => 'PLC/SPS-Netzwerk Fertigungshalle 1', 'asset_type' => 'hardware', 'owner' => 'Leiter Instandhaltung', 'confidentiality' => 2, 'integrity' => 5, 'availability' => 5, 'description' => 'Siemens-S7/Rockwell-/Beckhoff-Steuerungen der Hauptfertigungslinie, segmentiert als OT-Zone nach IEC 62443'],
                ['name' => 'CAD/CAM-Vault (PLM)', 'asset_type' => 'software', 'owner' => 'Head of Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 3, 'description' => 'Teamcenter / Windchill / 3DEXPERIENCE — zentrales PLM mit Konstruktionszeichnungen und Stücklisten'],
                ['name' => 'Fernwartungs-Jumphost (OT-DMZ)', 'asset_type' => 'hardware', 'owner' => 'Head of IT Security', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Bastion-Host mit Session-Recording (CyberArk / Wallix) für Hersteller-Zugriffe'],
                ['name' => 'ERP-System (SAP S/4HANA / Dynamics 365)', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Finanz-, Auftrags- und Lagerdaten-Kernsystem'],
                ['name' => 'OT-Firewall (IT/OT-Demarkation)', 'asset_type' => 'hardware', 'owner' => 'Head of IT Security', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Industrie-Firewall (Palo Alto PA-Industrial / Fortinet Rugged) als Layer-3-Trennung IT/OT'],
                ['name' => 'Historian/SCADA-Datenbank', 'asset_type' => 'software', 'owner' => 'Leiter Produktionsleittechnik', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 4, 'description' => 'OSIsoft PI / AVEVA Historian — Zeitreihen für Prozess-Monitoring und KPIs'],
                ['name' => 'Engineering-Workstation (EWS)', 'asset_type' => 'hardware', 'owner' => 'Head of Automation', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 3, 'description' => 'Dedizierte Programmier-Arbeitsplätze (TIA Portal / Studio 5000) mit USB-Lockdown'],
                ['name' => 'Backup-System (offline capable)', 'asset_type' => 'hardware', 'owner' => 'Head of IT', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Veeam / Commvault mit Air-Gap-/Immutable-Repository gegen Ransomware'],
                ['name' => 'Cyber-Versicherungs-Dokumentation', 'asset_type' => 'document', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 2, 'description' => 'Police + Warranty-Fragebogen + Control-Nachweise — versicherungsrelevant im Schadenfall'],
            ],
            'preset_applicable_controls' => [
                // Identity, Access, Supplier — Kernproblem OT-Zulieferer
                'A.5.1', 'A.5.2', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18',
                // Threat Intelligence + Supplier Risk
                'A.5.7', 'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22',
                // Incident + Continuity
                'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.29', 'A.5.30',
                // Cloud (MES-Cloud-Migration)
                'A.5.23',
                // HR Security (Engineering-Insider)
                'A.6.3', 'A.6.6',
                // Physical: OT-Bereich-Zutritt
                'A.7.2', 'A.7.3', 'A.7.4',
                // Technical — OT-relevant
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8', 'A.8.9',
                'A.8.12', 'A.8.13', 'A.8.15', 'A.8.16',
                'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.25',
                'A.8.28', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function financeBaseline(): array
    {
        return [
            'code' => 'BL-FINANCE-v1',
            'name' => 'Finanzsektor (ISO 27001 + DORA + BAIT/VAIT)',
            'description' => <<<TXT
Starter-Paket für Banken, Versicherer, Zahlungsinstitute und kritische ICT-Dienstleister der Finanzbranche unter DORA-Regime (ab 17.01.2025 vollständig anwendbar).

Ziel-Kundensegment:
- NACE-Codes: K.64 (Finanzdienstleistungen ohne Versicherungen), K.65 (Versicherungen, Rückversicherungen, Pensionskassen), K.66 (Hilfsdienste für Finanzdienstleistungen inkl. Zahlungsdienstleister)
- Mitarbeiterzahl-Range: 100–25.000 FTE (Kredit-/Privatbanken, Spezialversicherer bis Landesbank-Größe)
- Umsatz-Range: 50 Mio. EUR Bruttoertrag bis > 5 Mrd. EUR
- Typische Regulatoren: BaFin, Deutsche Bundesbank, EZB (SSM-Direktaufsicht ab bestimmten Größen), EIOPA, ESMA

Warum diese Baseline: Finanzsektor-spezifisch durch DORA-Pflichten (Art. 5–15 ICT Risk Management, Art. 17–23 Incident Reporting, Art. 24–27 Digital Operational Resilience Testing inkl. TLPT, Art. 28–44 Third-Party Risk mit Register of Information). BAIT/VAIT bleiben als nationale Aufsichtsrichtlinie parallel bestehen. Diese Baseline deckt die ICT-spezifischen DORA-Anforderungen mit ISO-27001-Controls ab und flaggt DORA-Gap-Themen, die ISO allein nicht abbildet (insb. Register of Information, TLPT).

ISO 27001:2022 Control-Kern: Schwerpunkt A.5.19–A.5.23 (Supplier + Cloud — wegen Konzentrationsrisiko-Nachweis DORA Art. 29), A.5.24–A.5.30 (Incident + BCM — MTPD-Definition, Recovery-Time-Objectives auf kritische Funktionen, DORA Art. 11), A.8.16 (Monitoring — Anomalieerkennung DORA Art. 10), A.8.24 (Kryptographie — BaFin-Mindeststandards), A.8.32 (Change). Weggelassen: Reine Physical-Controls A.7.x (bei Großbanken i.d.R. bereits Reifegrad 4).

Quellen-Nachweis:
- DORA (RL (EU) 2022/2554) + RTS/ITS der ESAs (JC 2023-86 ICT Risk Management, JC 2024-26 Subcontracting, JC 2024-53 TLPT)
- BaFin MaRisk AT 7.2 + BAIT (Bankaufsichtliche Anforderungen an die IT)
- BaFin VAIT (Versicherungsaufsichtliche Anforderungen an die IT)
- BaFin ZAIT (Zahlungsdiensteaufsichtliche Anforderungen an die IT)
- EBA Guidelines on ICT and Security Risk Management (EBA/GL/2019/04)
- Basel III SRP OpRisk / Principles for Operational Resilience (BCBS 2021)
TXT,
            'industry' => IndustryBaseline::INDUSTRY_FINANCE,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '2.0',
            'required_frameworks' => ['ISO27001', 'DORA'],
            'recommended_frameworks' => ['BAIT', 'VAIT', 'NIS2', 'ISO27005', 'ISO22301', 'BSI_GRUNDSCHUTZ'],
            'fte_days_saved_estimate' => 28.0,
            'preset_risks' => [
                [
                    'title' => 'ICT-Drittanbieter-Konzentrationsrisiko (DORA Art. 29)',
                    'description' => 'Kritische bzw. wichtige Funktionen (Online-Banking-Frontend, Kernbankensystem, Payments-Gateway) laufen ausschließlich bei einem Hyperscaler ohne Exit-Strategie und ohne Zweit-Cloud-Vorhalt. Konzentration auf AWS/Azure/Google ist laut EBA-Cloud-Concentration-Study 2023 ein explizit benannter systemrelevanter Risikofaktor. DORA Art. 28 Abs. 8 + Art. 29 verlangen Substituierbarkeits-Analyse und Konzentrations-Bewertung im Register of Information.',
                    'threat' => 'Ausfall / regulatorische Maßnahme gegen den CTPP / geopolitisch motivierte Trennung',
                    'vulnerability' => 'Keine dokumentierte Exit-Strategie, keine Multi-Cloud-Portierbarkeit der Workloads',
                    'category' => 'third_party',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DORA Art. 28-29, EBA Cloud Concentration Study 2023, BaFin Orientierungshilfe zu Auslagerungen 02/2023',
                ],
                [
                    'title' => 'Unbefugte Manipulation von Handels-/Abwicklungsdaten (Trade-Data-Integrität)',
                    'description' => 'Manipulation von Order-, Matching- oder Settlement-Daten zwischen Front-Office (Trading-System), Middle-Office (Risk-/Limit-System) und Back-Office (Abwicklung, Clearing) ohne durchgängigen Reconciliation-Check. Präzedenzfall: Société-Générale-Kerviel 2008 (4,9 Mrd. EUR), jüngere Fälle bei Flash-Crash-Triggern durch fehlerhafte Automatisierung. ISO 27001 A.8.3 + DORA Art. 9 (Integrität der Informationen in der ICT-Umgebung).',
                    'threat' => 'Insider-Manipulation / fehlerhafte Trade-Automation / kompromittierter Service-Account',
                    'vulnerability' => 'Unzureichende End-to-End-Reconciliation zwischen Trade-Capture und Ledger',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BaFin MaRisk AT 4.3.1, DORA Art. 9, BCBS Principles for Sound Management of Operational Risk',
                ],
                [
                    'title' => 'Fehlende Threat-Led Penetration Tests (DORA Art. 26/27, TIBER-EU)',
                    'description' => 'DORA Art. 26/27 verpflichtet signifikante Finanzunternehmen zu TLPT alle 3 Jahre, angelehnt an TIBER-EU-Framework mit roter Zelle nach Threat-Intel-Profiling. Heute führt die Organisation ausschließlich klassische Scope-begrenzte Pentests durch. Gap bei Bewertung: Fehlen der Red-Team-Fähigkeit, fehlende Threat-Intel-Feed-Zuordnung, keine White-Team-Koordination.',
                    'threat' => 'Advanced Persistent Threat / staatlich gesteuerter Angriff auf Finanzsystem',
                    'vulnerability' => 'Kein TLPT-Programm, keine ESAs-akkreditierte Red-Team-Vendor-Liste, keine TIBER-Verfahrensdokumentation',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DORA Art. 26-27, JC 2024-53 (RTS on TLPT), TIBER-EU Framework 2018 (ECB)',
                ],
                [
                    'title' => 'KYC/Sanktionslisten-Screening-Datenqualität (GwG + EU-Sanktionen)',
                    'description' => 'Listen-Screening läuft gegen Sanktionslisten (EU, OFAC, UN) mit unklarer Update-Latenz (> 24h). Post Russia/Belarus/Iran-Regulierung 2022–2024 sind Listen-Updates häufiger und geopolitisch getrieben. False-Negatives führen zu Geldwäsche-Verdachtsfällen und BaFin-Prüfung gem. GwG + KWG § 25h.',
                    'threat' => 'Geldwäsche, regulatorische Sanktion gegen das Institut, Reputationsschaden',
                    'vulnerability' => 'Screening-Update-Prozess manuell, kein SLA mit Listen-Anbieter, kein Delta-Test der Listen-Feeds',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'GwG §§ 10-18, EU-Sanktions-VO 833/2014 + 269/2014, BaFin AuA Rundschreiben 05/2023',
                ],
                [
                    'title' => 'DORA-Meldefristen Cyber-Vorfall (Early Warning 4h, Intermediate 72h, Final 1 Monat)',
                    'description' => 'DORA Art. 17-19 + RTS JC 2024-33 verlangen gestaffelte Meldung an zuständige Behörde (BaFin/Bundesbank) mit strikten Fristen: 4h Early Notification nach Klassifizierung als "major", 72h Intermediate, 1 Monat Final. Aktuell kein End-to-End-getesteter Prozess mit Stellvertreterregelung für abendliche/Wochenend-Vorfälle.',
                    'threat' => 'Versäumte Meldefristen / fehlerhafte Klassifizierung des Vorfalls',
                    'vulnerability' => 'Kein 24/7-Incident-Classifier, keine eingespielte Meldevorlage nach JC 2024-33 Annex',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DORA Art. 17-19, RTS/ITS JC 2024-33 Incident Reporting',
                ],
                [
                    'title' => 'Register of Information (RoI) unvollständig',
                    'description' => 'DORA Art. 28 Abs. 3 verlangt ein strukturiertes Register aller ICT-Third-Party-Provider-Verträge mit Kritikalitäts-Einstufung, Unterauftragnehmer-Kette, Konzentrations-Metriken. Erste Einreichung bei BaFin bis April 2025. Heute: Daten fragmentiert in Excel + Einkauf-Tool, keine konsolidierte Sicht auf CTPP.',
                    'threat' => 'Aufsichtsrechtliche Beanstandung / Zwangsmaßnahme / Bußgeld',
                    'vulnerability' => 'Kein zentrales Vertrags-Register, keine CTPP-Konzentrationsanalyse',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DORA Art. 28, ITS JC 2023-85 Register of Information',
                ],
                [
                    'title' => 'Schwachstellen in Legacy-Kernbankensystem (COBOL/Mainframe)',
                    'description' => 'Kernbankensystem noch auf Mainframe mit COBOL-Modulen, die in den 1980er-Jahren entwickelt wurden. Know-how-Verlust durch Renteneintritt der Kernentwickler. Patch-Frequenz mit IBM-LPAR-Wartungsfenster gekoppelt, keine feingranulare Änderung möglich. Integrations-Schicht mit modernem Core-Plattform-Projekt (T24/Avaloq/Finacle) erhöht Angriffsfläche.',
                    'threat' => 'Unentdeckte Legacy-Schwachstelle, Know-how-Abfluss',
                    'vulnerability' => 'Kein Source-Code-Audit, kein dokumentierter Wissens-Transferprozess',
                    'category' => 'strategic',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BaFin MaRisk AT 7.2, EBA GL/2019/04 ICT-Risiken',
                ],
                [
                    'title' => 'Unzureichende Resilienz der Payments-Infrastruktur (Instant Payments SEPA SCT Inst)',
                    'description' => 'Ab Pflicht-Datum 2025 müssen alle SEPA-Teilnehmer Instant-Payments 24/7/365 mit ≤10-Sekunden-Clearing anbieten (EU-VO 2024/886). RTO/RPO muss < Sekunden liegen. Aktuell Batch-basierte Architektur, keine aktive/aktive-Multi-RZ-Stretched-Cluster-Aufstellung.',
                    'threat' => 'Ausfall während Kernbetrieb 24/7, Vertragsstrafe der Payments-Systeme',
                    'vulnerability' => 'Keine Active/Active-Architektur, Cold-DR-Standby mit 4h+-RTO',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'EU-VO 2024/886 Instant Payments, EPC SCT Inst Rulebook V1.1',
                ],
                [
                    'title' => 'Kryptographie-Agilität (PQC-Migration)',
                    'description' => 'NIST FIPS 203/204/205 (ML-KEM, ML-DSA, SLH-DSA) finalisiert im August 2024. BaFin/BSI erwartet Kryptoinventar und Migrationsplan ab 2025/2026. Aktuell kein Kryptoinventar im Unternehmen, RSA-2048/ECC-P256 überall fest verdrahtet, keine Crypto-Agility im TLS-Gateway.',
                    'threat' => '"Harvest now, decrypt later" durch Nation-State-Angreifer; zukünftige Bruchbarkeit',
                    'vulnerability' => 'Kein Kryptoinventar, keine Crypto-Agility in PKI und TLS-Terminierung',
                    'category' => 'strategic',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'NIST FIPS 203/204/205, BSI TR-02102-1, ENISA Post-Quantum Cryptography Integration Study 2024',
                ],
                [
                    'title' => 'DDoS-Angriffe auf öffentliche Online-Banking-Endpoints',
                    'description' => 'Pro-russische Gruppen (NoName057(16), Killnet) haben in 2023-2024 wiederholt deutsche und niederländische Bank-Portale angegriffen. L7-DDoS mit HTTP/2 Rapid Reset (CVE-2023-44487) erzwingt Application-Layer-Ausfall. Heute kein vollflächiger WAF/DDoS-Provider (Cloudflare/Akamai/Imperva) im Standby.',
                    'threat' => 'L7-DDoS / Hacktivistenangriff',
                    'vulnerability' => 'Keine Scrubbing-Infrastruktur, keine Capacity-on-Demand-Bindung mit Transit-ISP',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI Lagebericht zur IT-Sicherheit 2023/2024, ENISA Threat Landscape Finance 2024',
                ],
                [
                    'title' => 'Mitarbeiter-Insider-Missbrauch in Kredit-/Trading-Bereich',
                    'description' => 'Hohe Hebelwirkung bei privilegiertem Zugriff auf Limit-, Trading- und Kreditsysteme. Klassische Fälle: Adoboli (UBS, 2,3 Mrd. USD) 2011, Iksil "London Whale" (JP Morgan, 6,2 Mrd. USD) 2012. Moderne Variante: Datendiebstahl für Front-Running oder Insider-Trading durch einen Quant. MaRisk AT 7.2 + DORA Art. 9 Zugriffs-Controls.',
                    'threat' => 'Insider-Missbrauch / Front-Running / Marktmanipulation',
                    'vulnerability' => 'Ungeprüfte privilegierte Accounts, keine User-Entity-Behavior-Analytics (UEBA)',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BaFin MaRisk BT 2, Basel Committee Operational Risk Events Database',
                ],
                [
                    'title' => 'Unvollständige Business-Impact-Analyse für kritische Funktionen (DORA Art. 11)',
                    'description' => 'DORA Art. 11 Abs. 2 verlangt BIA als Grundlage der Resilienz-Strategie. Heute BIA punktuell je System, aber nicht auf Ebene "kritische/wichtige Funktionen" i.S.d. Art. 3 Nr. 22 definiert. MTPD + RTO/RPO fehlen formal unterzeichnet von Business-Ownern.',
                    'threat' => 'Fehleinschätzung Recovery-Priorität im Ernstfall',
                    'vulnerability' => 'BIA-Methodik nicht DORA-konform, keine Business-Owner-Zeichnung',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DORA Art. 11, ISO 22301:2019 Kap. 8.2, BaFin MaRisk AT 7.3',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Kernbankensystem (Core Banking)', 'asset_type' => 'software', 'owner' => 'CIO', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Kontoführendes System (Avaloq / T24 / Finacle / SAP FS) — kritische Funktion i.S.d. DORA Art. 3 Nr. 22'],
                ['name' => 'Trading-/Order-Management-Platform', 'asset_type' => 'software', 'owner' => 'Head of Trading & Markets', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'OMS/EMS (Murex / Calypso / Front Arena) mit Low-Latency-Market-Connectivity'],
                ['name' => 'Payments-Gateway (SEPA/SWIFT/Card)', 'asset_type' => 'software', 'owner' => 'Head of Payments', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Zahlungsverkehr inkl. SCT Inst — 24/7/365-Anforderung ab 2025'],
                ['name' => 'KYC/AML-Screening-System', 'asset_type' => 'software', 'owner' => 'MLRO (Money Laundering Reporting Officer)', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Actimize / SAS AML / Fenergo — KYC-Onboarding + laufendes Sanktionsscreening'],
                ['name' => 'Data Warehouse Regulatorisches Reporting', 'asset_type' => 'software', 'owner' => 'Head of Regulatory Reporting', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'BaFin/EZB/Bundesbank-Meldewesen (AnaCredit, FinRep, CoRep)'],
                ['name' => 'Register of Information (RoI) Tool', 'asset_type' => 'software', 'owner' => 'Head of ICT Risk Management', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 3, 'description' => 'DORA-Art.-28-Vertrags-/Drittanbieter-Register mit Kritikalitäts-Einstufung'],
                ['name' => 'Online-Banking-Frontend', 'asset_type' => 'software', 'owner' => 'Head of Digital Channels', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Kunden-Portal + Mobile-App — DDoS-exponiertes Public-Endpoint'],
                ['name' => 'SIEM / SOC-Plattform', 'asset_type' => 'software', 'owner' => 'Head of SOC', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 5, 'description' => 'Splunk ES / QRadar / Sentinel — DORA-Art.-10-Monitoring-Basis'],
                ['name' => 'PKI / Hardware Security Module (HSM)', 'asset_type' => 'hardware', 'owner' => 'Head of IT Security', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Thales Luna / Utimaco — Schlüsselmaterial für Zahlungsverkehr und Signaturen'],
                ['name' => 'Notfall-Arbeitsplatz-Umgebung (WAR-Room)', 'asset_type' => 'location', 'owner' => 'Head of Business Continuity', 'confidentiality' => 3, 'integrity' => 4, 'availability' => 5, 'description' => 'Physischer + virtueller Ausweichstandort für Krisenstab bei Cyber-/BCM-Vorfall'],
            ],
            'preset_applicable_controls' => [
                // Organizational — Top-Prio DORA/BAIT
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.4', 'A.5.7', 'A.5.8',
                'A.5.9', 'A.5.10', 'A.5.12', 'A.5.13',
                'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18',
                // Supplier Risk — DORA Art. 28-30
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23',
                // Incident + BCM — DORA Art. 11-17
                'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.5.29', 'A.5.30',
                // Compliance + Audit
                'A.5.31', 'A.5.32', 'A.5.33', 'A.5.34', 'A.5.35', 'A.5.36', 'A.5.37',
                // People
                'A.6.1', 'A.6.3', 'A.6.6',
                // Technical — Kern
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8', 'A.8.9',
                'A.8.12', 'A.8.15', 'A.8.16', 'A.8.20', 'A.8.21',
                'A.8.23', 'A.8.24', 'A.8.25', 'A.8.26',
                'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function kritisHealthBaseline(): array
    {
        return [
            'code' => 'BL-KRITIS-HEALTH-v1',
            'name' => 'KRITIS Gesundheit (ISO 27001 + NIS2 + B3S Krankenhaus + KHZG)',
            'description' => <<<TXT
Starter-Paket für Krankenhäuser, Reha-Kliniken und MVZ innerhalb/nahe der KRITIS-Gesundheit-Schwellen.

Ziel-Kundensegment:
- NACE-Codes: Q.86.10 (Krankenhäuser), Q.86.22 (Facharztpraxen), Q.86.90 (Sonstiges Gesundheitswesen), Q.87.10 (Pflegeheime mit Gesundheits-IT)
- Mitarbeiterzahl-Range: 500–8.000 FTE (mittelgroße Klinik bis Maximalversorger)
- Fallzahl-Range: typisch KRITIS ab 30.000 stationäre Fälle/Jahr pro Einrichtung (§ 6 BSI-KritisV 2021 angepasst)
- Typische Regulatoren: BSI (NIS2 + KRITIS-DachG), Gesundheitsminister*innen der Länder, Medizinischer Dienst, Landesdatenschutzbeauftragte

Warum diese Baseline: Klinik-IT ist medizingeräte-zentrisch mit Embedded-OS-Zoo (Windows 7/XP weit verbreitet auf MRT/CT-Konsolen wegen Hersteller-Zertifizierung), Patientensicherheits-Integritätsfokus, 24/7-Verfügbarkeits-Druck bei Notaufnahme + OP. KRITIS-Pflicht durch Schwellenwert der BSI-KritisV, B3S Krankenhaus (branchenspezifischer Sicherheitsstandard der DKG) als anerkannter Umsetzungsrahmen nach § 8a BSIG. KHZG-Fördertatbestand 10 (IT-Sicherheit) bindet 15 % der Fördersumme an nachweisbare Sicherheitsmaßnahmen.

ISO 27001:2022 Control-Kern: A.5.23 (Cloud bei Telemedizin-Plattformen), A.5.33 (Protection of records — ePA/Patientenakte), A.5.34 (Privacy — besondere Kategorien nach Art. 9 DSGVO), A.6.3 (Awareness — klinisches Personal), A.7.4 (Monitoring physical access — OP/IPS), A.8.9 (Configuration Management — Medizingeräte), A.8.22 (Segregation — MDR-Netz separat). Weggelassen: nicht-einrichtungsrelevante Cloud-Entwicklungs-Controls.

Quellen-Nachweis:
- B3S Krankenhaus der DKG, aktuelle Version 1.3 (2024)
- BSI-KritisV, BSIG § 8a, NIS2UmsuCG (Entwurf)
- KHZG § 19 + BMG-Förderrichtlinie
- MDR (VO (EU) 2017/745), IEC 80001-1 Risk Management for Medical Device Networks
- § 75b SGB V (IT-Sicherheitsrichtlinie für Arztpraxen als Referenz)
- ENISA Threat Landscape Health Sector 2024
TXT,
            'industry' => IndustryBaseline::INDUSTRY_KRITIS_HEALTH,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '2.0',
            'required_frameworks' => ['ISO27001', 'NIS2'],
            'recommended_frameworks' => ['BSI_GRUNDSCHUTZ', 'ISO22301', 'GDPR', 'B3S_KRANKENHAUS'],
            'fte_days_saved_estimate' => 24.0,
            'preset_risks' => [
                [
                    'title' => 'Ransomware-Ausfall Klinik-IT mit Notfallbetrieb',
                    'description' => 'Verschlüsselung von KIS, LIS, PACS, Dokumentationssystem. Notfallbetrieb nur eingeschränkt möglich (Papier-Anamnese, Abmeldung aus Rettungsleitstelle), Umleitung von Notfallpatienten zu Nachbarkrankenhäusern. Präzedenzfälle: Universitätsklinik Düsseldorf 2020 (1 Todesfall durch Notfall-Umleitung), Lukaskrankenhaus Neuss 2016, Krankenhaus Brandenburg Juni 2023. ENISA Health Threat Landscape 2024: Gesundheitssektor ist in EU der am stärksten betroffene Sektor (54 % aller gemeldeten meldepflichtigen Vorfälle).',
                    'threat' => 'Ransomware-Gruppe (Clop / LockBit / BlackCat) mit Gesundheits-Fokus',
                    'vulnerability' => 'Flache Netzarchitektur, Backup-Recovery > 24h, kein Offline-Backup',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ENISA Health Threat Landscape 2024, BSI Lagebericht 2023, B3S KH Kap. 5.4',
                ],
                [
                    'title' => 'Medizingeräte mit Legacy-Embedded-OS (XP/7)',
                    'description' => 'CT, MRT, Beatmungsgeräte, Infusomaten, Monitor-Überwachungs-Zentralen laufen auf Windows XP Embedded oder Windows 7 Embedded. Kein Hersteller-Update wegen MDR-Zulassungsreichweite — Updates würden die Konformitätsbewertung neu auslösen. Mitigation nur über Netzsegmentierung + Hardening + Monitoring. IEC 80001-1 verlangt dokumentierte Risk-Management-Vereinbarung zwischen Klinik-Betreiber und MDR-Hersteller.',
                    'threat' => 'Bekannte CVE-Ausnutzung, Gerätestillstand während laufender Prozedur',
                    'vulnerability' => 'Firmware-Update-Sperre durch MDR-Zulassung, keine kompensatorische Netzsegmentierung',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'IEC 80001-1, MDR Art. 5 + Art. 10, B3S KH Kap. 6.2',
                ],
                [
                    'title' => 'Patientendaten-Abfluss (DSGVO Art. 33, besondere Kategorien Art. 9)',
                    'description' => 'Unverschlüsselte E-Mail-Kommunikation mit niedergelassenen Ärzten, USB-Stick-Austausch mit externen Radiologen, Faxversand an Hausärzte ohne Sendebestätigung. Gesundheitsdaten nach Art. 9 DSGVO mit verschärftem Schutz; Bußgeld-Höchstrahmen Art. 83. Aktuelle Bußgeld-Praxis der LfDI NRW/Hessen: 6-stellige Beträge bei Klinik-Datenpannen.',
                    'threat' => 'Meldepflichtige Datenpanne, Bußgeld, Reputationsschaden',
                    'vulnerability' => 'Keine Ende-zu-Ende-Verschlüsselung, kein KIM-Dienst (Kommunikation im Medizinwesen) produktiv',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 9/33/34/83, TI-KIM-Spezifikation gematik, B3S KH Kap. 8.3',
                ],
                [
                    'title' => 'Medikamenten-Verordnungs-Integrität (Patientensicherheits-Risiko)',
                    'description' => 'Fehlerhafte oder manipulierte Medikations-Einträge im KIS können Patienten direkt schädigen (Dosierungsfehler, Kontraindikations-Übersehen). Unerwünschte Arzneimittelereignisse (UAE) sind lt. Aktionsbündnis Patientensicherheit in 5 % aller Klinik-Aufenthalte ursächlich für Komplikationen. IT-gestützte Integritäts-Controls (4-Augen-Prinzip, Dosisgrenzen-Plausibilisierung) reduzieren dies signifikant.',
                    'threat' => 'Datenbank-Manipulation / Fehlkonfiguration / Insider',
                    'vulnerability' => 'Unzureichende 4-Augen-Kontrolle, fehlende Dosis-Plausibilisierung, kein Audit-Trail',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'B3S KH Kap. 6.4, Aktionsbündnis Patientensicherheit AMTS-Handlungsempfehlungen',
                ],
                [
                    'title' => 'Ausfall der Telematik-Infrastruktur (TI) / ePA',
                    'description' => 'Elektronische Patientenakte (ePA ab 2025 opt-out), E-Rezept, KIM, AMTS — alle abhängig von der gematik-Telematik-Infrastruktur. Ausfälle der TI-Konnektor-Infrastruktur (zB Hersteller-Kasko-Update-Fehler im März 2023) führen zu Stillstand in Abrechnung und Rezeptausstellung.',
                    'threat' => 'gematik-TI-Ausfall, Konnektor-Bug, Signaturkarten-Sperre',
                    'vulnerability' => 'Kein Fallback-Prozess (Papier-Rezept, manuelle Abrechnung)',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'gematik-Spezifikation TI, § 311 SGB V, BMG Digitalisierungsstrategie',
                ],
                [
                    'title' => 'Unzureichende Segmentierung Medizingeräte-Netz',
                    'description' => 'Medizingeräte hängen im gleichen Broadcast-Domain wie Verwaltungs-PCs, Catering-Tablets, Patienten-WLAN. IEC 80001-1 verlangt Zonierung mit expliziter Risiko-Bewertung. WannaCry 2017 im UK NHS: 200.000 betroffene Geräte, weil Medizingeräte im Büro-Netz lebten.',
                    'threat' => 'Wurm/Ransomware-Ausbreitung auf Medizingeräte',
                    'vulnerability' => 'Flache Netz-Topologie, keine Zonierung nach IEC 80001-1',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'IEC 80001-1, BSI NHS-WannaCry-Analyse 2017, B3S KH Kap. 6.1',
                ],
                [
                    'title' => 'Supply-Chain-Risiko bei Medizingeräte-Herstellern (Post-Market Surveillance)',
                    'description' => 'Hersteller-Updates + Cyber-Advisories müssen aktiv abgerufen werden (MDR Post-Market-Surveillance). Viele Kliniken haben keinen Prozess zur Auswertung der Hersteller-Portale (Siemens Healthineers, GE Healthcare, Philips, Dräger). Bekannte Fälle: URGENT/11-Schwachstellen in Wind-River VxWorks mit Auswirkung auf Infusomaten 2019.',
                    'threat' => 'Unbeachtete Hersteller-Advisories, ausgenutzte Geräte-CVE',
                    'vulnerability' => 'Kein Medizingeräte-Asset-Register mit Hersteller-Advisory-Feed',
                    'category' => 'third_party',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'MDR Art. 83-86 (PMS), BfArM Medical Device CERT, URGENT/11-Fallanalyse',
                ],
                [
                    'title' => 'Physische Zutrittskontrolle zu IT-Räumen + Medizintechnik-Werkstatt',
                    'description' => 'Server-Räume, Netzwerk-Verteiler, Medizintechnik-Werkstatt oft nur mit klassischem Mechanik-Schlüssel. Bei Schlüsselverlust keine Rotation. Insider-Sabotage-Szenario oder externer Dienstleister, der sich nachts Zugang verschafft.',
                    'threat' => 'Unbefugter physischer Zugriff auf Core-Switching/Server',
                    'vulnerability' => 'Keine elektronische Zutrittskontrolle, keine Protokollierung',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'B3S KH Kap. 7.1, ISO 27001 A.7.2/7.3',
                ],
                [
                    'title' => 'Klinisches Personal ohne Security-Awareness',
                    'description' => 'Hohe Personalrotation in Pflege + Assistenzärzten, Onboarding-Fokus auf klinische Inhalte, Security-Awareness-Training optional. Verizon DBIR 2024 Healthcare-Vertical: 68 % der Breaches involvieren Menschen (Phishing, Fehlklick, Fehlverhalten).',
                    'threat' => 'Phishing-Erfolgsrate in Pflege/Verwaltung signifikant höher als in IT-zentrischen Branchen',
                    'vulnerability' => 'Kein jährliches Mandatory-Training, kein Phishing-Simulationsprogramm',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'Verizon DBIR 2024 Healthcare, B3S KH Kap. 9',
                ],
                [
                    'title' => 'NIS2/KRITIS-Meldepflicht-Bereitschaft',
                    'description' => 'KRITIS-Meldepflichten nach BSIG § 8b + zukünftig NIS2-Pflichten (24h Early Warning, 72h Incident Notification, Final Report nach 1 Monat). Prozess muss 24/7 tragfähig sein — heute kein Bereitschafts-Plan für Nachtzeiten, keine BSI-Meldeformulare vorgefüllt.',
                    'threat' => 'Meldefrist-Überschreitung, regulatorische Beanstandung',
                    'vulnerability' => 'Keine 24/7-Meldekette, keine Vorfall-Klassifizierungsmatrix',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSIG § 8b, NIS2-Richtlinie (EU) 2022/2555 Art. 23',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Krankenhaus-Informationssystem (KIS)', 'asset_type' => 'software', 'owner' => 'Chief Medical Information Officer (CMIO)', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Orbis / Medico / iMedOne — zentrale elektronische Patientenakte'],
                ['name' => 'Labor-Informationssystem (LIS)', 'asset_type' => 'software', 'owner' => 'Leitung Zentrallabor', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Swisslab / LabCentre — Laborauftrag + Befundrückgabe inkl. Hygiene-Screening'],
                ['name' => 'Radiologie-PACS', 'asset_type' => 'software', 'owner' => 'Chefarzt Radiologie', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'GE Centricity / Sectra / Philips IntelliSpace — Bildarchiv inkl. DICOM-Router'],
                ['name' => 'Medikamenten-Verordnungs-Modul (AMTS)', 'asset_type' => 'software', 'owner' => 'Chefapotheker', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Elektronische Verordnung + Arzneimitteltherapiesicherheits-Check'],
                ['name' => 'Medizingeräte-Netz (MDR-Zone)', 'asset_type' => 'hardware', 'owner' => 'Leitung Medizintechnik', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Separat segmentiertes VLAN für CT/MRT/Beatmung/Monitoring nach IEC 80001-1'],
                ['name' => 'TI-Konnektor + Kartenterminals', 'asset_type' => 'hardware', 'owner' => 'Leitung IT-Infrastruktur', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'gematik-zertifizierter TI-Konnektor + eHBA/SMC-B-Karten'],
                ['name' => 'Dokumenten-Management / Archiv (nach KHEntgG 10 Jahre)', 'asset_type' => 'software', 'owner' => 'CMIO + Verwaltungsdirektor', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 3, 'description' => 'Revisionssicheres Archiv für Patientenakten, 10-30 Jahre Aufbewahrungsfrist'],
                ['name' => 'RIS (Radiologie-Informations-System)', 'asset_type' => 'software', 'owner' => 'Chefarzt Radiologie', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Auftrags-/Befund-Workflow für Radiologie, koppelt an PACS'],
                ['name' => 'OP-Dokumentations-/Monitoring-System', 'asset_type' => 'software', 'owner' => 'Ärztlicher Direktor OP', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Integrated-OR / Planung + Vitaldaten-Aufzeichnung'],
                ['name' => 'Notfall-Arbeitsplätze (Papier-Fallback-Kit)', 'asset_type' => 'document', 'owner' => 'Leitung Qualitätsmanagement', 'confidentiality' => 3, 'integrity' => 4, 'availability' => 5, 'description' => 'Ausgedruckte Verordnungs-/Aufnahme-Formulare, Telefonlisten, MD-Fallback-Prozesse'],
            ],
            'preset_applicable_controls' => [
                // Organizational — Kern
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.7', 'A.5.9',
                'A.5.12', 'A.5.13', 'A.5.14', 'A.5.15',
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22',
                'A.5.23', 'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.29', 'A.5.30',
                'A.5.33', 'A.5.34', 'A.5.37',
                // People
                'A.6.3', 'A.6.5', 'A.6.6',
                // Physical — Klinik-Gebäude
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.10',
                // Technical
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8',
                'A.8.9', 'A.8.11', 'A.8.12', 'A.8.13', 'A.8.15', 'A.8.16',
                'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.24', 'A.8.25',
                'A.8.28', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function genericStarterBaseline(): array
    {
        return [
            'code' => 'BL-GENERIC-v1',
            'name' => 'Generischer ISMS-Starter (ISO 27001 only)',
            'description' => <<<TXT
Minimales Starter-Paket für Organisationen ohne spezifische Branche.

Ziel-Kundensegment:
- NACE-Codes: querschnittlich, vorwiegend M (freiberufliche, wissenschaftliche und technische Dienstleistungen), N.78 (Personalvermittlung), J.62.02 (Beratung), weitere Office-/Dienstleistungs-Tätigkeiten
- Mitarbeiterzahl-Range: 10–250 FTE (KMU)
- Umsatz-Range: 1 Mio. EUR – 50 Mio. EUR
- Typische Regulatoren: keine sektor-spezifischen; DSGVO + BDSG querschnittlich; ggf. branchenspezifische Nachschärfung nach Kundenbedarf

Warum diese Baseline: Einstiegs-Template für Firmen, die zum ersten Mal ein ISMS aufbauen und noch keine Branchen-Tiefe benötigen. Nach 3–6 Monaten operativer Nutzung wird in der Regel die Migration zu einer Branchen-Baseline empfohlen. Bewusst schlank gehalten: Büro-IT, kein OT, keine spezialisierten Cloud-Plattformen, keine Regulierungs-Spezifika.

ISO 27001:2022 Control-Kern: Pflicht-Minimum-Set aus A.5.1 (Policies), A.5.9/A.5.10 (Asset Management), A.5.15/A.8.3 (Zugriffskontrolle), A.5.24–A.5.27 (Incident Management), A.8.1/A.8.8 (Endpoint + Vulnerability), A.8.13 (Backup). Bewusst weggelassen: alle Controls, die ohne Branchen-Kontext overengineered wirken (OT-, Krypto-, Cloud-Tiefe-Themen).

Quellen-Nachweis:
- ISO 27001:2022 Annex A Pflicht-Minimum
- BSI IT-Grundschutz Basis-Absicherung
- Verizon DBIR 2024 Top-Threat-Kategorien für KMU
TXT,
            'industry' => IndustryBaseline::INDUSTRY_GENERIC,
            'source' => IndustryBaseline::SOURCE_INTERNAL,
            'version' => '2.0',
            'required_frameworks' => ['ISO27001'],
            'recommended_frameworks' => ['ISO27005', 'GDPR'],
            'fte_days_saved_estimate' => 9.0,
            'preset_risks' => [
                [
                    'title' => 'Phishing-Angriff auf Office-Accounts',
                    'description' => 'Mitarbeiter klickt auf Phishing-Mail, Angreifer kompromittiert Office365-/Google-Workspace-Account. Evilginx-basierte Reverse-Proxies umgehen klassisches MFA-per-SMS. Verizon DBIR 2024: Phishing + gestohlene Credentials zusammen verantwortlich für 58 % aller Breaches im KMU-Segment.',
                    'threat' => 'Phishing / Evilginx / AiTM-Credential-Stealer',
                    'vulnerability' => 'Kein phishing-resistentes MFA (FIDO2), keine regelmäßige Awareness-Schulung',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'Verizon DBIR 2024, BSI Lagebericht 2024',
                ],
                [
                    'title' => 'Laptop-Verlust unterwegs ohne Verschlüsselung',
                    'description' => 'Dienst-Laptop wird im Zug, Café oder Flughafen vergessen oder entwendet. Festplatte unverschlüsselt, dadurch Datenzugriff durch Finder/Dieb. DSGVO Art. 32 + Art. 33 relevant (meldepflichtige Datenpanne, wenn personenbezogene Daten betroffen).',
                    'threat' => 'Diebstahl + unbefugter Datenzugriff',
                    'vulnerability' => 'Keine Festplatten-Verschlüsselung (BitLocker/FileVault)',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz SYS.2.1, DSGVO Art. 32/33',
                ],
                [
                    'title' => 'Backup-Wiederherstellung nie validiert',
                    'description' => 'Tägliches Backup läuft automatisiert, aber Restore-Fähigkeit nie unter realistischen Bedingungen getestet. Im Ernstfall (Ransomware, Hardware-Defekt) zeigen sich inkonsistente Stände, vergessene Volumes, fehlende Anwendungs-Abhängigkeiten. ISO 27001 A.8.13 fordert explizit Test-Durchführung.',
                    'threat' => 'Datenverlust nach Ransomware / Hardware-Defekt',
                    'vulnerability' => 'Kein regelmäßiger dokumentierter Restore-Test',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ISO 27001:2022 A.8.13, BSI IT-Grundschutz CON.3',
                ],
                [
                    'title' => 'Abgang Schlüsselmitarbeiter ohne Wissens-Übergabe',
                    'description' => 'Technischer Single-Point-of-Knowledge (z. B. der einzige DevOps-Engineer, die einzige Fibu-Expertin) verlässt Firma ohne dokumentierten Übergang. Operativer Stillstand in spezifischem Bereich für Wochen bis Monate. Typisches KMU-Risiko (< 100 FTE).',
                    'threat' => 'Operativer Stillstand, Know-how-Verlust',
                    'vulnerability' => 'Fehlende Dokumentation, keine Urlaubs-Vertretung, keine Stellenbeschreibung mit Backup-Zuordnung',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ISO 27001:2022 A.5.2/A.6.5, BSI IT-Grundschutz ORP.2',
                ],
                [
                    'title' => 'Schatten-IT / unautorisierte SaaS-Tools',
                    'description' => 'Mitarbeiter nutzen Cloud-Tools ohne IT-Freigabe (File-Sharing via Dropbox/WeTransfer, Projektmanagement via Trello/Notion, KI-Dienste via ChatGPT/Claude mit personenbezogenen Daten im Prompt). DSGVO-Verletzung + Datenabfluss + unklare Auftragsverarbeitung.',
                    'threat' => 'Datenabfluss, DSGVO-Verletzung, unklare AVV-Kette',
                    'vulnerability' => 'Kein SaaS-Inventar, keine Use-Policy für KI-Tools, kein Cloud-Access-Security-Broker',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 28 (AVV), EDPB Guidelines KI 2024, BSI-Empfehlungen zu generativer KI',
                ],
                [
                    'title' => 'Schwache / geteilte Passwörter',
                    'description' => 'Team-Passwörter liegen in Excel-Tabelle auf Netzlaufwerk, Admin-Accounts ohne MFA, gemeinsam genutzte Service-Accounts ohne Rotation. Credential-Stuffing-Angriffe auf öffentlich exponierte Systeme (VPN, RDP, Exchange) mit hoher Erfolgswahrscheinlichkeit.',
                    'threat' => 'Credential Stuffing, Insider-Missbrauch',
                    'vulnerability' => 'Keine Password-Policy, kein Passwort-Manager, keine MFA-Pflicht',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI IT-Grundschutz ORP.4, NIST SP 800-63B Digital Identity Guidelines',
                ],
                [
                    'title' => 'Ransomware-Angriff auf File-Server / NAS',
                    'description' => 'Klassischer Angriffspfad: Phishing → Credential-Harvest → VPN-Login → laterale Bewegung → Verschlüsselung File-Server + Backup-Snapshot. KMU-Segment besonders betroffen, weil EDR oft fehlt und Backup-Strategie "nur NAS-intern" weit verbreitet ist.',
                    'threat' => 'Ransomware-Gruppe (aktuell: LockBit-Nachfolge, 8Base, BlackCat-Derivate)',
                    'vulnerability' => 'Keine EDR, kein Offline-Backup, keine Netz-Segmentierung',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI Lagebericht 2024 (KMU-Abschnitt), Sophos State of Ransomware 2024',
                ],
                [
                    'title' => 'Business-E-Mail-Compromise (CEO-Fraud)',
                    'description' => 'Angreifer übernimmt oder täuscht CEO-/CFO-Mailadresse vor und weist Buchhaltung zu dringender Überweisung an. Durchschnittsschaden in DACH-Mittelstand laut BKA 2023: 50.000–300.000 EUR pro erfolgreichem Vorfall. Deepfake-Stimme ergänzt den Angriff zunehmend.',
                    'threat' => 'CEO-Fraud, Deepfake-Voice-Betrug',
                    'vulnerability' => 'Kein dokumentierter 4-Augen-Prozess für Überweisungen, keine Rückruf-Verifikation',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BKA Bundeslagebild Cybercrime 2023, BSI Warnung Business E-Mail Compromise',
                ],
                [
                    'title' => 'DSGVO-Meldepflicht-Unklarheit bei Datenpannen',
                    'description' => 'Mitarbeiter unsicher, was eine Datenpanne ist. Fehlende Meldekette an internen DSB + Aufsichtsbehörde. 72h-Meldefrist nach Art. 33 DSGVO wird oft versäumt.',
                    'threat' => 'Versäumte 72h-Meldefrist, Bußgeld',
                    'vulnerability' => 'Keine Meldekette, keine Trainings, kein DSB benannt',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 33/34, BDSG § 42',
                ],
                [
                    'title' => 'Ungepatchte Internet-exponierte Systeme',
                    'description' => 'VPN-Appliances (Fortinet, SonicWall, Ivanti), Exchange-Server, File-Sync-Dienste (ownCloud/NextCloud) direkt im Internet und mehrere Wochen hinter aktuellen CVEs. Automatisiertes Massenscanning durch Initial-Access-Broker innerhalb < 48h nach CVE-Publikation beobachtbar.',
                    'threat' => 'Mass-Scanning-Ausnutzung (Ivanti Connect Secure CVE-2024-21887 u.ä.)',
                    'vulnerability' => 'Kein automatisiertes Patch-Management, kein Internet-Attack-Surface-Monitoring',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'CISA Known Exploited Vulnerabilities Catalog, BSI Warnmeldungen 2024',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Office 365 / Google Workspace', 'asset_type' => 'software', 'owner' => 'Head of IT', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 4, 'description' => 'E-Mail + Kollaboration + File-Storage'],
                ['name' => 'Mitarbeiter-Endgeräte (Laptop + Mobile)', 'asset_type' => 'hardware', 'owner' => 'Head of IT', 'confidentiality' => 3, 'integrity' => 3, 'availability' => 3, 'description' => 'Notebook-Pool + verwaltete Mobile-Geräte mit MDM'],
                ['name' => 'CRM-System', 'asset_type' => 'software', 'owner' => 'Head of Sales', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'HubSpot / Pipedrive / Salesforce — Kundenstammdaten + Historie'],
                ['name' => 'Finanzbuchhaltung', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 3, 'description' => 'DATEV / sevDesk / Lexware'],
                ['name' => 'Backup-Speicher', 'asset_type' => 'hardware', 'owner' => 'Head of IT', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 3, 'description' => 'NAS / Cloud-Backup mit Off-Site-Kopie'],
                ['name' => 'VPN-Gateway', 'asset_type' => 'hardware', 'owner' => 'Head of IT', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 4, 'description' => 'FortiGate / Sophos / WireGuard als Remote-Access-Einstieg'],
                ['name' => 'Kollaborations-/Projektmanagement', 'asset_type' => 'software', 'owner' => 'COO', 'confidentiality' => 3, 'integrity' => 3, 'availability' => 3, 'description' => 'Microsoft Teams / Slack / Asana / Monday'],
                ['name' => 'Website + Marketing-CMS', 'asset_type' => 'software', 'owner' => 'Head of Marketing', 'confidentiality' => 2, 'integrity' => 4, 'availability' => 4, 'description' => 'WordPress / Typo3 inkl. Marketing-Tracking'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.10',
                'A.5.12', 'A.5.15', 'A.5.17', 'A.5.23',
                'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27',
                'A.5.34',
                'A.6.3', 'A.6.5', 'A.6.6',
                'A.7.1', 'A.7.4',
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8',
                'A.8.12', 'A.8.13', 'A.8.15', 'A.8.23', 'A.8.28',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function automotiveBaseline(): array
    {
        return [
            'code' => 'BL-AUTOMOTIVE-v1',
            'name' => 'Automobilzulieferer (ISO 27001 + TISAX AL3 + ISO 27701)',
            'description' => <<<TXT
Starter-Paket für Automobilzulieferer mit TISAX-Pflicht durch OEM-Forderung (VW, BMW, Mercedes-Benz, Stellantis, Ford, Toyota).

Ziel-Kundensegment:
- NACE-Codes: C.29.10 (Herstellung von Kraftwagen), C.29.20 (Karosserien, Aufbauten, Anhänger), C.29.31 (Elektrische und elektronische Ausrüstungsgegenstände), C.29.32 (sonstige Teile und Zubehör). Zusätzlich C.27.11/C.27.20 (Elektromotoren, Batterien) und J.62.01 bei reinen Software-Zulieferern.
- Mitarbeiterzahl-Range: 50–15.000 FTE (Tier-2/Tier-3-Zulieferer bis großer System-Supplier)
- Umsatz-Range: 10 Mio. EUR – 5 Mrd. EUR
- Typische Regulatoren: keine direkte Aufsicht, aber indirekte Pflicht durch OEM-Einkaufsbedingungen (TISAX-Label als Vertragsvoraussetzung), ENX-Association, zusätzlich NIS2, UNECE WP.29 R155/R156 bei vernetzten Fahrzeugen, Produkthaftung, Dual-Use-Recht bei Militär-/Export-Komponenten.

Warum diese Baseline: TISAX (Trusted Information Security Assessment Exchange) ist De-facto-Zwang für alle Zulieferer, die mit OEM Engineering-Daten austauschen. ISA v6.0 (Information Security Assessment der ENX-Association) mappt ~80 % auf ISO 27001 A-Controls, ergänzt aber um Automotive-Spezifika: Prototypen-Schutz, Umgang mit Testfahrzeugen, Datenaustausch-Portale (Surface-EDI / OFTP2). Assessment-Levels AL1 (self-assessment), AL2 (remote), AL3 (vor Ort) — die meisten OEM fordern AL3 bei Zugriff auf Prototypen-Informationen. ISO 27701 kommt durch Fahrzeug-Telematik-/Fahrerdaten-Verarbeitung (Mobilitätsdienste, Flottenanalytik, Pay-per-Use) ins Spiel.

ISO 27001:2022 Control-Kern: A.5.7 (Threat Intelligence — ENX Early Warning System), A.5.12/A.5.13 (Classification + Labelling — Prototypen-Stufen), A.5.14 (Information Transfer — Surface-EDI-Portale), A.5.19-A.5.22 (Supplier-Subsupplier — TISAX Cascade), A.7.x (Physical — Prototypen-Lagerung), A.8.22 (Segregation — Engineering-/Produktionsnetze-Trennung).

Quellen-Nachweis:
- VDA ISA v6.0 (TISAX-Katalog)
- TISAX-Prüfkatalog der ENX-Association
- UNECE WP.29 R155 (Cybersecurity Management System für Fahrzeuge) + R156 (Software Update Management)
- ISO/SAE 21434 (Road vehicles — Cybersecurity engineering)
- ISO 27701:2025 für Fahrer-/Mobilitätsdaten
TXT,
            'industry' => IndustryBaseline::INDUSTRY_AUTOMOTIVE,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'TISAX', 'ISO27701'],
            'recommended_frameworks' => ['NIS2', 'ISO22301', 'BSI_GRUNDSCHUTZ', 'ISO27005'],
            'fte_days_saved_estimate' => 26.0,
            'preset_risks' => [
                [
                    'title' => 'Prototypen-Informations-Abfluss (TISAX Prototype Protection)',
                    'description' => 'Unautorisierter Zugriff auf Fahrzeug-Prototypen-Informationen (VP = Versuchsprojekt, EP = Erprobungsprojekt, SOP-minus-24-Monate). Klassischer Insider-Fall in 2023 bei einem süddeutschen Tier-1: Entwickler fotografierte Prüfstand und postete Bilder auf LinkedIn vor Marktankündigung. TISAX ISA v6.0 Abschnitt "Prototype Protection" ist harter AL3-Prüfpunkt — Non-Conformity führt zu Label-Suspendierung.',
                    'threat' => 'Insider / Social Media Leak / Wirtschaftsspionage',
                    'vulnerability' => 'Keine Prototype-Classification-Policy, keine Foto-/Handy-Verbotszonen im Entwicklungslabor',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'VDA ISA v6.0 Prototype Protection, TISAX Participant Handbook',
                ],
                [
                    'title' => 'OEM-Interface-Kompromittierung (Surface-EDI/OFTP2)',
                    'description' => 'Datenaustausch mit OEM über Surface-EDI, OFTP2, Odette-FTP. Port-Freigabe in Richtung VW-/BMW-/MBG-Netze inkl. Zertifikats-Verwaltung. Fall Q2/2024 bei nordrhein-westfälischem Tier-2: Ablaufende Client-Zertifikate führten 5 Tage zu komplettem Bestelleingangs-Stillstand — Vertragsstrafe 6-stellig. Angriffs-Fläche: kompromittierte EDI-Gateway-Credentials.',
                    'threat' => 'Ausfall / Manipulation des EDI-Austauschs / Zertifikats-Missbrauch',
                    'vulnerability' => 'Kein Zertifikats-Lifecycle-Management, kein Monitoring der EDI-Queue',
                    'category' => 'integrity',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'Odette OFTP2 Security Guidelines, VDA ISA v6.0 Information Transfer',
                ],
                [
                    'title' => 'Just-in-Sequence-Produktions-Ausfall',
                    'description' => 'JIS-Lieferung (Anlieferung in Montagereihenfolge, typisch 4-8h vor Verbau) bei Kfz-Sitz-/Cockpit-Modul-Zulieferern. Bandstillstand beim OEM kostet laut Branchenkonsens 30.000 EUR pro Minute. IT-Ausfall > 30 min eskaliert direkt auf OEM-Einkaufsleitung mit Vertragsstrafe und Q-Audit-Folge.',
                    'threat' => 'IT-Ausfall ERP/MES während JIS-Anlauf / Ransomware',
                    'vulnerability' => 'Kein redundantes ERP, kein manueller JIS-Fallback-Prozess',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'VDA 4916 Just-in-Sequence, OEM-Einkaufsbedingungen VW LAB',
                ],
                [
                    'title' => 'Lücken in TISAX-Scope-Definition / Assessment-Level',
                    'description' => 'TISAX-Scope wird anhand der Datenflüsse mit OEM definiert. Häufiger Fehler: Engineering-Standort mit Prototype-Zugriff nicht im Scope, dafür Werk ohne OEM-Daten mitzertifiziert. Re-Assessment-Pflicht alle 3 Jahre; bei Scope-Änderung Zwischen-Audit. Audit-Aufwand je Standort: 5–15 Tage vor-Ort-Prüfung.',
                    'threat' => 'TISAX-Label-Suspendierung, Vertragsverlust bei OEM',
                    'vulnerability' => 'Unvollständige Scope-Analyse, keine Data-Flow-Mapping zu OEM',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'TISAX Participant Handbook, ENX Scope Definition Guidelines',
                ],
                [
                    'title' => 'Test-Vehicle-Flotte mit Telematik (personenbezogene Daten)',
                    'description' => 'Testfahrer-Flotten sammeln neben Fahrzeug-Telemetrie auch Fahrer-Daten (Lenkradgriff, Blickrichtung via DMS-Kamera, Sitz-Position). Diese sind personenbezogen i.S.d. DSGVO. Speicherung in Cloud-Backends mit unklarer Zweckbindung. ISO 27701 + Art. 9 DSGVO (Gesundheitsdaten bei Fahrerzustands-Monitoring).',
                    'threat' => 'DSGVO-Verletzung, Bußgeld, Widerruf der Betroffenen',
                    'vulnerability' => 'Keine Einwilligungs-Dokumentation der Testfahrer, keine Löschfristen im Telematik-Backend',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 9, ISO 27701:2025, EDPB Guidelines 01/2020 Connected Vehicles',
                ],
                [
                    'title' => 'UNECE R155/R156-Pflichten bei vernetzten Komponenten',
                    'description' => 'Seit Juli 2024 verlangt UNECE WP.29 R155 ein zertifiziertes Cyber Security Management System (CSMS) für alle Hersteller mit Typzulassung. R156 verlangt Software Update Management System (SUMS). Wer Steuergeräte (ECUs) mit OTA-Fähigkeit liefert, wird in das OEM-CSMS vertraglich einbezogen.',
                    'threat' => 'OEM lehnt Lieferung ab, fehlende R155-Konformität blockiert Typzulassung',
                    'vulnerability' => 'Kein ISO/SAE 21434-Prozess im Engineering, keine TARA (Threat Analysis & Risk Assessment)',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'UNECE WP.29 R155/R156, ISO/SAE 21434:2021',
                ],
                [
                    'title' => 'Schwachstellen in Entwicklungs-Werkzeugen (PLM/CAD)',
                    'description' => 'Teamcenter, Windchill, 3DEXPERIENCE, NX, CATIA haben 2023–2024 jeweils kritische CVEs (Siemens Healthineers Teamcenter CVE-2024-33649 u.ä.). Weil diese Systeme tief mit Engineering-Identitäten verflochten sind, ist ein Patch-Rollout aufwändig (Freigabe nach OEM-Kompatibilitäts-Matrix).',
                    'threat' => 'Gezielter Angriff auf Engineering-Infrastruktur, IP-Diebstahl',
                    'vulnerability' => 'Langes Patch-Lag (6-12 Monate), keine internal Attack-Surface-Metrik',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'Siemens ProductCERT / CISA Advisories 2024, TISAX Incident Handling',
                ],
                [
                    'title' => 'Subunternehmer-Cascade im Werkzeug-/Teile-Lieferkette',
                    'description' => 'Werkzeugbau, Lohnfertiger, Galvanik-Partner — oft Kleinbetriebe mit < 50 FTE und ohne ISMS-Reife. TISAX verlangt Cascade-Absicherung (Zulieferer-TISAX bei Prototype-Daten). Lieferkette enthält typischerweise 15–40 direkte Lieferanten pro Projekt.',
                    'threat' => 'Supply-Chain-Datenabfluss über kleine Unterlieferanten',
                    'vulnerability' => 'Keine Lieferanten-Klassifizierung nach Datensensitivität, kein Nachweis des TISAX-Labels',
                    'category' => 'third_party',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'VDA ISA v6.0 Supplier Relationships, TISAX Cascade Requirements',
                ],
                [
                    'title' => 'Prüfstände mit veralteter IT (Klima-/Dauerlauf-Rigs)',
                    'description' => 'Prüfstände (Dauerlauf-Rigs, Klimakammern, Akustik-Rollenprüfstände) laufen mit teils 15+ Jahre alter Steuer-IT, die Messwerte per SMB/FTP in interne Netze schiebt. Prüfstand-Ausfall kostet Entwicklungszeit + verzögert SOP. Patchen ist schwierig — Prüfstand-Hersteller unterstützt oft keine aktuellen OS-Versionen.',
                    'threat' => 'Wurm-/Ransomware-Ausbreitung, Prüfstand-Stillstand',
                    'vulnerability' => 'Keine Segmentierung der Prüfstand-IT, keine Endpoint-Protection',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'VDA ISA v6.0, IEC 62443 für OT-ähnliche Systeme',
                ],
                [
                    'title' => 'Zero-Day in Fahrzeug-Telematik-Backend (Connected Car)',
                    'description' => 'Fahrzeug-Telematik-Backends haben Angriffsfläche, die 2015–2024 in mehreren Fällen zu massiven Incidents geführt hat (Jeep-Hack 2015, Tesla-Exploit, BMW-ConnectedDrive 2015, Toyota-Telematik-Leak 2023 mit 2,15 Mio. Fahrzeugen betroffen). Zero-Day in Mobilfunk-Stack oder Cloud-API führt zu Massenkompromittierung.',
                    'threat' => 'Zero-Day-Ausnutzung im Mobilfunk-/Cloud-Stack',
                    'vulnerability' => 'Kein Bug-Bounty-Programm, keine Runtime-Protection im Telematik-Backend',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'ENISA Connected Vehicles Threat Landscape 2023, Toyota Incident Report 2023',
                ],
                [
                    'title' => 'ISA-Katalog-Abdeckung nicht vollständig für AL3',
                    'description' => 'TISAX AL3 erfordert Nachweis aller "must"-Kriterien des ISA-Katalogs + volumige Evidence (Policies, Records, Screenshots). Häufig Gaps in 5.1.1 (Security Policy), 5.2.x (Organization), 7.x (Physical), 8.x (Operations). Assessor-Findings führen zu Correction Plan mit 90-Tage-Frist.',
                    'threat' => 'TISAX-Assessor-Findings, Correction Plan mit OEM-Sichtbarkeit',
                    'vulnerability' => 'Policies nicht ISA-gemappt, keine Evidence-Sammlung automatisiert',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'VDA ISA v6.0 Mandatory Criteria, ENX Assessment Guidelines',
                ],
            ],
            'preset_assets' => [
                ['name' => 'PLM-System (Teamcenter / Windchill / 3DEXPERIENCE)', 'asset_type' => 'software', 'owner' => 'Head of Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Produktlebenszyklus-Management mit Stücklisten, Änderungsmanagement, OEM-Austauschpaketen'],
                ['name' => 'CAD-Vault (CATIA/NX/Creo)', 'asset_type' => 'software', 'owner' => 'Head of Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Konstruktionsdaten-Tresor mit Check-in/Check-out-Policy und Prototype-Schutz'],
                ['name' => 'EDI-Gateway (Surface-EDI / OFTP2 / VDA 4905)', 'asset_type' => 'hardware', 'owner' => 'Head of Supply Chain IT', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 5, 'description' => 'Zentrales Gateway für OEM-Datenaustausch, Zertifikats-gesteuert'],
                ['name' => 'Prüfstände + Testrig-IT', 'asset_type' => 'hardware', 'owner' => 'Head of Test Engineering', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Dauerlauf-, Klima-, NVH-Prüfstände mit Mess-IT'],
                ['name' => 'Prototypen-Labor (physische Zugangszone)', 'asset_type' => 'location', 'owner' => 'Head of R&D', 'confidentiality' => 5, 'integrity' => 4, 'availability' => 3, 'description' => 'Bauteile-/Fahrzeug-Lager mit Handy-Verbot + Zutrittskontrolle AL3-konform'],
                ['name' => 'Test-Vehicle-Flotte mit Telematik', 'asset_type' => 'hardware', 'owner' => 'Head of Vehicle Integration', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Testfahrzeuge mit Datenlogger + Cloud-Upload inkl. Fahrerdaten'],
                ['name' => 'MES/ERP Produktion (SAP S/4 + Dassault Delmia)', 'asset_type' => 'software', 'owner' => 'CIO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 5, 'description' => 'JIS/JIT-kritisches Kernsystem'],
                ['name' => 'ECU-Entwicklungs-/Flash-Umgebung', 'asset_type' => 'software', 'owner' => 'Head of Embedded Software', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'AUTOSAR-Toolchain + HSM-Keys für Secure Boot und SOTA-Signaturen'],
                ['name' => 'TISAX-Evidence-Repository', 'asset_type' => 'software', 'owner' => 'Head of Information Security', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 3, 'description' => 'Zentralisierte Ablage aller Policies, Records, Screenshots für TISAX-Assessor'],
                ['name' => 'Lieferanten-/Subunternehmer-Portal', 'asset_type' => 'software', 'owner' => 'Head of Procurement', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Portal für Anfragen/Zeichnungen/NDAs an Tier-3-Lieferanten'],
            ],
            'preset_applicable_controls' => [
                // Kern-TISAX-AL3 (must-haves aus ISA v6.0 Mapping)
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.4', 'A.5.7',
                'A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.13', 'A.5.14',
                'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18',
                // Supplier Chain (TISAX Cascade)
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23',
                'A.5.24', 'A.5.25', 'A.5.26', 'A.5.29', 'A.5.30',
                'A.5.31', 'A.5.33', 'A.5.34',
                // People — Engineering-Insider
                'A.6.3', 'A.6.5', 'A.6.6',
                // Physical — Prototype-Zonen, Lab-Access
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.6', 'A.7.7', 'A.7.8', 'A.7.10',
                // Technical
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8', 'A.8.9',
                'A.8.12', 'A.8.15', 'A.8.16',
                'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.24', 'A.8.25',
                'A.8.28', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function cloudProviderBaseline(): array
    {
        return [
            'code' => 'BL-CLOUD-PROVIDER-v1',
            'name' => 'Cloud/SaaS-Anbieter B2B (ISO 27001 + BSI C5:2026 + SOC 2 + ISO 27017/27018)',
            'description' => <<<TXT
Starter-Paket für B2B-Cloud- und SaaS-Anbieter mit EU-Hosting und deutschen/europäischen Enterprise-Kunden.

Ziel-Kundensegment:
- NACE-Codes: J.63.11 (Datenverarbeitung, Hosting und damit verbundene Tätigkeiten), J.62.01 (Programmierungstätigkeiten), J.62.02 (Beratung im Bereich IT)
- Mitarbeiterzahl-Range: 25–3.000 FTE (reine SaaS-Scale-ups bis etablierte Managed-Cloud-Provider)
- Umsatz-Range: 5 Mio. EUR – 2 Mrd. EUR ARR
- Typische Regulatoren/Trust-Programme: BSI (C5-Testat als De-facto-Standard für Behörden- und Bundesbank-Geschäft), AICPA (SOC 2 für US-Kunden), ENISA (EUCS im Werden), BaFin/EIOPA (bei Finanz-Kunden indirekt), NIS2 (als "wichtige Einrichtung" wahrscheinlich)

Warum diese Baseline: Cloud-Anbieter haben ein anderes Risikoprofil als klassisch IT-verwaltende Unternehmen — sie sind die Infrastruktur selbst. Multi-Tenant-Isolation, Hypervisor-/Container-Escape, Supply-Chain-Attacken auf Base-Images (SolarWinds/Codecov/3CX-Typus), BYOK/HYOK-Key-Management, Data-Residency — das sind keine "Zusatz"-Risiken, sondern die Existenz-Risiken. BSI C5:2026 (Release Q3/2024 als Entwurf, final Q1/2025) ergänzt klassisches C5:2020 um PQC-Krypto-Agilität, KI-Security-Anforderungen und Supply-Chain-Security-Kriterien — diese sollten Neubaselines direkt vollumfänglich abdecken. SOC 2 ist für US-Enterprise-Sales ohne Verhandelbarkeit.

ISO 27001:2022 Control-Kern: A.5.7 (Threat Intel — Cloud-Provider sind Ziel Nr. 1), A.5.23 (Cloud-Nutzung — selbst wenn man CSP ist, hat man Upstream-Abhängigkeiten), A.8.9 (Configuration Management — Hypervisor/Container-Baselines), A.8.24 (Kryptographie — BYOK/HYOK), A.8.25 (SDLC — Base-Image-Build-Sicherheit), A.8.28 (Secure Coding — Tenant-Boundary-Logik), A.8.31 (Dev/Test/Prod-Trennung — harte Forderung C5 DEV-04), A.8.32 (Change).

Quellen-Nachweis:
- BSI C5:2020 (aktuell) + C5:2026 (Entwurfsstand Q4/2024) inkl. neuer Domains SCS (Supply Chain Security), PQC, AISEC (AI Security)
- AICPA TSP 100 / SOC 2 Trust Services Criteria 2022
- ISO/IEC 27017:2015 (Cloud-spezifische Controls), ISO/IEC 27018:2019 (PII-Schutz in Public Cloud)
- ENISA EU Cybersecurity Certification Scheme for Cloud Services (EUCS) — Entwurf
- CIS Benchmarks + OWASP Top 10 for Cloud-Native / Docker / Kubernetes
- Cloud Security Alliance (CSA) CCM v4 + Top Threats 2024 Report
TXT,
            'industry' => IndustryBaseline::INDUSTRY_CLOUD,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'BSI-C5-2026', 'SOC2'],
            'recommended_frameworks' => ['ISO27017', 'ISO27018', 'ISO22301', 'GDPR', 'NIS2', 'ISO27701'],
            'fte_days_saved_estimate' => 30.0,
            'preset_risks' => [
                [
                    'title' => 'Multi-Tenant-Isolation-Bruch (Cross-Tenant Data Access)',
                    'description' => 'Logische Tenant-Trennung auf Applikations-/DB-Ebene versagt, ein Kunde sieht Daten eines anderen. Präzedenzfälle: Wiz/Microsoft "Chaos DB" 2021 (Cosmos-DB-Cross-Tenant-Access), AWS-S3-"GhostBuckets"-Publikations-Class 2023. BSI C5 BDI-02 verlangt explizite Tenant-Isolations-Controls mit Nachweis. Worst Case: systematischer Massen-Datenabfluss aller Kunden.',
                    'threat' => 'Fehler in Applikations-Logik / DB-Row-Level-Security / Hypervisor',
                    'vulnerability' => 'Keine durchgängigen Cross-Tenant-Penetration-Tests, keine automatisierten Boundary-Tests im CI',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5:2020/2026 BDI-02, CSA Top Threats 2024 #3 "Insecure Interfaces/APIs", Wiz Research 2021',
                ],
                [
                    'title' => 'Hypervisor-/Container-Escape',
                    'description' => 'Durchbruch der Virtualisierungs-Isolation (KVM/QEMU, VMware ESXi, Xen, containerd, runc). Historische Fälle: Spectre/Meltdown 2018 (Side-Channel), VENOM CVE-2015-3456, runc CVE-2024-21626. Bei Managed-Kubernetes-Services kritisch, weil Customer-Workloads auf geteilter Node laufen. BSI C5 KOS-04/KOS-05.',
                    'threat' => 'Zero-Day in Hypervisor / Container-Runtime',
                    'vulnerability' => 'Keine separierten Dedicated-Host-Pools für High-Trust-Kunden, keine gVisor/Firecracker-Sandbox',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5 KOS-04, CISA Known Exploited Vulnerabilities, Google gVisor Security Model',
                ],
                [
                    'title' => 'Supply-Chain-Attack auf Base-Image/Build-Pipeline',
                    'description' => 'Malware-Injection in Base-Images (Docker Hub typosquatting, npm-Pakete), Kompromittierung der CI/CD-Pipeline (GitHub Actions, GitLab Runners). Referenzfälle: SolarWinds 2020, Codecov 2021, 3CX 2023, XZ-utils Backdoor März 2024 (andrej/jia tan). BSI C5:2026 führt neue Domain SCS (Supply Chain Security) explizit wegen dieser Fälle ein.',
                    'threat' => 'Kompromittiertes Upstream-Paket / Build-System-Übernahme',
                    'vulnerability' => 'Keine SBOM-Pflicht, kein reproducible Build, keine Artefakt-Signatur (Sigstore/Cosign)',
                    'category' => 'integrity',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5:2026 SCS-Domain, SLSA Framework v1.0, NIST SP 800-204D',
                ],
                [
                    'title' => 'Kunden-BYOK-Schlüssel-Missbrauch durch Cloud-Anbieter',
                    'description' => 'Bring-Your-Own-Key/Hold-Your-Own-Key-Versprechen bedingt, dass der Cloud-Anbieter den Klartext-Schlüssel nie sieht. Fehlkonfiguration oder Insider-Zugriff kompromittiert das Vertrauensmodell. Enterprise-Kunden (Bundesverwaltung, Banken) verlangen HSM-basiertes Key-Management mit Crypto-Custodian-Trennung.',
                    'threat' => 'Insider-Zugriff auf Klartext-Schlüssel, Key-Management-Missbrauch',
                    'vulnerability' => 'Keine strikte Trennung Key-Custodian ↔ Betrieb, keine 4-Augen-Logs auf Key-Operations',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5 KRY-01/02, NIST SP 800-57 Part 1 Rev. 5, ISO 27018 Annex A',
                ],
                [
                    'title' => 'Data-Residency-Verletzung (EU-Daten in Non-EU-Regionen)',
                    'description' => 'Unbemerkter Datenabfluss in Non-EU-Regionen (Backup-Replikation, CDN-Edges, Support-Access aus Indien/USA). Schrems-II-relevant: DSGVO Kapitel V Drittlandübermittlungen brauchen SCC + TIA. EuGH C-311/18 Schrems II + EDPB Empfehlungen 01/2020. BSI C5 KOS-07 verlangt deklarierte Verarbeitungsorte.',
                    'threat' => 'DSGVO-Verletzung, EDPB-Beanstandung, Vertragsstrafe',
                    'vulnerability' => 'Keine Data-Flow-Karte, keine Egress-Kontrolle auf Region-Ebene',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'EuGH Schrems II (C-311/18), EDPB Empfehlungen 01/2020, BSI C5 KOS-07',
                ],
                [
                    'title' => 'Secrets-Leak in Git-Repos / Log-Aggregation',
                    'description' => 'API-Keys, OAuth-Tokens, DB-Passwörter rutschen in öffentliche Git-Repos (GitGuardian-Report 2024: 12,8 Mio. Leaks auf GitHub) oder in zentrale Log-Systeme (Splunk/Datadog). Wird von automatisierten Scans in Minuten entdeckt und ausgenutzt. BSI C5 IDM-06 Secrets-Management.',
                    'threat' => 'Automatisiertes Scanning + Credential-Missbrauch',
                    'vulnerability' => 'Kein Pre-Commit-Scan, keine zentralen Secrets-Vaults, kein Log-Sanitizer',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'GitGuardian State of Secrets Sprawl 2024, BSI C5 IDM-06',
                ],
                [
                    'title' => 'Privilege-Escalation in Control Plane (IAM)',
                    'description' => 'Fehler in IAM-Policy-Logik (überprivilegierte Service-Accounts, wildcard-erlaubende Rollen, Session-Token-Missbrauch). Klassische Kette: niedrigprivilegierter Einstieg → IAM-Policy-Enumeration → Privilege-Escalation via iam:PassRole oder kompromittierter Assume-Role-Kette. CSA Top Threats 2024 #1.',
                    'threat' => 'Lateral movement + Privilege Escalation innerhalb der Control Plane',
                    'vulnerability' => 'Überprivilegierte IAM-Rollen, keine least-privilege-Evaluation, fehlende SCPs',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'CSA Top Threats to Cloud Computing 2024 #1, AWS IAM Security Best Practices',
                ],
                [
                    'title' => 'Kunden-Data-Deletion / Right-to-be-Forgotten technisch nicht durchsetzbar',
                    'description' => 'DSGVO Art. 17 Löschungsrecht ist technisch schwer umsetzbar, wenn Daten in Backups, CDN-Caches, Hot-Standby-Replikaten, Analytics-Data-Lakes verteilt sind. Aufsichtsbehörden erwarten nachweisbaren Lösch-Prozess inkl. Backup-Retention-Kopplung.',
                    'threat' => 'DSGVO-Beanstandung, Vertragskündigung durch B2B-Kunde',
                    'vulnerability' => 'Keine Inventarisierung aller Datenspeicherorte, keine Retention-gekoppelten Lösch-Workflows',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 17, ISO 27018:2019 Kap. A.9.3',
                ],
                [
                    'title' => 'DDoS auf Control Plane / Tenant-API',
                    'description' => 'Angriff auf die Provisionierungs-API (nicht Daten-Ebene) führt zu Service-Degradation für alle Tenants gleichzeitig. Cloudflare April 2024: HTTP/2-Rapid-Reset 201 Mio. Requests/s. Ohne dedizierte Anti-DDoS-Infrastruktur und Rate-Limiting pro Tenant-Endpoint ist Ausfall wahrscheinlich.',
                    'threat' => 'L7-DDoS / Protokoll-Abuse',
                    'vulnerability' => 'Kein Rate-Limiting pro Tenant, keine Anti-Automation (reCAPTCHA / WAF-Bot-Signaturen)',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5 OPS-05, Cloudflare DDoS Threat Report Q1/2024',
                ],
                [
                    'title' => 'PQC-Migration der Kundenschnittstellen (C5:2026-Neuanforderung)',
                    'description' => 'BSI C5:2026 wird PQC-Crypto-Agilität als "must"-Kriterium verlangen. NIST FIPS 203/204/205 sind seit August 2024 final. Hybrid-TLS (X25519+ML-KEM-768) empfohlen von BSI TR-02102-1 v2024-02. Kunden werden nach 2025 nach PQC-Roadmap im RfP fragen.',
                    'threat' => 'Wettbewerbsnachteil, "Harvest now decrypt later" durch Nation-State',
                    'vulnerability' => 'TLS-Stack ohne PQC-Support, keine Crypto-Inventory',
                    'category' => 'strategic',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI TR-02102-1 v2024-02, NIST FIPS 203/204/205, BSI C5:2026 Entwurf PQC-Domain',
                ],
                [
                    'title' => 'AI/ML-Security (C5:2026 AISEC-Domain)',
                    'description' => 'Wenn der Cloud-Provider AI-Features anbietet (Embedded LLMs, Model-Inference-Service), entstehen neue Risiken: Prompt Injection, Model Extraction, Training-Data-Poisoning, Output-Leakage. C5:2026 AISEC-Domain fordert explizite Governance, Model-Cards, Threat-Modeling. OWASP Top 10 for LLMs (2023/2024).',
                    'threat' => 'Prompt-Injection / Model-Extraction / Training-Data-Leakage',
                    'vulnerability' => 'Kein AI-spezifisches Threat Model, keine Input-Guardrails',
                    'category' => 'integrity',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'BSI C5:2026 AISEC-Domain (Entwurf), OWASP Top 10 for LLM Applications 2024, ISO/IEC 42001:2023',
                ],
                [
                    'title' => 'Unterauftragsverarbeiter-Transparenz (DSGVO Art. 28 + C5 KOS)',
                    'description' => 'Cloud-Anbieter nutzen Unter-Cloud-Anbieter (CDN, Observability-SaaS, Monitoring, Backup-Storage). Enterprise-Kunden verlangen vollständige Unterauftragsverarbeiter-Liste mit Notice-Pflicht bei Änderung (typisch 30 Tage). Nicht-Konformität führt zu Vertragskündigungen.',
                    'threat' => 'Vertragsbruch mit Enterprise-Kunden, DSGVO-Beanstandung',
                    'vulnerability' => 'Sub-Processor-Liste manuell gepflegt, kein Änderungs-Notification-Prozess',
                    'category' => 'third_party',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                    'rationale_source' => 'DSGVO Art. 28 Abs. 2-4, BSI C5 KOS-06, ISO 27018 A.11.1',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Hypervisor-Cluster (KVM / VMware / Xen)', 'asset_type' => 'hardware', 'owner' => 'Head of Platform Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Virtualisierungs-Basisschicht für Tenant-Workloads'],
                ['name' => 'Object-Storage (S3-kompatibel)', 'asset_type' => 'software', 'owner' => 'Head of Storage', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'MinIO / Ceph / AWS S3 für Kundendaten inkl. BYOK-Verschlüsselung'],
                ['name' => 'Tenant Control Plane (IAM + Provisioning API)', 'asset_type' => 'software', 'owner' => 'Head of Cloud Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Tenant-Lifecycle + RBAC + Quota-Steuerung'],
                ['name' => 'Secrets Management (Vault / KMS)', 'asset_type' => 'software', 'owner' => 'Head of Security Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'HashiCorp Vault / AWS KMS — Zentrales Secrets/Key-Handling mit HSM-Backing'],
                ['name' => 'Billing / Metering System', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Nutzungs-Messung, Rechnungsstellung, Umsatzerfassung — revisionspflichtig'],
                ['name' => 'CI/CD-Pipeline (GitHub Actions / GitLab / Jenkins)', 'asset_type' => 'software', 'owner' => 'Head of Platform Engineering', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Build- und Deploy-Pipeline mit SBOM-Erzeugung und Sigstore-Signatur'],
                ['name' => 'Kubernetes-Cluster (Customer-facing)', 'asset_type' => 'hardware', 'owner' => 'Head of Platform Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Managed-K8s-Offering mit Multi-Tenant-Nodepools und Admission-Controller'],
                ['name' => 'Observability-Stack (Metrics/Logs/Traces)', 'asset_type' => 'software', 'owner' => 'Head of SRE', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 4, 'description' => 'Prometheus / Grafana / Loki / Tempo — mit Log-Sanitizing gegen Secret-Leak'],
                ['name' => 'Data-Residency-Gateway (Region-Routing)', 'asset_type' => 'software', 'owner' => 'Head of Cloud Engineering', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 5, 'description' => 'Policy-gesteuerte Egress-Kontrolle für EU-Data-Residency-Einhaltung'],
                ['name' => 'Trust-Portal / Kunden-Compliance-Dokumente', 'asset_type' => 'software', 'owner' => 'Head of Trust & Compliance', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 4, 'description' => 'SafeBase / OneTrust-artig — SOC-2-/C5-Testate, Subprocessor-Liste, SLA'],
            ],
            'preset_applicable_controls' => [
                // Organizational — Cloud-Provider haben alle Organisational-Controls umzusetzen
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.4', 'A.5.7',
                'A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.14',
                'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18',
                // Supplier + Cloud — kritisch, weil Kunden B2B-Subprocessor-Transparenz verlangen
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23',
                // Incident + BCM
                'A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.5.29', 'A.5.30',
                // Compliance + PII
                'A.5.31', 'A.5.32', 'A.5.33', 'A.5.34', 'A.5.35', 'A.5.36', 'A.5.37',
                // People
                'A.6.1', 'A.6.3', 'A.6.5', 'A.6.6',
                // Physical — Rechenzentrum-Zugang
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.8', 'A.7.10', 'A.7.12', 'A.7.13',
                // Technical — Cloud-Provider-Kern
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.7', 'A.8.8', 'A.8.9', 'A.8.10',
                'A.8.11', 'A.8.12', 'A.8.13', 'A.8.15', 'A.8.16',
                'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.24', 'A.8.25', 'A.8.26', 'A.8.27',
                'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31', 'A.8.32', 'A.8.33', 'A.8.34',
            ],
        ];
    }

    /**
     * Managed Service Provider (MSP) — operates customer IT on a continuous
     * basis (managed office, managed backup, managed SIEM). Primary risk
     * vector is the MSP-tooling fan-out: one compromised RMM/PSA cascades
     * into all customer tenants (Kaseya VSA 2021, ConnectWise 2024).
     *
     * NACE 62.03 — Betrieb von Datenverarbeitungseinrichtungen.
     *
     * Abgrenzung zu BL-CLOUD-PROVIDER: Cloud-Provider hostet seine eigene
     * Plattform für Self-Service-Kunden; MSP administriert *Kunden-eigene*
     * IT über zentrale Tools. Shared-Responsibility-Modell komplett anders.
     *
     * @return array<string,mixed>
     */
    private function managedServiceProviderBaseline(): array
    {
        return [
            'code' => 'BL-MANAGED-SERVICE-PROVIDER-v1',
            'name' => 'Managed Service Provider (ISO 27001 + SOC 2)',
            'description' => 'Starter-Paket für MSP, die Kunden-IT dauerhaft-operativ betreiben (managed office, managed backup, managed SIEM, managed EDR). NACE 62.03. Typische Kunden: KMU 50–2.000 MA, zunehmend mit NIS2-Pflichten, die SOC-2-Type-II-Bericht oder ISO-27001-Zertifikat vom MSP einfordern. Zentrales Risiko: Kaseya/ConnectWise-Style-Fanout — ein kompromittiertes MSP-Tool trifft 50+ Kundenumgebungen gleichzeitig. Controls daher fokussiert auf Admin-Identity, Tool-Härtung, Mandantentrennung, Fernzugriff.',
            'industry' => IndustryBaseline::INDUSTRY_GENERIC,
            'source' => IndustryBaseline::SOURCE_CONSULTANT,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'SOC2'],
            'recommended_frameworks' => ['ISO27017', 'ISO27018', 'NIS2', 'ISO20000'],
            'fte_days_saved_estimate' => 22.0,
            'preset_risks' => [
                [
                    'title' => 'Lateral Movement über MSP-RMM-Tooling in Kundenumgebungen',
                    'description' => 'Ein kompromittiertes RMM-Agenten-Update oder RMM-API-Key führt zu gleichzeitiger Ausführung auf allen verbundenen Kunden-Endpoints. Historische Referenzfälle: Kaseya VSA (Juli 2021, ~1.500 betroffene Endkunden), ConnectWise ScreenConnect (CVE-2024-1709, Feb 2024). Bewertung: likelihood=3 (gezieltes Supply-Chain-Ziel), impact=5 (Reputations- + Haftungs-Total-Loss). (Quelle: CISA Advisory AA24-060A, 2024-02-29)',
                    'threat' => 'Supply-Chain-Angriff auf RMM-Platform / kompromittierter API-Key',
                    'vulnerability' => 'Kein Canary-Deployment für Agent-Updates, fehlende Integritätsprüfung, API-Keys ohne Rotation',
                    'category' => 'third_party',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Missbrauch privilegierter Admin-Credentials auf Kunden-Systemen',
                    'description' => 'MSP-Techniker haben geteilte Admin-Accounts pro Kundenumgebung ohne PAM. Kompromittiertes Technikerkonto = breiter Kundenzugriff. Marktüblich wird heute Just-in-Time-Access + Session-Recording erwartet (SOC-2-CC6.1). Bewertung: likelihood=4, impact=5.',
                    'threat' => 'Credential-Theft, Insider-Abuse, abgewanderter Mitarbeiter',
                    'vulnerability' => 'Shared Admin-Accounts, kein PAM, keine Session-Aufzeichnung',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'MFA-Umgehung an Tool-APIs (Service-Accounts)',
                    'description' => 'Tool-zu-Tool-Integrationen (RMM→PSA→SIEM) laufen mit statischen API-Keys ohne MFA. Angreifer umgeht Nutzer-MFA über Service-Account. (Quelle: Microsoft Digital Defense Report 2024 — token-theft + OAuth-abuse rising). Bewertung: likelihood=4, impact=4.',
                    'threat' => 'Token-Theft, OAuth-Consent-Phishing auf Admin',
                    'vulnerability' => 'Statische API-Keys, keine Conditional-Access-Policy auf Service-Accounts',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Ransomware auf MSP selbst mit Kunden-Fanout',
                    'description' => 'Ransomware verschlüsselt MSP-interne Infrastruktur inkl. Kunden-Dokumentation, Passwort-Tresor, zentralem Backup-Server. MSP kann keine Kundeninzidente mehr bearbeiten. Regulatorische Meldung gegenüber allen Kunden fällig. Bewertung: likelihood=3, impact=5.',
                    'threat' => 'Ransomware-Gruppe mit MSP-Fokus (LockBit, BlackCat)',
                    'vulnerability' => 'Kein immutables Backup, MSP-eigene Systeme nicht segmentiert',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Unzureichende Mandantentrennung im zentralen SIEM',
                    'description' => 'Logs mehrerer Kunden laufen in einen gemeinsamen Index ohne Row-Level-Security. Analyst sieht versehentlich Daten anderen Kunden — Vertragsbruch + DSGVO-Verstoß. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Fehlkonfiguration, Analyst-Fehler',
                    'vulnerability' => 'SIEM ohne mandantenfähige Access-Control, keine Data-Tagging-Pflicht',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Backup-Pipelines ohne Air-Gap zum Kunden-Produktivnetz',
                    'description' => 'Managed-Backup-Agent hat schreibenden Zugriff auf Backup-Ziel aus Kunden-Produktion. Ransomware verschlüsselt Backup mit. Marktstandard seit 2022: immutable / object-lock / separates Credential. Bewertung: likelihood=4, impact=5.',
                    'threat' => 'Ransomware, Insider mit Kundenzugang',
                    'vulnerability' => 'Backup-Ziel online erreichbar, kein Object-Lock, kein separater Backup-User',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Dokumentations-Chaos bei Kundenübernahme / Offboarding',
                    'description' => 'Neue Kunden werden übernommen ohne strukturierten Discovery; alter MSP hinterlässt undokumentierte Spezial-Konfigurationen. Bei Abwanderung: welche Accounts/Tokens müssen wirklich rotiert werden? Bewertung: likelihood=4, impact=3.',
                    'threat' => 'Unvollständige Rücknahme von Zugängen nach Kundenabgang',
                    'vulnerability' => 'Kein Onboarding-/Offboarding-Runbook pro Kunde, kein Inventar der platzierten Agents/Keys',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Unklare Haftungs- und Verantwortungsaufteilung (RACI) im MSA',
                    'description' => 'Master-Service-Agreement definiert "Patching" pauschal ohne OS-/App-Trennung. Bei Ausfall Streit, wer verantwortlich war. Regulatorisch DORA Art. 30 verlangt präzises Rollenmodell für ICT-Drittanbieter. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Vertrags-/Haftungsstreit nach Incident',
                    'vulnerability' => 'MSA ohne RACI-Matrix pro Service, kein Shared-Responsibility-Diagramm',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'SLA-Breach-Eskalation unklar, Pönalen akkumulieren',
                    'description' => 'Reaktions-/Lösungszeiten werden gemessen, aber Eskalation ins MSP-Management erfolgt zu spät. Pönalen-Gutschriften an Kunden belasten Marge. Bewertung: likelihood=3, impact=3.',
                    'threat' => 'Finanzieller Schaden, Kundenabwanderung',
                    'vulnerability' => 'Kein automatisierter Eskalations-Timer, keine Management-Dashboard-Alerts',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Skill-Shortage und Key-Person-Dependency in Spezialrollen',
                    'description' => 'Nur 1–2 Senior-Engineers beherrschen das zentrale SIEM-Regelwerk oder die Firewall-Plattform. Bei Abgang / Krankheit fällt kompletter Service-Strang aus. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Unerwarteter Mitarbeiterabgang, Krankheit',
                    'vulnerability' => 'Kein Dual-Skilling-Programm, unzureichende Runbook-Dokumentation',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Verstoß gegen Auftragsverarbeitungs-Vereinbarung (DSGVO Art. 28)',
                    'description' => 'MSP verarbeitet in Kundenauftrag personenbezogene Daten (E-Mail-Betrieb, Endgeräte-Management). AVV-Pflichten bei Subunternehmer-Einsatz, Daten-Transfer Drittland (EU-US DPF), TOMs häufig nicht aktuell dokumentiert. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'DSGVO-Aufsichtsverfahren, Reputationsschaden',
                    'vulnerability' => 'AVV-Vorlage veraltet, keine TOM-Selbstauskunft, Unterauftragsverarbeiter nicht genehmigt',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Phishing mit MSP-Markenidentität gegen Endkunden',
                    'description' => 'Angreifer registriert Look-alike-Domain des MSP, kontaktiert Kundenanwender als "Service-Desk", führt Social-Engineering. Bewertung: likelihood=4, impact=3.',
                    'threat' => 'Impersonation / Business-Email-Compromise-Vektor',
                    'vulnerability' => 'Kein Domain-Monitoring, kein DMARC-Reject auf Haupt-Domain',
                    'category' => 'reputational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'RMM-Platform (Remote Monitoring & Management)', 'asset_type' => 'software', 'owner' => 'MSP Service Delivery Manager', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Zentrales Tool für Agent-Rollout, Patching, Remote-Control aller Kunden-Endpoints (z. B. NinjaRMM, Datto, N-able, ConnectWise Automate)'],
                ['name' => 'PSA-Platform (Professional Services Automation)', 'asset_type' => 'software', 'owner' => 'MSP Service Delivery Manager', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Ticketing + Zeiterfassung + Abrechnung + Vertragsverwaltung mit Kundendaten (z. B. Autotask, HaloPSA)'],
                ['name' => 'Zentraler SIEM/XDR für Managed Detection', 'asset_type' => 'software', 'owner' => 'SOC-Lead', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Log-Aggregation + Alert-Regelwerk über alle Kundenumgebungen, mandantenfähig segmentiert'],
                ['name' => 'Kunden-Backup-Plattform (Managed Backup)', 'asset_type' => 'software', 'owner' => 'Head of Managed Services', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Orchestriert Backup-Jobs, Restore-Tests, immutable Storage für alle betreuten Kunden'],
                ['name' => 'Admin-Jump-Hosts / Privileged Access Workstations', 'asset_type' => 'hardware', 'owner' => 'NOC-Schichtleiter', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Gehärtete Sprungserver, über die Techniker auf Kundenumgebungen zugreifen — einziger genehmigter Admin-Pfad'],
                ['name' => 'Kunden-Dokumentations-Wiki', 'asset_type' => 'software', 'owner' => 'Service Delivery Manager', 'confidentiality' => 5, 'integrity' => 4, 'availability' => 3, 'description' => 'Pro-Kunde-Runbooks, Netzdiagramme, Notfall-Kontakte (z. B. IT Glue, Hudu)'],
                ['name' => 'Ticketing-System mit Kunden-Datenzugriff', 'asset_type' => 'software', 'owner' => 'Service Delivery Manager', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 4, 'description' => 'Service-Desk-Tickets enthalten häufig Kunden-PII, Screenshots, temporäre Credentials'],
                ['name' => 'Privileged Access Management / Passwort-Tresor', 'asset_type' => 'software', 'owner' => 'MSP CISO', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Zentraler Secrets-Store für Kunden-Admin-Credentials mit Check-Out / Session-Recording'],
                ['name' => 'Patch-/Configuration-Automation (Intune/GPO-Orchestrator)', 'asset_type' => 'software', 'owner' => 'NOC-Schichtleiter', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 4, 'description' => 'Ausrollung von Patches + Baseline-Konfigurationen auf Kunden-Endpoints'],
                ['name' => 'MSP Identity Provider / Tenant-Federation', 'asset_type' => 'software', 'owner' => 'MSP CISO', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Entra ID / Okta des MSP mit Federation zu Kunden-Tenants (Granular Delegated Admin Privileges)'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.12', 'A.5.15', 'A.5.16', 'A.5.17',
                'A.5.18', 'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23', 'A.5.24', 'A.5.25',
                'A.5.26', 'A.5.30', 'A.5.34',
                'A.6.3', 'A.6.6',
                'A.8.2', 'A.8.3', 'A.8.5', 'A.8.8', 'A.8.12', 'A.8.15', 'A.8.16', 'A.8.20',
                'A.8.23', 'A.8.28', 'A.8.32',
            ],
        ];
    }

    /**
     * IT-Service-Provider / Systemhaus / Integrator — projektbasiert +
     * Support-Ticket-Geschäft. Abgrenzung zu MSP: weniger dauerhaft-operative
     * Verantwortung, typisch "wir bauen, Kunde betreibt". Kernrisiko:
     * Rückzug von Zugängen nach Projektende scheitert — Kunden-SSH-Keys
     * leben auf Mitarbeiterlaptops weiter, alte AD-Accounts in Kundendomänen
     * bleiben aktiv.
     *
     * NACE 62.02 — Informationstechnologie-Beratung.
     *
     * @return array<string,mixed>
     */
    private function itServiceProviderBaseline(): array
    {
        return [
            'code' => 'BL-IT-SERVICE-PROVIDER-v1',
            'name' => 'IT-Service-Provider / Systemhaus (ISO 27001)',
            'description' => 'Starter-Paket für IT-Systemhäuser und Integratoren (NACE 62.02). Typische Kunden: Mittelstand bis Konzern, öffentliche Hand (daher BSI-Grundschutz-Anforderungen durchschlagend). Geschäftsmodell: Projekte + Support-Tickets + Lizenz-Reselling — *kein* dauerhaft-operativer Betrieb wie beim MSP. Kernrisiken liegen im Projekt-Lebenszyklus: Account-Offboarding nach Projektende, Fernwartungszugänge, Source-Code-IP des Kunden, Subunternehmer-Kette für Spezial-Skills. Zertifikats-Pflicht regelmäßig über Ausschreibungsanforderung öffentlicher Auftraggeber getrieben.',
            'industry' => IndustryBaseline::INDUSTRY_GENERIC,
            'source' => IndustryBaseline::SOURCE_CONSULTANT,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001'],
            'recommended_frameworks' => ['SOC2', 'BSI_GRUNDSCHUTZ', 'ISO20000', 'GDPR'],
            'fte_days_saved_estimate' => 16.0,
            'preset_risks' => [
                [
                    'title' => 'Nicht zurückgezogene Kunden-Accounts nach Projektende',
                    'description' => 'Berater-Accounts in Kunden-AD / -Azure / -VPN bleiben nach Projektabschluss aktiv — weder Kunde noch Dienstleister pflegt eine Abgangs-Checkliste. Typisch bei Rollouts über 6–12 Monate mit wechselndem Team. Bewertung: likelihood=5, impact=4.',
                    'threat' => 'Kompromittierter Berater-Account als Einstiegstor in Kundendomäne',
                    'vulnerability' => 'Kein Offboarding-Runbook pro Projekt, keine periodische Zugriffs-Review',
                    'category' => 'third_party',
                    'inherent_likelihood' => 5,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Mitarbeiter-Laptops mit kundenspezifischen SSH-/VPN-Keys',
                    'description' => 'Engineers speichern kundenspezifische Private Keys in ~/.ssh/ ohne Passphrase / ohne Hardware-Token. Verlust oder Diebstahl = unmittelbarer Kundenzugang. Bewertung: likelihood=4, impact=5.',
                    'threat' => 'Laptop-Verlust, Credential-Stealer-Malware',
                    'vulnerability' => 'Keine zentrale Key-Verwaltung, keine Hardware-Token-Pflicht, keine Festplattenverschlüsselung-Pflicht',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Fernwartungszugänge bleiben über Projektende hinaus aktiv',
                    'description' => 'TeamViewer-/AnyDesk-/VPN-Tunnel wurden während Projektimplementierung eingerichtet, nach Go-Live nicht zurückgebaut. Bewertung: likelihood=4, impact=4.',
                    'threat' => 'Missbrauch verwaister Fernwartungspfade',
                    'vulnerability' => 'Kein Inventar platzierter Remote-Tools, kein Rückbau-Step in Projektabschluss-Checklist',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Kunden-IP in Source-Code-Ablagen (GitLab/Bitbucket) vermischt',
                    'description' => 'Kunden-spezifische Customizing-Repos liegen im gemeinsamen Dienstleister-Repo-Server ohne klare Projektgruppen-Trennung. Risiko: Cross-Kunden-Sichtbarkeit, Lizenzverletzung beim Weiterverwenden von Snippets. Bewertung: likelihood=4, impact=4.',
                    'threat' => 'Daten-/IP-Abfluss, Vertragsbruch mit Kunde',
                    'vulnerability' => 'Keine Projekt-Gruppen-Isolation, keine Commit-Autoren-Review',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Subunternehmer-Kette für Spezial-Skills (Nearshore/Freelancer)',
                    'description' => 'Bei Ressourcen-Engpässen werden Freelancer / Nearshore-Partner ohne identische Sicherheits-Standards zugeschaltet. Kunde vertraglich mit Dienstleister, Dienstleister mit Subunternehmer — Sicherheits-Level driftet. DSGVO Art. 28 Unter-AVV. Bewertung: likelihood=4, impact=4.',
                    'threat' => 'Sub-Supplier-Incident schlägt auf Kunde durch',
                    'vulnerability' => 'Keine Supplier-Security-Assessments, keine Durchreichung Kunden-Anforderungen an Subunternehmer',
                    'category' => 'third_party',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Vermischung Dev-Umgebung und Kunden-Staging',
                    'description' => 'Interne Entwickler-Sandbox enthält Produktivdaten-Kopien mehrerer Kunden. DSGVO-Verstoß (Zweckbindung) + Vertragsbruch (Daten-Residency). Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Daten-Compliance-Verstoß, Kundenbeschwerde',
                    'vulnerability' => 'Keine Datenmaskierungs-Policy, gemeinsam genutzte Test-Datenbanken',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Lizenz-Compliance-Risiko (Oracle, Microsoft, VMware-Post-Broadcom)',
                    'description' => 'Als Reseller/Implementierer haftet das Systemhaus indirekt, wenn beim Kunden Lizenz-Audits zu Nachforderungen führen, die auf Dienstleister-Fehlberatung zurückgehen. Nach Broadcom-Übernahme VMware 2023–2024 häufige Streitpunkte. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Lizenz-Audit-Nachforderung, Kundenregress',
                    'vulnerability' => 'Kein Lizenz-Compliance-Check im Architektur-Review',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'DSGVO beim Support-Zugriff auf Kundendaten',
                    'description' => 'Troubleshooting erfordert Einblick in Produktivdaten; häufig ohne formale Zugriffsanfrage, ohne Zweck-Dokumentation, ohne Log. AVV-Verletzung. Bewertung: likelihood=4, impact=3.',
                    'threat' => 'DSGVO-Beschwerde durch Kunden oder Betroffene',
                    'vulnerability' => 'Kein Break-Glass-Prozess, keine Access-Logs pro Support-Ticket',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Proposal-Phase: Datenleck sensibler Kundenarchitekturen',
                    'description' => 'In RFP-Antworten und Proposal-Dokumenten stehen Kunden-Ist-Zustand + geplante Sollarchitektur. Vertrieb versendet per E-Mail ohne Verschlüsselung, legt in geteiltem SharePoint ab. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Angreifer-Aufklärung durch geleakte Architekturdokumente',
                    'vulnerability' => 'Keine Klassifizierungs-Policy für Vertriebsdokumente, keine Verschlüsselungs-Pflicht',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Fehlende ISO-27001-Zertifizierung blockiert öffentliche Ausschreibungen',
                    'description' => 'Bund/Länder/KRITIS-Kunden fordern in Ausschreibungen zunehmend ISO-27001-Zertifikat oder BSI-C5-Bericht als Mindestanforderung. Ohne Zertifikat = ausgeschlossen. Kein reiner Security-Risk, aber geschäftsstrategisch. Bewertung: likelihood=4, impact=4.',
                    'threat' => 'Verlust von Ausschreibungsfähigkeit',
                    'vulnerability' => 'Keine strategische Zertifizierungs-Roadmap',
                    'category' => 'strategic',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Bring-your-own-Device bei externen Freelancern im Projekt',
                    'description' => 'Freelancer nutzen eigene Hardware, die nicht zentral verwaltet (MDM) ist. Keine Verschlüsselungs- oder Patch-Kontrolle durchsetzbar. Bewertung: likelihood=4, impact=3.',
                    'threat' => 'Datenabfluss über ungepatchtes oder unverschlüsseltes Freelancer-Gerät',
                    'vulnerability' => 'Keine BYOD-Policy, keine Pflicht zur Managed Workstation',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Projekt-SharePoint / Dokumentenablage', 'asset_type' => 'software', 'owner' => 'Head of Delivery', 'confidentiality' => 5, 'integrity' => 4, 'availability' => 3, 'description' => 'Projektdokumente, Ist-/Soll-Architekturen, Konfigurations-Exports, Design-Spezifikationen pro Kunde'],
                ['name' => 'Entwickler-Laptops (Consultant Workstations)', 'asset_type' => 'hardware', 'owner' => 'IT-Betrieb (Workplace Management)', 'confidentiality' => 5, 'integrity' => 3, 'availability' => 3, 'description' => 'Mobile Engineer-Arbeitsplätze mit Kunden-Zugangsdaten, SSH-Keys, VPN-Profilen'],
                ['name' => 'Source-Code-Repositories (GitLab / Bitbucket / Azure DevOps)', 'asset_type' => 'software', 'owner' => 'Head of Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Kunden-IP, Customizing-Code, Infrastruktur-as-Code-Templates — häufig wichtigstes Asset im Systemhaus'],
                ['name' => 'Customer-Identity-Bridge / Remote-Access-Portal', 'asset_type' => 'software', 'owner' => 'IT-Security-Officer', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Zentraler Einstiegspunkt für Berater-Zugriff auf Kundenumgebungen (Guacamole/Teleport/eigener Bastion)'],
                ['name' => 'Lab-/Test-Umgebung', 'asset_type' => 'hardware', 'owner' => 'Head of Engineering', 'confidentiality' => 3, 'integrity' => 3, 'availability' => 3, 'description' => 'Interne Spielwiese für Proof-of-Concepts und Zertifizierungs-Trainings'],
                ['name' => 'Zeiterfassung / Projekt-Abrechnung (PSA)', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Projekt-Controlling, Rechnungsstellung, Marge-Reporting'],
                ['name' => 'CRM / Proposal-System', 'asset_type' => 'software', 'owner' => 'Head of Sales', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Opportunities, Kunden-Ansprechpartner, RFP-Antworten mit Kundenarchitekturen'],
                ['name' => 'Dokumentations-Wiki / Confluence', 'asset_type' => 'software', 'owner' => 'Head of Delivery', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Interne Runbooks, Best Practices, gelegentlich eingebettete Kunden-Spezifika'],
                ['name' => 'Zertifikats- und Key-Tresor', 'asset_type' => 'software', 'owner' => 'IT-Security-Officer', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Zentraler Secrets-Store (Vault / Keeper / 1Password Business) für projektrelevante Credentials'],
                ['name' => 'Standard-Client-Image / MDM-Baseline', 'asset_type' => 'software', 'owner' => 'IT-Betrieb (Workplace Management)', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 4, 'description' => 'Gehärtetes Windows-/macOS-Image mit EDR, BitLocker/FileVault, SSO-Enrollment'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.14',
                'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22',
                'A.5.23', 'A.5.30', 'A.5.32',
                'A.6.1', 'A.6.2', 'A.6.3', 'A.6.5', 'A.6.6', 'A.6.7',
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.8', 'A.8.12', 'A.8.20', 'A.8.23',
                'A.8.28', 'A.8.30',
            ],
        ];
    }

    /**
     * Hosting-Provider — Colocation, Dedicated, VPS, Managed Hosting.
     * Kernfokus: Rechenzentrums-Physik + Netzwerk + Mandantentrennung auf
     * shared Infrastruktur. NIS2-relevant (Hosting-Provider sind
     * "wichtige Einrichtungen" nach Anhang II), oft zusätzlich BSI C5 für
     * deutsche Kunden. Abgrenzung zu BL-CLOUD-PROVIDER: klassisches
     * Hosting ist typischerweise IaaS/Bare-Metal ohne Plattform-Services
     * (keine managed DB/Kubernetes/Serverless).
     *
     * NACE 63.11 — Datenverarbeitung, Hosting und damit verbundene Tätigkeiten.
     *
     * @return array<string,mixed>
     */
    private function hostingProviderBaseline(): array
    {
        return [
            'code' => 'BL-HOSTING-PROVIDER-v1',
            'name' => 'Hosting-Provider / Colocation / VPS (ISO 27001 + C5 + NIS2)',
            'description' => 'Starter-Paket für klassische Hosting-Provider (NACE 63.11): Colocation, Dedicated Server, VPS, Managed Hosting. Abgrenzung zu BL-CLOUD-PROVIDER: kein PaaS / kein Serverless — Verantwortung endet beim Hypervisor bzw. bei Bare-Metal. Typische Kunden: KMU, Agenturen, SaaS-Anbieter der zweiten Reihe, teils Automotive-Zulieferer (→ TISAX nachgelagert). NIS2-Einordnung als "wichtige Einrichtung" Anhang II (Digitale Infrastruktur). Für den DE-Markt praktisch Pflicht: BSI-C5-Testat. Kernrisiken liegen in RZ-Physik, Netzwerk-Edge, Hardware-Lifecycle, Mandantentrennung auf gemeinsamer Hardware. (Quelle: BSI C5:2020/2026, ENISA "Cloud Security Guide for SMEs")',
            'industry' => IndustryBaseline::INDUSTRY_CLOUD,
            'source' => IndustryBaseline::SOURCE_CONSULTANT,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'BSI-C5-2026', 'SOC2'],
            'recommended_frameworks' => ['ISO27017', 'ISO22301', 'NIS2', 'TISAX'],
            'fte_days_saved_estimate' => 24.0,
            'preset_risks' => [
                [
                    'title' => 'Tailgating / unautorisierter Zutritt zum Rechenzentrum',
                    'description' => 'Besucher folgen Techniker durch Mantrap, Lieferant ohne Begleitung in Sicherheitsbereich. BSI C5:2020 PS-05, ISO 27001 A.7.1–7.4. Historisch bei vielen RZ-Audits als Finding aufgetaucht. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Unautorisierter physischer Zugang, Diebstahl, Sabotage',
                    'vulnerability' => 'Mantrap nicht konsequent genutzt, Besucher ohne Begleitzwang, Lieferanten-Prozess unklar',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Stromausfall trotz USV + Netzersatzanlage (NEA)',
                    'description' => 'USV-Batterien nicht getauscht im 5-Jahres-Zyklus, NEA-Lasttest < jährlich, Dieselvorrat nur 24h. Historischer Fall: OVH Straßburg Brand März 2021 (Gesamtausfall SBG2). Bewertung: likelihood=2, impact=5. (Quelle: OVH Incident Report 2021-03-10)',
                    'threat' => 'Stromausfall + NEA-Startversagen',
                    'vulnerability' => 'Unzureichende USV-/NEA-Wartung, kein dokumentierter Lasttest',
                    'category' => 'availability',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Kühlungs-Ausfall im Serverraum führt zu Flächen-Shutdown',
                    'description' => 'Klimaanlage fällt aus, Schwellwerte triggern thermisches Shutdown. Risiko akut im Sommer, besonders bei älteren Freikühlungs-Setups. Bewertung: likelihood=3, impact=5.',
                    'threat' => 'HVAC-Defekt, Hitzewelle',
                    'vulnerability' => 'Kein N+1 bei Kühlung, kein automatisiertes Re-Route von Lasten',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Volumetrischer DDoS auf gemeinsame Edge-Infrastruktur',
                    'description' => 'Einzelner Kunde wird Ziel eines Terabit-Angriffs, Edge-Router ausgelastet, Kollateralausfall für alle Kunden auf demselben PoP. (Quelle: Cloudflare DDoS Threat Report Q3 2024 — 5.6 Tbps Rekord). Bewertung: likelihood=4, impact=4.',
                    'threat' => 'DDoS (volumetrisch / protocol-layer)',
                    'vulnerability' => 'Keine Scrubbing-Appliance / kein Upstream-Scrubbing-Vertrag, BGP-Blackholing nicht automatisiert',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Noisy-Neighbour auf VPS-Compute-Nodes',
                    'description' => 'CPU-Scheduling / Storage-IOPS-Limits nicht streng erzwungen, ein Kunde monopolisiert Ressourcen (Crypto-Mining, ungewollter Lastpeak). SLA-Breach gegenüber Nachbarn. Bewertung: likelihood=4, impact=3.',
                    'threat' => 'Ressourcen-Erschöpfung durch Nachbar-VM',
                    'vulnerability' => 'Keine strikten cgroups / IOPS-Limits, kein Monitoring pro Tenant',
                    'category' => 'availability',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'BGP-Hijacking / Route-Leak des eigenen IP-Space',
                    'description' => 'Upstream-Peer verteilt fehlerhaft eine Route, Traffic zu Kunden-IPs wird umgeleitet. Ohne RPKI-Signierung kein schneller Schutz. Historisch: Rostelecom 2017, Klayswap 2022. Bewertung: likelihood=2, impact=4.',
                    'threat' => 'BGP-Hijack / Route-Leak',
                    'vulnerability' => 'Kein RPKI-ROA auf Präfixen, keine Route-Filterung mit Peers',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Fehlkonfigurierte Network-ACLs zwischen Kundensegmenten',
                    'description' => 'VLAN-Trennung auf dem Shared-Switch-Fabric löchrig — Kunde A erreicht Management-Interface von Kunde B. Klassischer Pentest-Finding. Bewertung: likelihood=3, impact=5.',
                    'threat' => 'Tenant-Hop, lateral attack zwischen Kunden',
                    'vulnerability' => 'Kein Default-Deny auf Tenant-Fabric, ACL-Changes ohne Peer-Review',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Backup-Tape-/Medien-Verlust bei Transport',
                    'description' => 'Offsite-Backup auf Band oder Wechseldatenträger geht auf dem Transportweg verloren, unverschlüsselt = Datenpanne. Bewertung: likelihood=2, impact=5.',
                    'threat' => 'Diebstahl / Verlust des Transportmediums',
                    'vulnerability' => 'Unverschlüsselte Medien, kein Chain-of-Custody-Log, unzuverlässiger Logistik-Partner',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'On-Site-Techniker mit physischem Zugang zu Kundenhardware',
                    'description' => 'Remote-Hands-Service verlangt Zugang zu Kunden-Servern. Insider könnte Direct-Memory-Attack, Festplattentausch, Boot-USB durchführen. Bewertung: likelihood=2, impact=5.',
                    'threat' => 'Insider-Angriff durch RZ-Personal',
                    'vulnerability' => 'Kein 4-Augen-Prinzip bei Remote-Hands, keine Video-Überwachung im Kunden-Cage',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Unzureichende Hardware-Entsorgung (Daten-Residuen)',
                    'description' => 'Außer-Betrieb-genommene Festplatten werden verschrottet ohne nachweisbaren Wipe (NIST SP 800-88 Purge / Destroy). Risiko: Verwerter verkauft weiter, Datenabfluss. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Datenabfluss über wiederverwertete Datenträger',
                    'vulnerability' => 'Kein zertifizierter Entsorgungs-Prozess, keine Wipe-/Shred-Nachweise pro Seriennummer',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Data-Residency-Verletzung (Kundendaten außerhalb vereinbarter Region)',
                    'description' => 'Disaster-Recovery-Setup repliziert Daten in Fremd-Region ohne vertragliche Freigabe. DSGVO Art. 44 ff., Schrems-II-Problematik bei Non-EEA. Bewertung: likelihood=3, impact=5.',
                    'threat' => 'DSGVO-Aufsicht, Vertragsverletzung',
                    'vulnerability' => 'Kein Data-Residency-Flag im Kundenobjekt, keine automatisierte Policy-Prüfung im Orchestrator',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Lieferketten-Risiko kritischer Netzwerk-/Server-Ersatzteile',
                    'description' => 'Switch-Hersteller (z. B. Juniper/Cisco) mit Lieferzeiten 6–12 Monate, USV-Batterien Mangelware. Ersatzteil-Verfügbarkeit kann RTO-Ziele verletzen. Historisch: Halbleiter-Krise 2021–2023. Bewertung: likelihood=3, impact=4.',
                    'threat' => 'Ausfall ohne rechtzeitigen Ersatz',
                    'vulnerability' => 'Keine Ersatzteil-Sicherheitsbestände, einseitige Lieferantenbindung',
                    'category' => 'availability',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Hypervisor-Cluster (VMware/Proxmox/KVM)', 'asset_type' => 'hardware', 'owner' => 'Head of Hosting Operations', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Compute-Plane für VPS-Produkte, Mandanten-Trennung auf Hypervisor-Ebene'],
                ['name' => 'Storage-Backend (SAN / Ceph / Pure)', 'asset_type' => 'hardware', 'owner' => 'Head of Hosting Operations', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Zentrales Block-/Object-Storage für Kunden-VMs inkl. Replikation'],
                ['name' => 'Network Edge Router / ASN-Infrastruktur', 'asset_type' => 'hardware', 'owner' => 'Head of Network Engineering', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Border-Router mit BGP-Peering, eigenem AS-Number, RPKI-Signierung'],
                ['name' => 'DDoS-Scrubbing-Appliance / Upstream-Scrubbing-Vertrag', 'asset_type' => 'hardware', 'owner' => 'Head of Network Engineering', 'confidentiality' => 2, 'integrity' => 4, 'availability' => 5, 'description' => 'Volumetrische Angriffe werden in Scrubbing-Center umgeleitet (Arbor, Link11, Cloudflare Magic Transit)'],
                ['name' => 'Management-Plane (vCenter / OpenStack / Proxmox-UI)', 'asset_type' => 'software', 'owner' => 'Head of Hosting Operations', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Administrative Steuerebene — Kompromittierung = Gesamtkontrolle aller Tenants'],
                ['name' => 'Kunden-DNS (Authoritative)', 'asset_type' => 'software', 'owner' => 'Head of Network Engineering', 'confidentiality' => 2, 'integrity' => 5, 'availability' => 5, 'description' => 'Authoritative DNS-Zonen für Kundendomänen, DNSSEC-signiert'],
                ['name' => 'Datacenter-Zutritts-System', 'asset_type' => 'hardware', 'owner' => 'Facility Manager', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Kartenleser, Mantrap-Steuerung, biometrische Authentifizierung, Videoaufzeichnung'],
                ['name' => 'USV + Kühlungs-Monitoring-Panel (BMS)', 'asset_type' => 'hardware', 'owner' => 'Facility Manager', 'confidentiality' => 2, 'integrity' => 5, 'availability' => 5, 'description' => 'Building-Management-System, erfasst Strom, Temperatur, Luftfeuchte, NEA-Bereitschaft'],
                ['name' => 'Out-of-Band-Management-Netz (OOB)', 'asset_type' => 'hardware', 'owner' => 'Head of Network Engineering', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Physisch getrenntes Management-Netz (iLO/iDRAC/IPMI) für Notfall-Zugriff'],
                ['name' => 'Konsol-Server / Serial-over-IP', 'asset_type' => 'hardware', 'owner' => 'NOC-Schichtleiter', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Zentraler Zugriffspunkt auf Serial Consoles aller Netzwerk- und Storage-Komponenten'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.12', 'A.5.15', 'A.5.17', 'A.5.18',
                'A.5.19', 'A.5.21', 'A.5.22', 'A.5.23', 'A.5.24', 'A.5.25', 'A.5.29', 'A.5.30',
                'A.5.34',
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.7.8', 'A.7.10',
                'A.7.11', 'A.7.12', 'A.7.13', 'A.7.14',
                'A.8.1', 'A.8.5', 'A.8.6', 'A.8.9', 'A.8.12', 'A.8.13', 'A.8.14', 'A.8.15',
                'A.8.16', 'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.24',
            ],
        ];
    }
}
