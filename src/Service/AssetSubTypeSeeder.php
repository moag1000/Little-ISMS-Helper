<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssetSubType;
use App\Entity\Tenant;
use App\Repository\AssetSubTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seed preset library for AssetSubType (S18 B2).
 *
 * Three industry presets:
 *  - bsi-grundschutz: BSI IT-Grundschutz Zielobjekt-Typen (28 entries)
 *  - tisax:           Automotive/Engineering (TISAX VDA-ISA) (15 entries)
 *  - production-de-mittelstand: OT/Manufacturing-heavy Mittelstand (22 entries)
 *
 * Seed-Entries get `source = 'seed-<preset>'` for downstream audit trail.
 * Existing rows (by uniq tenant+top_type+name) are skipped — idempotent re-runs OK.
 */
final class AssetSubTypeSeeder
{
    public const PRESET_BSI = 'bsi-grundschutz';
    public const PRESET_TISAX = 'tisax';
    public const PRESET_PRODUCTION_DE = 'production-de-mittelstand';

    public const PRESETS = [
        self::PRESET_BSI,
        self::PRESET_TISAX,
        self::PRESET_PRODUCTION_DE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetSubTypeRepository $repository,
    ) {
    }

    /**
     * @return list<string>
     */
    public function availablePresets(): array
    {
        return self::PRESETS;
    }

    /**
     * Apply preset to tenant. Returns counts.
     *
     * @return array{preset: string, created: int, skipped: int, total: int}
     */
    public function applyPreset(Tenant $tenant, string $preset): array
    {
        $entries = $this->presetData($preset);
        $source = 'seed-' . $preset;

        $created = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $existing = $this->repository->findOneBy([
                'tenant' => $tenant,
                'topType' => $entry['topType'],
                'name' => $entry['name'],
            ]);

            if ($existing !== null) {
                ++$skipped;
                continue;
            }

            $subType = new AssetSubType();
            $subType->setTenant($tenant);
            $subType->setTopType($entry['topType']);
            $subType->setName($entry['name']);
            $subType->setDescription($entry['description'] ?? null);
            $subType->setSource($source);
            $subType->setIsActive(true);

            $this->em->persist($subType);
            ++$created;
        }

        $this->em->flush();

        return [
            'preset' => $preset,
            'created' => $created,
            'skipped' => $skipped,
            'total' => count($entries),
        ];
    }

    /**
     * @return list<array{topType: string, name: string, description?: string}>
     */
    public function presetData(string $preset): array
    {
        return match ($preset) {
            self::PRESET_BSI => $this->bsiGrundschutzEntries(),
            self::PRESET_TISAX => $this->tisaxEntries(),
            self::PRESET_PRODUCTION_DE => $this->productionDeEntries(),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown preset "%s". Available: %s',
                $preset,
                implode(', ', self::PRESETS),
            )),
        };
    }

    /**
     * BSI IT-Grundschutz Zielobjekt-Typen (Kompendium Edition 2023).
     *
     * @return list<array{topType: string, name: string, description?: string}>
     */
    private function bsiGrundschutzEntries(): array
    {
        return [
            // Hardware
            ['topType' => 'Hardware', 'name' => 'Server', 'description' => 'Server-System (physisch oder virtuell) — BSI SYS.1'],
            ['topType' => 'Hardware', 'name' => 'Workstation', 'description' => 'Arbeitsplatz-Computer — BSI SYS.2.1'],
            ['topType' => 'Hardware', 'name' => 'Notebook', 'description' => 'Mobiler Arbeitsplatz — BSI SYS.2.2'],
            ['topType' => 'Hardware', 'name' => 'Drucker', 'description' => 'Drucker / Multifunktionsgerät — BSI SYS.4.1'],
            ['topType' => 'Hardware', 'name' => 'Netzkomponente', 'description' => 'Switch, Router, Firewall — BSI NET.3.x'],
            ['topType' => 'Hardware', 'name' => 'USB-Storage', 'description' => 'Wechseldatenträger — BSI SYS.4.5'],
            ['topType' => 'Hardware', 'name' => 'IoT-Gerät', 'description' => 'IoT-Endgerät — BSI SYS.4.4'],
            ['topType' => 'Hardware', 'name' => 'ICS-Komponente', 'description' => 'Industrial Control System — BSI IND.x'],
            ['topType' => 'Hardware', 'name' => 'Mobilgerät', 'description' => 'Smartphone, Tablet — BSI SYS.3.2'],

            // Software
            ['topType' => 'Software', 'name' => 'Betriebssystem', 'description' => 'Server- oder Client-OS — BSI SYS.1.x / SYS.2.x'],
            ['topType' => 'Software', 'name' => 'Anwendung', 'description' => 'Fachanwendung — BSI APP.x'],
            ['topType' => 'Software', 'name' => 'Datenbankmanagementsystem', 'description' => 'DBMS (MySQL, Oracle, …) — BSI APP.4.3'],
            ['topType' => 'Software', 'name' => 'Cloud-Service', 'description' => 'SaaS / PaaS / IaaS — BSI OPS.2.2'],

            // Datenbank (Information Asset)
            ['topType' => 'Datenbank', 'name' => 'Personendaten', 'description' => 'Personenbezogene Daten — DSGVO Art. 4'],
            ['topType' => 'Datenbank', 'name' => 'Geschäftsdaten', 'description' => 'Geschäftliche Stamm- und Bewegungsdaten'],
            ['topType' => 'Datenbank', 'name' => 'Konfigurationsdaten', 'description' => 'System- und Anwendungskonfiguration — BSI OPS.1.1.1'],
            ['topType' => 'Datenbank', 'name' => 'Logdaten', 'description' => 'Protokoll- und Audit-Daten — BSI OPS.1.1.5'],

            // Personal
            ['topType' => 'Personal', 'name' => 'Beschäftigter', 'description' => 'Interne Mitarbeitende — BSI ORP.2'],
            ['topType' => 'Personal', 'name' => 'Externer', 'description' => 'Externe Dienstleister, Berater — BSI ORP.2'],
            ['topType' => 'Personal', 'name' => 'Fachverantwortlicher', 'description' => 'Fachverantwortliche Person — ISO 27001 A.6.2'],
            ['topType' => 'Personal', 'name' => 'Administrator', 'description' => 'Privilegierter Account-Inhaber — BSI ORP.2 / ISO A.5.16'],

            // Standort
            ['topType' => 'Standort', 'name' => 'Bürogebäude', 'description' => 'Standard-Büroumgebung — BSI INF.7'],
            ['topType' => 'Standort', 'name' => 'Rechenzentrum', 'description' => 'Rechenzentrum / Serverraum — BSI INF.2'],
            ['topType' => 'Standort', 'name' => 'Heimarbeitsplatz', 'description' => 'Home-Office-Arbeitsplatz — BSI INF.8'],
            ['topType' => 'Standort', 'name' => 'Filiale', 'description' => 'Außenstelle / Niederlassung — BSI INF.7'],

            // Dienstleistung
            ['topType' => 'Dienstleistung', 'name' => 'Cloud-Dienst', 'description' => 'Externer Cloud-Provider — BSI OPS.2.2'],
            ['topType' => 'Dienstleistung', 'name' => 'Wartung', 'description' => 'Externe Wartung / Support — BSI OPS.2.1'],
            ['topType' => 'Dienstleistung', 'name' => 'Outsourcing', 'description' => 'Outsourcing-Dienstleister — BSI OPS.2.1'],
            ['topType' => 'Dienstleistung', 'name' => 'ISP', 'description' => 'Internet-/Telekom-Provider — BSI NET.4.1'],
        ];
    }

    /**
     * TISAX / VDA-ISA spezifisch — Automotive Engineering.
     *
     * @return list<array{topType: string, name: string, description?: string}>
     */
    private function tisaxEntries(): array
    {
        return [
            // Hardware
            ['topType' => 'Hardware', 'name' => 'Engineering-Workstation', 'description' => 'CAD/CAE-Arbeitsplatz — TISAX 1.5.x'],
            ['topType' => 'Hardware', 'name' => 'Test-Equipment', 'description' => 'Prüf- und Messgerät — TISAX Prototypenschutz'],
            ['topType' => 'Hardware', 'name' => 'Prototyp', 'description' => 'Fahrzeug- / Bauteil-Prototyp — TISAX PT-Anhang'],
            ['topType' => 'Hardware', 'name' => 'Diagnose-Tester', 'description' => 'Fahrzeug-Diagnosegerät — TISAX 5.2.x'],

            // Software
            ['topType' => 'Software', 'name' => 'PLM-System', 'description' => 'Product Lifecycle Management'],
            ['topType' => 'Software', 'name' => 'CAD/CAE', 'description' => 'Computer-Aided Design/Engineering'],
            ['topType' => 'Software', 'name' => 'MES', 'description' => 'Manufacturing Execution System'],
            ['topType' => 'Software', 'name' => 'Simulations-Tool', 'description' => 'FEM-/MKS-Simulationssoftware'],

            // Datenbank
            ['topType' => 'Datenbank', 'name' => 'Konstruktionsdaten', 'description' => 'CAD-Modelle, Zeichnungen — TISAX VS-NfD'],
            ['topType' => 'Datenbank', 'name' => 'Prototypen-Information', 'description' => 'Prototypen-Spezifikationen — TISAX PT'],
            ['topType' => 'Datenbank', 'name' => 'OEM-Vertraulich', 'description' => 'OEM-Vertrauliche Informationen — TISAX VL3'],

            // Standort
            ['topType' => 'Standort', 'name' => 'Prototypenwerkstatt', 'description' => 'Geschützter Prototypen-Bereich — TISAX PT'],
            ['topType' => 'Standort', 'name' => 'Testfeld', 'description' => 'Erprobungs- und Testgelände'],

            // Dienstleistung
            ['topType' => 'Dienstleistung', 'name' => 'Engineering-Dienstleister', 'description' => 'Externe Entwicklungsdienstleister'],
            ['topType' => 'Dienstleistung', 'name' => 'Logistik (Prototypen)', 'description' => 'Prototypen-Transport / Verschleierung'],
        ];
    }

    /**
     * Production / Mittelstand DE — OT-/Manufacturing-heavy.
     *
     * @return list<array{topType: string, name: string, description?: string}>
     */
    private function productionDeEntries(): array
    {
        return [
            // Hardware (OT-focused)
            ['topType' => 'Hardware', 'name' => 'Server', 'description' => 'Server-System (physisch / virtuell)'],
            ['topType' => 'Hardware', 'name' => 'Workstation', 'description' => 'Büro-Arbeitsplatz'],
            ['topType' => 'Hardware', 'name' => 'SPS/PLC', 'description' => 'Speicherprogrammierbare Steuerung — IEC 62443'],
            ['topType' => 'Hardware', 'name' => 'HMI-Panel', 'description' => 'Human-Machine-Interface Panel'],
            ['topType' => 'Hardware', 'name' => 'Sensor', 'description' => 'Industrie-Sensor (IO-Link, Profinet, …)'],
            ['topType' => 'Hardware', 'name' => 'Sicherheitsschaltgerät', 'description' => 'Safety-Relais / NotAus-Schalter — ISO 13849'],
            ['topType' => 'Hardware', 'name' => 'Roboterzelle', 'description' => 'Industrieroboter mit Zellumhausung'],
            ['topType' => 'Hardware', 'name' => 'Werkzeugmaschine', 'description' => 'CNC-Fräs-/Drehmaschine'],
            ['topType' => 'Hardware', 'name' => 'Drucker', 'description' => 'Büro- / Etikettendrucker'],

            // Software
            ['topType' => 'Software', 'name' => 'ERP', 'description' => 'Enterprise Resource Planning (SAP, ProAlpha, …)'],
            ['topType' => 'Software', 'name' => 'MES', 'description' => 'Manufacturing Execution System'],
            ['topType' => 'Software', 'name' => 'SCADA', 'description' => 'Supervisory Control and Data Acquisition'],
            ['topType' => 'Software', 'name' => 'CAQ', 'description' => 'Computer-Aided Quality (QM-Software)'],

            // Datenbank
            ['topType' => 'Datenbank', 'name' => 'Rezepturdaten', 'description' => 'Produktionsrezepturen / Maschinenparameter'],
            ['topType' => 'Datenbank', 'name' => 'Auftragsdaten', 'description' => 'Auftrags-/Kundendaten'],
            ['topType' => 'Datenbank', 'name' => 'Konstruktionsdaten', 'description' => 'CAD-Zeichnungen, Stücklisten'],

            // Personal
            ['topType' => 'Personal', 'name' => 'Beschäftigter', 'description' => 'Festangestellte Mitarbeitende'],
            ['topType' => 'Personal', 'name' => 'Externer', 'description' => 'Externe Servicekräfte / Leiharbeit'],

            // Standort
            ['topType' => 'Standort', 'name' => 'Bürogebäude', 'description' => 'Verwaltungsstandort'],
            ['topType' => 'Standort', 'name' => 'Produktionshalle', 'description' => 'OT-Produktionsumgebung'],
            ['topType' => 'Standort', 'name' => 'Lager', 'description' => 'Material- / Fertigwarenlager'],

            // Dienstleistung
            ['topType' => 'Dienstleistung', 'name' => 'Wartung', 'description' => 'Externe Maschinen- / IT-Wartung'],
        ];
    }
}
