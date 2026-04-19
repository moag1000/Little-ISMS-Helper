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
 * Seeds four ready-to-use industry baselines (Produktion, Finance,
 * KRITIS-Health, Generic-Starter). Idempotent: updates if the code
 * already exists, creates otherwise.
 *
 * Content is a pragmatic starter — real rollouts should be refined by
 * a domain consultant. The FTE-saved estimate is the *delta* between
 * starting from zero vs. starting with the preset set.
 */
#[AsCommand(
    name: 'app:load-industry-baselines',
    description: 'Seed or refresh industry-specific ISMS starter baselines (production, finance, kritis_health, generic).',
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

        $io->success(sprintf(
            'Industry baselines: %d created, %d updated',
            $stats['created'],
            $stats['updated'],
        ));

        return Command::SUCCESS;
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
        ];
    }

    /** @return array<string,mixed> */
    private function productionBaseline(): array
    {
        return [
            'code' => 'BL-PRODUCTION-v1',
            'name' => 'Mittelständische Produktion (ISO 27001 + NIS2)',
            'description' => 'Starter-Paket für produzierende Mittelstands-Unternehmen mit OT/ICS-Anteil. Deckt die typischen Asset-Klassen Fertigungsrechner, PLC/SPS, Werkstatt-Netz ab und vermerkt NIS2-relevante Prozesse, wenn die Firma über der KRITIS-Schwelle liegt.',
            'industry' => IndustryBaseline::INDUSTRY_PRODUCTION,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001'],
            'recommended_frameworks' => ['NIS2', 'BSI_GRUNDSCHUTZ', 'ISO27005'],
            'fte_days_saved_estimate' => 12.0,
            'preset_risks' => [
                [
                    'title' => 'OT-Netzwerk-Segmentierung unzureichend',
                    'description' => 'Produktionsnetz ohne ausreichende Trennung vom Office-Netz ermöglicht Lateral Movement von IT-Incidents in die OT.',
                    'threat' => 'Ransomware / Wurm',
                    'vulnerability' => 'Fehlende Firewall-Regeln zwischen OT und IT',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Veraltete PLC/SPS-Firmware',
                    'description' => 'Speicherprogrammierbare Steuerungen laufen mit Firmware-Stand > 5 Jahre ohne Security-Patches.',
                    'threat' => 'Exploit bekannter OT-Schwachstellen',
                    'vulnerability' => 'Kein OT-Patch-Management-Prozess',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Fernwartungs-Zugänge durch Maschinen-Hersteller',
                    'description' => 'VPN-/Modem-Zugänge durch Anlagenhersteller sind technisch aktiv, werden aber nicht überwacht.',
                    'threat' => 'Drittanbieter-Kompromittierung',
                    'vulnerability' => 'Fehlende Zugriffs-Reviews für Hersteller-Zugänge',
                    'category' => 'third_party',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Konstruktionsdaten-Abfluss (CAD/CAM)',
                    'description' => 'Sensible Konstruktionsdaten liegen unverschlüsselt auf File-Servern und auf Kunden-USB-Sticks.',
                    'threat' => 'Insider / Wirtschaftsspionage',
                    'vulnerability' => 'Keine Datenklassifikation + DLP',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Ausfall einer Einzel-Produktionslinie',
                    'description' => 'Single-Point-of-Failure bei Spezialmaschine. RTO > 4 Wochen bei Totalausfall.',
                    'threat' => 'Hardware-Defekt / Brand',
                    'vulnerability' => 'Kein redundanter Anlagenbetrieb',
                    'category' => 'availability',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'transfer',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Produktions-MES-Server', 'asset_type' => 'hardware', 'owner' => 'IT-Leitung', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Manufacturing Execution System — koordiniert Fertigungsaufträge'],
                ['name' => 'PLC-Netzwerk Fertigungshalle 1', 'asset_type' => 'hardware', 'owner' => 'Instandhaltung', 'confidentiality' => 2, 'integrity' => 5, 'availability' => 5, 'description' => 'SPS-Netz der Hauptfertigungslinie'],
                ['name' => 'CAD/CAM-Dateiserver', 'asset_type' => 'software', 'owner' => 'Konstruktion', 'confidentiality' => 5, 'integrity' => 4, 'availability' => 3, 'description' => 'Zentraler Speicher für Konstruktionszeichnungen'],
                ['name' => 'Fernwartungs-Jumphost', 'asset_type' => 'hardware', 'owner' => 'IT-Security', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Bastion-Host für Hersteller-Zugriffe'],
                ['name' => 'ERP-System (SAP/Dynamics)', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'Finanz-/Auftragsdaten-Kernsystem'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.12', 'A.5.15', 'A.5.19', 'A.5.23', 'A.5.30',
                'A.6.3', 'A.6.6', 'A.8.1', 'A.8.2', 'A.8.5', 'A.8.8', 'A.8.9', 'A.8.12', 'A.8.15',
                'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.25', 'A.8.28', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function financeBaseline(): array
    {
        return [
            'code' => 'BL-FINANCE-v1',
            'name' => 'Finanzsektor (ISO 27001 + DORA + BDSG)',
            'description' => 'Starter-Paket für Banken, Versicherer, Zahlungsinstitute unter DORA-Regime. Kernrisiken: ICT-Drittanbieter-Konzentration, operationelle Resilienz, Trade-Data-Integrität, Sanktionen-/KYC-Prozesse.',
            'industry' => IndustryBaseline::INDUSTRY_FINANCE,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'DORA'],
            'recommended_frameworks' => ['BDSG', 'NIS2', 'ISO27005', 'ISO22301'],
            'fte_days_saved_estimate' => 18.0,
            'preset_risks' => [
                [
                    'title' => 'ICT-Drittanbieter-Konzentrationsrisiko',
                    'description' => 'Kritische Funktionen laufen bei einem einzelnen Hyperscaler ohne Exit-Strategie (DORA Art. 28).',
                    'threat' => 'Anbieter-Ausfall / regulatorische Maßnahme gegen Anbieter',
                    'vulnerability' => 'Keine dokumentierte Exit-Strategie, keine Substituierbarkeits-Analyse',
                    'category' => 'third_party',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Unbefugte Änderung an Handelsdaten (Trade-Data-Integrität)',
                    'description' => 'Manipulation von Order- oder Abwicklungsdaten zwischen Front- und Back-Office ohne Abgleich-Kontrolle.',
                    'threat' => 'Insider-Manipulation / fehlerhafte Automatisierung',
                    'vulnerability' => 'Unzureichende Abgleichs-Controls',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Unzureichende Cyber-Resilienz-Tests (TLPT)',
                    'description' => 'DORA Art. 26/27 erfordert Threat-Led-Penetration-Tests. Heute wird nur klassisch pen-getestet.',
                    'threat' => 'APT / gezielter Angriff auf Finanzsystem',
                    'vulnerability' => 'Kein TLPT-Programm mit Red-Team',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'KYC/Sanktionen-Datenqualität',
                    'description' => 'Listen-Screening läuft gegen veraltete Sanktionslisten; False-Negatives möglich.',
                    'threat' => 'Geldwäsche / regulatorische Sanktion gegen uns',
                    'vulnerability' => 'Screening-Update-Prozess manuell',
                    'category' => 'compliance',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Meldefristen Cyber-Vorfall (24h Early Warning, 72h Intermediate)',
                    'description' => 'DORA Art. 19 verlangt strukturierte Meldung an zuständige Behörde binnen enger Fristen.',
                    'threat' => 'Meldefristen überschritten',
                    'vulnerability' => 'Kein definierter Incident-Meldeprozess mit SLA',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Kernbankensystem', 'asset_type' => 'software', 'owner' => 'CIO', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Kontoführendes System'],
                ['name' => 'Trading-Platform', 'asset_type' => 'software', 'owner' => 'Head of Trading', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Order-Routing + Execution'],
                ['name' => 'Payments-Gateway', 'asset_type' => 'software', 'owner' => 'CIO', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Kartenzahlungsabwicklung'],
                ['name' => 'KYC-/AML-System', 'asset_type' => 'software', 'owner' => 'Compliance', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Know-Your-Customer + Anti-Money-Laundering'],
                ['name' => 'Data Warehouse (Regulatorisches Reporting)', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 4, 'description' => 'BaFin/EZB-Meldewesen'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.12', 'A.5.15', 'A.5.17', 'A.5.18',
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23', 'A.5.24', 'A.5.25', 'A.5.26',
                'A.5.27', 'A.5.28', 'A.5.29', 'A.5.30', 'A.5.34', 'A.5.36',
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.12', 'A.8.15', 'A.8.16', 'A.8.20',
                'A.8.21', 'A.8.23', 'A.8.24', 'A.8.28', 'A.8.32',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function kritisHealthBaseline(): array
    {
        return [
            'code' => 'BL-KRITIS-HEALTH-v1',
            'name' => 'KRITIS Gesundheit (ISO 27001 + NIS2 + KHZG)',
            'description' => 'Starter-Paket für Krankenhäuser, Reha-Kliniken, MVZ unter KRITIS-Gesundheit-Schwellen. Schwerpunkt: medizinische Geräte, Patientenakten (ePA), Medikamenten-Verordnung, Notfall-IT-Betrieb.',
            'industry' => IndustryBaseline::INDUSTRY_KRITIS_HEALTH,
            'source' => IndustryBaseline::SOURCE_COMMUNITY,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001', 'NIS2'],
            'recommended_frameworks' => ['BSI_GRUNDSCHUTZ', 'ISO22301', 'GDPR'],
            'fte_days_saved_estimate' => 15.0,
            'preset_risks' => [
                [
                    'title' => 'Ransomware-Ausfall Klinik-IT',
                    'description' => 'Schließt KIS + Laborinformationssystem + Radiologie über mehrere Tage — Notfallbetrieb <50%.',
                    'threat' => 'Ransomware-Gruppe (targeted attack)',
                    'vulnerability' => 'Unzureichende Segmentierung + Backup-Recovery > 24h',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Medizingeräte mit veralteten Embedded-OS',
                    'description' => 'CT/MRT/Beatmungsgeräte laufen auf Windows XP/7 ohne Hersteller-Support.',
                    'threat' => 'Exploit + Geräte-Stillstand während OP',
                    'vulnerability' => 'Hersteller verweigert Firmware-Update',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Patientendaten-Abfluss (DSGVO Art. 33)',
                    'description' => 'Unverschlüsselte E-Mail-Kommunikation mit Hausärzten, USB-Stick-Austausch mit externen Radiologen.',
                    'threat' => 'Datenpannen-Meldepflicht + Bußgeld',
                    'vulnerability' => 'Keine Ende-zu-Ende-Verschlüsselung',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Medikamenten-Verordnung im KIS integritätskritisch',
                    'description' => 'Fehlerhafte oder manipulierte Medikations-Einträge können Patienten direkt schädigen.',
                    'threat' => 'Datenbank-Manipulation / Fehlkonfiguration',
                    'vulnerability' => 'Unzureichende 4-Augen-Kontrolle bei Medikations-Änderungen',
                    'category' => 'integrity',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Krankenhaus-Informationssystem (KIS)', 'asset_type' => 'software', 'owner' => 'Medizin-IT', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Zentrale Patienten-Aktenverwaltung'],
                ['name' => 'Labor-Informationssystem (LIS)', 'asset_type' => 'software', 'owner' => 'Medizin-IT', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Labor-Auftrag und Ergebnis-Verarbeitung'],
                ['name' => 'Radiologie-PACS', 'asset_type' => 'software', 'owner' => 'Medizin-IT', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 4, 'description' => 'Bild-Archivierung Radiologie'],
                ['name' => 'Medikamenten-Verordnungs-Modul', 'asset_type' => 'software', 'owner' => 'Chefarzt', 'confidentiality' => 5, 'integrity' => 5, 'availability' => 5, 'description' => 'Elektronische Arzneimittel-Verordnung'],
                ['name' => 'Medizingerät-Netz (OT-Segment)', 'asset_type' => 'hardware', 'owner' => 'Technik', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 5, 'description' => 'Separates Netzwerk für CT/MRT/Beatmungsgeräte'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.7', 'A.5.9', 'A.5.12', 'A.5.13', 'A.5.14',
                'A.5.15', 'A.5.23', 'A.5.24', 'A.5.25', 'A.5.26', 'A.5.30', 'A.5.34', 'A.5.37',
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.8', 'A.8.12', 'A.8.13', 'A.8.15',
                'A.8.20', 'A.8.21', 'A.8.23', 'A.8.24', 'A.8.28',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function genericStarterBaseline(): array
    {
        return [
            'code' => 'BL-GENERIC-v1',
            'name' => 'Generischer ISMS-Starter (ISO 27001 only)',
            'description' => 'Minimales Starter-Paket für Organisationen ohne spezifische Branche. Deckt die typischen Büro-/Software-Assets ab, listet 6 Basis-Risiken und markiert die ISO-27001-Pflicht-Controls als anwendbar. Erwarte nachträgliche Branchen-Anpassung.',
            'industry' => IndustryBaseline::INDUSTRY_GENERIC,
            'source' => IndustryBaseline::SOURCE_INTERNAL,
            'version' => '1.0',
            'required_frameworks' => ['ISO27001'],
            'recommended_frameworks' => ['ISO27005'],
            'fte_days_saved_estimate' => 5.0,
            'preset_risks' => [
                [
                    'title' => 'Phishing-Angriff auf Office-Accounts',
                    'description' => 'Mitarbeiter klickt auf Phishing-Mail, Angreifer kompromittiert Office365-Account.',
                    'threat' => 'Phishing / Credential-Stealer',
                    'vulnerability' => 'Kein MFA + keine regelmäßige Awareness-Schulung',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Laptop-Verlust unterwegs',
                    'description' => 'Dienst-Laptop wird im Zug / Café vergessen. Festplatte unverschlüsselt.',
                    'threat' => 'Diebstahl + Datenzugriff',
                    'vulnerability' => 'Keine Festplatten-Verschlüsselung (BitLocker/FileVault)',
                    'category' => 'confidentiality',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Backup-Wiederherstellung nie getestet',
                    'description' => 'Tägliches Backup läuft, aber Restore-Fähigkeit nie validiert. Im Ernstfall unbrauchbar.',
                    'threat' => 'Datenverlust nach Ransomware / Hardware-Defekt',
                    'vulnerability' => 'Kein regelmäßiger Restore-Test',
                    'category' => 'availability',
                    'inherent_likelihood' => 2,
                    'inherent_impact' => 5,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Abgang Schlüsselmitarbeiter ohne Wissens-Übergabe',
                    'description' => 'Technischer Single-Point-of-Knowledge verlässt Firma ohne dokumentierten Übergang.',
                    'threat' => 'Operativer Stillstand in spezifischem Bereich',
                    'vulnerability' => 'Fehlende Dokumentation + Urlaubs-Vertretung',
                    'category' => 'operational',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Schatten-IT / unautorisierte SaaS-Tools',
                    'description' => 'Mitarbeiter nutzen Cloud-Tools ohne IT-Freigabe (File-Sharing, Projektmanagement).',
                    'threat' => 'Datenabfluss + DSGVO-Verletzung',
                    'vulnerability' => 'Kein SaaS-Inventar + keine Policy',
                    'category' => 'compliance',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 3,
                    'treatment_strategy' => 'mitigate',
                ],
                [
                    'title' => 'Schwache / geteilte Passwörter',
                    'description' => 'Team-Passwörter in Excel-Tabelle, Admin-Accounts ohne MFA, gemeinsam genutzt.',
                    'threat' => 'Credential Stuffing / Insider',
                    'vulnerability' => 'Keine Password-Policy + kein Secrets-Manager',
                    'category' => 'operational',
                    'inherent_likelihood' => 4,
                    'inherent_impact' => 4,
                    'treatment_strategy' => 'mitigate',
                ],
            ],
            'preset_assets' => [
                ['name' => 'Office365 / Google Workspace', 'asset_type' => 'software', 'owner' => 'IT-Leitung', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 4, 'description' => 'E-Mail + Kollaboration + File-Storage'],
                ['name' => 'Mitarbeiter-Laptops', 'asset_type' => 'hardware', 'owner' => 'IT-Leitung', 'confidentiality' => 3, 'integrity' => 3, 'availability' => 3, 'description' => 'Notebook-Pool für Mitarbeiter'],
                ['name' => 'Kunden-Datenbank (CRM)', 'asset_type' => 'software', 'owner' => 'Head of Sales', 'confidentiality' => 4, 'integrity' => 4, 'availability' => 3, 'description' => 'Kundenstammdaten + Historie'],
                ['name' => 'Finanzbuchhaltung', 'asset_type' => 'software', 'owner' => 'CFO', 'confidentiality' => 4, 'integrity' => 5, 'availability' => 3, 'description' => 'DATEV / vergleichbares Fibu-System'],
                ['name' => 'Backup-Speicher', 'asset_type' => 'hardware', 'owner' => 'IT-Leitung', 'confidentiality' => 3, 'integrity' => 5, 'availability' => 3, 'description' => 'NAS / Cloud-Backup für Unternehmensdaten'],
            ],
            'preset_applicable_controls' => [
                'A.5.1', 'A.5.2', 'A.5.3', 'A.5.9', 'A.5.12', 'A.5.15', 'A.5.17', 'A.5.23',
                'A.6.3', 'A.6.5', 'A.6.6',
                'A.7.1', 'A.7.4',
                'A.8.1', 'A.8.2', 'A.8.3', 'A.8.5', 'A.8.8', 'A.8.13', 'A.8.23',
            ],
        ];
    }
}
