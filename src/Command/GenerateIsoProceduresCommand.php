<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:generate-iso-procedures',
    description: 'Generate ISO 27001 procedure templates for ISMS management system',
)]
class GenerateIsoProceduresCommand extends Command
{
    private Filesystem $filesystem;

    public function __construct()
    {
        parent::__construct();
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Output directory for templates', 'var/iso_procedures')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (markdown, html)', 'markdown')
            ->setHelp('This command generates ISO 27001 procedure templates to help establish a complete ISMS management system.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $input->getOption('output-dir');
        $format = $input->getOption('format');

        $io->title('ISO 27001 Procedure Templates Generator');
        $io->text('Generating comprehensive ISMS procedure templates...');

        // Create output directory
        if (!$this->filesystem->exists($outputDir)) {
            $this->filesystem->mkdir($outputDir);
            $io->success("Created output directory: $outputDir");
        }

        $procedures = $this->getProcedureTemplates();
        $generatedCount = 0;

        foreach ($procedures as $category => $categoryProcedures) {
            $categoryDir = "$outputDir/$category";
            $this->filesystem->mkdir($categoryDir);

            foreach ($categoryProcedures as $procedureName => $procedureContent) {
                $filename = match ($format) {
                    'html' => "$categoryDir/$procedureName.html",
                    default => "$categoryDir/$procedureName.md",
                };

                $content = match ($format) {
                    'html' => $this->convertToHtml($procedureContent),
                    default => $procedureContent,
                };

                $this->filesystem->dumpFile($filename, $content);
                $generatedCount++;
            }
        }

        $io->success("Successfully generated $generatedCount procedure templates in: $outputDir");
        $io->table(
            ['Category', 'Procedures'],
            array_map(fn($cat, $procs) => [$cat, count($procs)], array_keys($procedures), $procedures)
        );

        return Command::SUCCESS;
    }

    private function getProcedureTemplates(): array
    {
        return [
            '01_Information_Security_Policies' => [
                'ISMS_Policy' => $this->getIsmsPolicyTemplate(),
                'Information_Security_Policy' => $this->getInfoSecPolicyTemplate(),
                'Acceptable_Use_Policy' => $this->getAcceptableUsePolicyTemplate(),
            ],
            '02_Organization_of_Information_Security' => [
                'Roles_and_Responsibilities' => $this->getRolesResponsibilitiesTemplate(),
                'Segregation_of_Duties' => $this->getSegregationDutiesTemplate(),
                'Contact_with_Authorities' => $this->getContactAuthoritiesTemplate(),
            ],
            '03_Human_Resource_Security' => [
                'Screening_Procedure' => $this->getScreeningTemplate(),
                'Terms_and_Conditions_of_Employment' => $this->getEmploymentTermsTemplate(),
                'Security_Awareness_Training' => $this->getAwarenessTrainingTemplate(),
                'Disciplinary_Process' => $this->getDisciplinaryProcessTemplate(),
            ],
            '04_Asset_Management' => [
                'Asset_Inventory_Procedure' => $this->getAssetInventoryTemplate(),
                'Asset_Classification' => $this->getAssetClassificationTemplate(),
                'Media_Handling' => $this->getMediaHandlingTemplate(),
            ],
            '05_Access_Control' => [
                'Access_Control_Policy' => $this->getAccessControlPolicyTemplate(),
                'User_Access_Management' => $this->getUserAccessManagementTemplate(),
                'Password_Management' => $this->getPasswordManagementTemplate(),
                'Privileged_Access_Management' => $this->getPrivilegedAccessTemplate(),
            ],
            '06_Cryptography' => [
                'Cryptographic_Controls' => $this->getCryptographicControlsTemplate(),
                'Key_Management' => $this->getKeyManagementTemplate(),
            ],
            '07_Physical_and_Environmental_Security' => [
                'Physical_Security_Perimeter' => $this->getPhysicalSecurityTemplate(),
                'Secure_Areas' => $this->getSecureAreasTemplate(),
                'Equipment_Security' => $this->getEquipmentSecurityTemplate(),
            ],
            '08_Operations_Security' => [
                'Change_Management' => $this->getChangeManagementTemplate(),
                'Capacity_Management' => $this->getCapacityManagementTemplate(),
                'Malware_Protection' => $this->getMalwareProtectionTemplate(),
                'Backup_Procedure' => $this->getBackupProcedureTemplate(),
                'Logging_and_Monitoring' => $this->getLoggingMonitoringTemplate(),
            ],
            '09_Communications_Security' => [
                'Network_Security_Management' => $this->getNetworkSecurityTemplate(),
                'Information_Transfer' => $this->getInfoTransferTemplate(),
            ],
            '10_System_Acquisition_Development_Maintenance' => [
                'Secure_Development_Lifecycle' => $this->getSecureSDLCTemplate(),
                'Security_in_Development' => $this->getSecDevTemplate(),
                'Test_Data_Management' => $this->getTestDataTemplate(),
            ],
            '11_Supplier_Relationships' => [
                'Supplier_Security' => $this->getSupplierSecurityTemplate(),
                'Service_Delivery_Management' => $this->getServiceDeliveryTemplate(),
            ],
            '12_Incident_Management' => [
                'Incident_Response_Procedure' => $this->getIncidentResponseTemplate(),
                'Evidence_Collection' => $this->getEvidenceCollectionTemplate(),
            ],
            '13_Business_Continuity_Management' => [
                'Business_Continuity_Planning' => $this->getBCPTemplate(),
                'ICT_Readiness_for_Business_Continuity' => $this->getICTReadinessTemplate(),
            ],
            '14_Compliance' => [
                'Legal_and_Regulatory_Requirements' => $this->getLegalComplianceTemplate(),
                'Internal_Audit_Procedure' => $this->getInternalAuditTemplate(),
                'Management_Review_Procedure' => $this->getManagementReviewTemplate(),
            ],
        ];
    }

    // Template methods with detailed German content

    private function getIsmsPolicyTemplate(): string
    {
        return <<<'MD'
# ISMS-Richtlinie (ISO 27001)

## 1. Zweck
Diese Richtlinie definiert den Rahmen für das Informationssicherheits-Managementsystem (ISMS) gemäß ISO 27001:2022.

## 2. Geltungsbereich
Diese Richtlinie gilt für:
- Alle Mitarbeiter, Auftragnehmer und Dritte
- Alle Informationsverarbeitungssysteme
- [Definieren Sie den ISMS-Geltungsbereich]

## 3. ISMS-Ziele
1. Vertraulichkeit, Integrität und Verfügbarkeit von Informationen sicherstellen
2. Compliance mit gesetzlichen und vertraglichen Anforderungen
3. Kontinuierliche Verbesserung der Informationssicherheit
4. Schutz vor Sicherheitsvorfällen

## 4. ISMS-Rahmenwerk
Das ISMS basiert auf dem PDCA-Zyklus:
- **Plan**: Risikobewertung, Ziele setzen
- **Do**: Umsetzung der Controls
- **Check**: Überwachung und Messung
- **Act**: Kontinuierliche Verbesserung

## 5. Verantwortlichkeiten
- **Geschäftsleitung**: Bereitstellung von Ressourcen, strategische Ausrichtung
- **ISMS-Verantwortlicher**: Implementierung und Wartung des ISMS
- **Mitarbeiter**: Einhaltung der Sicherheitsrichtlinien

## 6. Risikomanagement
- Regelmäßige Risikobewertungen durchführen
- Risikobehandlungspläne erstellen und umsetzen
- Restrisiken akzeptieren und dokumentieren

## 7. Überprüfung und Verbesserung
- Interne Audits: mindestens jährlich
- Management-Review: mindestens jährlich
- Kontinuierliche Überwachung der Wirksamkeit

## 8. Version
- Version: 1.0
- Datum: [Datum]
- Nächste Überprüfung: [Datum + 1 Jahr]
MD;
    }

    private function getInfoSecPolicyTemplate(): string
    {
        return <<<'MD'
# Informationssicherheitsrichtlinie

## 1. Zweck
Definition der Grundsätze und Anforderungen für die Informationssicherheit in der Organisation.

## 2. Sicherheitsgrundsätze

### 2.1 Vertraulichkeit
- Zugriff nur für autorisierte Personen
- Klassifizierung und Kennzeichnung von Informationen
- Verschlüsselung sensibler Daten

### 2.2 Integrität
- Schutz vor unbefugter Änderung
- Validierung und Prüfung von Daten
- Versionskontrolle

### 2.3 Verfügbarkeit
- Sicherstellung der Verfügbarkeit von Systemen
- Redundanz und Backup-Strategien
- Disaster Recovery

## 3. Klassifizierung von Informationen
1. **Streng vertraulich**: Höchster Schutz
2. **Vertraulich**: Eingeschränkter Zugriff
3. **Intern**: Nur für Mitarbeiter
4. **Öffentlich**: Keine Einschränkungen

## 4. Zugriffskontrolle
- Principle of Least Privilege
- Need-to-Know-Prinzip
- Regelmäßige Überprüfung von Zugriffsrechten

## 5. Physische Sicherheit
- Zutrittskontrolle zu sensiblen Bereichen
- Schutz von Hardware und Datenträgern
- Clear Desk Policy

## 6. Compliance
- Einhaltung von DSGVO, TISAX, NIS2
- Regelmäßige Compliance-Prüfungen
- Dokumentation von Verstößen

## 7. Sanktionen
Verstöße gegen diese Richtlinie können zu disziplinarischen Maßnahmen führen.
MD;
    }

    private function getAcceptableUsePolicyTemplate(): string
    {
        return <<<'MD'
# Acceptable Use Policy (AUP)

## 1. Zweck
Regelung der akzeptablen Nutzung von IT-Ressourcen.

## 2. Geltungsbereich
Alle IT-Systeme, Geräte und Dienste der Organisation.

## 3. Erlaubte Nutzung
- Geschäftliche Kommunikation
- Zugelassene Software und Anwendungen
- Berechtigte Datenverarbeitung

## 4. Verbotene Aktivitäten
- Unbefugte Weitergabe von Zugangsdaten
- Installation nicht autorisierter Software
- Zugriff auf illegale oder unangemessene Inhalte
- Umgehung von Sicherheitsmaßnahmen
- Private Nutzung sensibler Daten

## 5. Internet- und E-Mail-Nutzung
- Geschäftliche Nutzung hat Vorrang
- Private Nutzung nur in angemessenem Rahmen
- Verbot von Spam und Phishing
- Vorsicht bei Downloads und Anhängen

## 6. Mobile Geräte
- Verwendung nur autorisierter Geräte
- Geräteverschlüsselung erforderlich
- Meldung bei Verlust oder Diebstahl

## 7. Überwachung
Die Organisation behält sich das Recht vor, die Nutzung zu überwachen.

## 8. Konsequenzen
Verstöße können zu Sanktionen bis hin zur Kündigung führen.
MD;
    }

    private function getRolesResponsibilitiesTemplate(): string
    {
        return <<<'MD'
# Rollen und Verantwortlichkeiten

## 1. Geschäftsleitung
- Bereitstellung von Ressourcen für das ISMS
- Festlegung der Informationssicherheitsziele
- Management-Review durchführen
- Vorbildfunktion in Sicherheitsfragen

## 2. ISMS-Verantwortlicher / Information Security Officer
- Implementierung und Wartung des ISMS
- Koordination von Sicherheitsmaßnahmen
- Durchführung von Risikoanalysen
- Berichterstattung an die Geschäftsleitung

## 3. IT-Leitung
- Technische Umsetzung von Security Controls
- Verwaltung der IT-Infrastruktur
- Patch-Management und Updates
- Incident Response

## 4. Datenschutzbeauftragter
- Einhaltung der DSGVO
- Beratung in Datenschutzfragen
- Zusammenarbeit mit Aufsichtsbehörden
- Schulung der Mitarbeiter

## 5. Asset Owner
- Verantwortung für bestimmte Assets
- Klassifizierung von Informationen
- Genehmigung von Zugriffsrechten
- Sicherstellung der Compliance

## 6. Alle Mitarbeiter
- Einhaltung der Sicherheitsrichtlinien
- Meldung von Sicherheitsvorfällen
- Teilnahme an Schulungen
- Schutz von Zugangsdaten
MD;
    }

    private function getSegregationDutiesTemplate(): string
    {
        return <<<'MD'
# Funktionstrennung (Segregation of Duties)

## 1. Zweck
Verhinderung von Betrug und Fehlern durch Aufteilung kritischer Funktionen.

## 2. Prinzipien
- Keine Person sollte alleinige Kontrolle über kritische Prozesse haben
- Vier-Augen-Prinzip für kritische Operationen
- Trennung von Entwicklung, Test und Produktion

## 3. Kritische Funktionen
- Genehmigung und Ausführung
- Entwicklung und Betrieb (DevOps-Ausnahmen dokumentieren)
- Sicherheitsadministration und Audit
- Backup und Restore

## 4. Implementierung
- Rollenbasierte Zugriffskontrolle (RBAC)
- Dokumentation von Ausnahmen
- Kompensatorische Controls bei Ressourcenmangel

## 5. Überwachung
- Regelmäßige Überprüfung der Rollenzuweisungen
- Audit-Logs für kritische Operationen
MD;
    }

    private function getContactAuthoritiesTemplate(): string
    {
        return <<<'MD'
# Kontakt mit Behörden und Interessengruppen

## 1. Zuständige Behörden

### Datenschutz
- Datenschutzaufsichtsbehörde
- [Kontaktdaten einfügen]

### Cybersecurity
- BSI (Bundesamt für Sicherheit in der Informationstechnik)
- [Kontaktdaten einfügen]

### Strafverfolgung
- Polizei / Staatsanwaltschaft
- [Kontaktdaten einfügen]

## 2. Fachverbände
- Branchenspezifische Sicherheitsforen
- CERT / CSIRT Netzwerke

## 3. Kommunikationsverfahren
- Eskalationspfade definieren
- Autorisierte Sprecher benennen
- Vertraulichkeitsvereinbarungen beachten
MD;
    }

    private function getScreeningTemplate(): string
    {
        return <<<'MD'
# Screening-Verfahren für neue Mitarbeiter

## 1. Zweck
Sicherstellung, dass neue Mitarbeiter vertrauenswürdig und qualifiziert sind.

## 2. Verfahren

### 2.1 Vorvertraglich
- Überprüfung von Referenzen
- Verifizierung von Qualifikationen
- Hintergrundprüfung (soweit rechtlich zulässig)

### 2.2 Bei Einstellung
- Unterzeichnung von Geheimhaltungsvereinbarungen
- Sicherheitsbelehrung
- Zugang nur nach erfolgreichem Screening

## 3. Risikostufen
- **Hoch**: Zugriff auf kritische Systeme → umfassende Prüfung
- **Mittel**: Standardprüfung
- **Niedrig**: Basisprüfung

## 4. Dokumentation
Alle Prüfungen sind zu dokumentieren und datenschutzkonform aufzubewahren.
MD;
    }

    private function getEmploymentTermsTemplate(): string
    {
        return <<<'MD'
# Arbeitsvertragliche Regelungen zur Informationssicherheit

## 1. Vertraulichkeitsvereinbarung (NDA)
- Geheimhaltung von Geschäftsinformationen
- Geltungsdauer auch nach Beendigung

## 2. Acceptable Use Policy
- Verpflichtung zur Einhaltung der IT-Nutzungsrichtlinien
- Verbot privater Nutzung sensibler Ressourcen

## 3. Intellectual Property
- Übertragung von Urheberrechten an der Organisation
- Offenlegung von Erfindungen

## 4. Post-Employment
- Rückgabe von Geräten und Zugangsmitteln
- Löschung von Daten auf privaten Geräten
- Fortbestehende Geheimhaltungspflichten
MD;
    }

    private function getAwarenessTrainingTemplate(): string
    {
        return <<<'MD'
# Security Awareness Training

## 1. Zielsetzung
Sensibilisierung aller Mitarbeiter für Informationssicherheitsrisiken.

## 2. Schulungsinhalte

### Grundlagenschulung (jährlich für alle)
- Informationssicherheitsrichtlinien
- Phishing-Erkennung
- Passwort-Sicherheit
- Social Engineering
- Incident Reporting

### Spezialschulungen
- **Entwickler**: Secure Coding
- **Administratoren**: Hardening, Patch Management
- **Management**: Risikomanagement, Compliance

## 3. Methoden
- E-Learning-Module
- Präsenzschulungen
- Simulated Phishing Campaigns
- Sicherheitshinweise und Newsletter

## 4. Erfolgsmessung
- Teilnahmequote: > 95%
- Bestehen von Tests
- Reduktion von Sicherheitsvorfällen

## 5. Dokumentation
- Schulungsnachweise aufbewahren
- Aktualisierung bei Richtlinienänderungen
MD;
    }

    private function getDisciplinaryProcessTemplate(): string
    {
        return <<<'MD'
# Disziplinarverfahren bei Sicherheitsverstößen

## 1. Zweck
Regelung des Umgangs mit Verstößen gegen Sicherheitsrichtlinien.

## 2. Kategorisierung von Verstößen

### Geringfügig
- Erste Verstöße ohne böse Absicht
- Maßnahme: Ermahnung und Schulung

### Schwerwiegend
- Wiederholte Verstöße
- Vorsätzliche Umgehung von Controls
- Maßnahme: Abmahnung, Zugriffsentzug

### Kritisch
- Böswillige Handlungen
- Datenlecks durch Fahrlässigkeit
- Maßnahme: Kündigung, rechtliche Schritte

## 3. Verfahren
1. Dokumentation des Verstoßes
2. Untersuchung und Beweissicherung
3. Anhörung des Mitarbeiters
4. Entscheidung und Maßnahme
5. Dokumentation im Personalakt

## 4. Rechtliche Aspekte
- Einhaltung des Arbeitsrechts
- Beteiligung des Betriebsrats
- Datenschutz beachten
MD;
    }

    // Additional template methods would continue here...
    // For brevity, I'll add a few more key ones

    private function getAssetInventoryTemplate(): string
    {
        return <<<'MD'
# Asset Inventory Procedure

## 1. Zweck
Verwaltung und Nachverfolgung aller informationsverarbeitenden Assets.

## 2. Asset-Kategorien
- Hardware (Server, Workstations, Mobile Geräte)
- Software (Anwendungen, Lizenzen)
- Daten (Datenbanken, Dateien)
- Services (Cloud-Dienste)
- Personen (Schlüsselpersonen)

## 3. Erfassung
- Zentrale Asset-Datenbank pflegen
- Verantwortliche Personen zuordnen
- Klassifizierung durchführen
- Standort dokumentieren

## 4. Lifecycle Management
- Beschaffung → Genehmigungsprozess
- Betrieb → Wartung und Updates
- Außerbetriebnahme → Sichere Entsorgung

## 5. Überprüfung
Mindestens jährliche Inventur durchführen.
MD;
    }

    private function getAccessControlPolicyTemplate(): string
    {
        return <<<'MD'
# Access Control Policy

## 1. Grundprinzipien
- Principle of Least Privilege
- Need-to-Know
- Segregation of Duties
- Default Deny

## 2. User Access Management
- Formaler Antragsprozess
- Genehmigung durch Asset Owner
- Regelmäßige Rezertifizierung (mindestens jährlich)

## 3. Authentifizierung
- Multi-Factor Authentication für privilegierte Zugriffe
- Starke Passwortrichtlinien
- Single Sign-On (SSO) wo möglich

## 4. Privileged Access
- Just-in-Time Access
- Protokollierung aller privilegierten Operationen
- Regelmäßige Überprüfung

## 5. Remote Access
- VPN-Pflicht
- Endpoint-Compliance-Checks
- Verschlüsselte Verbindungen

## 6. Zugriffsentzug
- Automatischer Entzug bei Ausscheiden
- Suspension bei Verdacht auf Missbrauch
MD;
    }

    private function getIncidentResponseTemplate(): string
    {
        return <<<'MD'
# Incident Response Procedure

## 1. Incident-Kategorien
- **P1 - Kritisch**: Massive Auswirkungen (z.B. Ransomware)
- **P2 - Hoch**: Erhebliche Auswirkungen
- **P3 - Mittel**: Begrenzte Auswirkungen
- **P4 - Niedrig**: Geringe Auswirkungen

## 2. Incident Response Team
- Incident Manager
- IT-Security
- IT-Operations
- Legal/Compliance
- Communications

## 3. Response-Phasen

### 3.1 Identifikation
- Incident Detection durch SIEM, Alerts, Meldungen
- Klassifizierung und Priorisierung
- Incident Ticket erstellen

### 3.2 Containment
- Betroffene Systeme isolieren
- Schaden begrenzen
- Logs sichern

### 3.3 Eradication
- Ursache beseitigen
- Malware entfernen
- Schwachstellen schließen

### 3.4 Recovery
- Systeme wiederherstellen
- Funktionalität verifizieren
- Monitoring verstärken

### 3.5 Lessons Learned
- Post-Incident Review
- Dokumentation
- Maßnahmen zur Vermeidung

## 4. Kommunikation
- Interne Stakeholder informieren
- Externe Meldepflichten beachten (DSGVO, NIS2)
- Kunden informieren bei Datenschutzverletzungen

## 5. Eskalation
- P1: Sofortige Eskalation an Management
- P2: Eskalation innerhalb von 2 Stunden
- P3/P4: Standard-Prozess
MD;
    }

    private function getBCPTemplate(): string
    {
        return <<<'MD'
# Business Continuity Plan

## 1. Zweck
Sicherstellung der Geschäftskontinuität bei Störungen.

## 2. Business Impact Analysis (BIA)
- Kritische Geschäftsprozesse identifizieren
- Recovery Time Objective (RTO) definieren
- Recovery Point Objective (RPO) festlegen

## 3. Kontinuitätsstrategien

### Technisch
- Backup und Disaster Recovery
- Redundante Systeme
- Failover-Mechanismen

### Organisatorisch
- Alternative Arbeitsplätze
- Notfallteams
- Kommunikationspläne

## 4. Notfallpläne
- Dokumentation von Wiederherstellungsschritten
- Kontaktlisten
- Ressourcenlisten

## 5. Testing
- Mindestens jährliche Tests
- Tabletop-Übungen
- Full-Scale-Tests für kritische Systeme

## 6. Wartung
- Regelmäßige Aktualisierung der Pläne
- Anpassung an Änderungen in der Organisation
MD;
    }

    private function getInternalAuditTemplate(): string
    {
        return <<<'MD'
# Internal Audit Procedure

## 1. Zweck
Überprüfung der Wirksamkeit des ISMS durch unabhängige interne Audits.

## 2. Auditplanung
- Jährlicher Auditplan
- Alle Bereiche mindestens einmal pro Zertifizierungszyklus (3 Jahre)
- Risikobasierte Priorisierung

## 3. Auditorenqualifikation
- Unabhängigkeit vom geprüften Bereich
- ISO 27001 Kenntnisse
- Schulung in Auditmethoden

## 4. Auditdurchführung
- Auditplanung und -vorbereitung
- Opening Meeting
- Dokumentenprüfung und Interviews
- Vor-Ort-Begehungen
- Closing Meeting

## 5. Audit-Ergebnisse
- Nichtkonformitäten (Major/Minor)
- Verbesserungspotenziale
- Auditbericht

## 6. Nachverfolgung
- Corrective Action Plans
- Verification of Implementation
- Follow-up Audits

## 7. Management-Review
Audit-Ergebnisse als Input für Management-Review.
MD;
    }

    private function getManagementReviewTemplate(): string
    {
        return <<<'MD'
# Management Review Procedure

## 1. Zweck
Regelmäßige Bewertung des ISMS durch die Geschäftsleitung.

## 2. Häufigkeit
Mindestens jährlich oder bei wesentlichen Änderungen.

## 3. Inputs
- Audit-Ergebnisse
- Sicherheitsvorfälle
- Leistungskennzahlen (KPIs)
- Zielerreichung
- Änderungen in externen/internen Kontexten
- Feedback von Interessengruppen
- Ergebnisse von Risikoanalysen

## 4. Outputs
- Entscheidungen zur kontinuierlichen Verbesserung
- Ressourcenzuteilung
- Anpassungen an ISMS-Zielen
- Aktualisierung der Risikobehandlung

## 5. Dokumentation
- Management-Review-Protokoll
- Maßnahmenliste mit Verantwortlichen und Fristen
- Aufbewahrung als Nachweis

## 6. Nachverfolgung
Umsetzung der beschlossenen Maßnahmen überwachen.
MD;
    }

    // Additional simplified templates for remaining procedures

    private function getAssetClassificationTemplate(): string { return $this->getGenericTemplate('Asset Classification', 'Klassifizierung von Assets nach Schutzbedarf'); }
    private function getMediaHandlingTemplate(): string { return $this->getGenericTemplate('Media Handling', 'Umgang mit Speichermedien'); }
    private function getUserAccessManagementTemplate(): string { return $this->getGenericTemplate('User Access Management', 'Verwaltung von Benutzerzugriffen'); }
    private function getPasswordManagementTemplate(): string { return $this->getGenericTemplate('Password Management', 'Passwortrichtlinien und -verwaltung'); }
    private function getPrivilegedAccessTemplate(): string { return $this->getGenericTemplate('Privileged Access Management', 'Verwaltung privilegierter Zugriffe'); }
    private function getCryptographicControlsTemplate(): string { return $this->getGenericTemplate('Cryptographic Controls', 'Verschlüsselungsmaßnahmen'); }
    private function getKeyManagementTemplate(): string { return $this->getGenericTemplate('Key Management', 'Verwaltung kryptographischer Schlüssel'); }
    private function getPhysicalSecurityTemplate(): string { return $this->getGenericTemplate('Physical Security', 'Physische Sicherheitsmaßnahmen'); }
    private function getSecureAreasTemplate(): string { return $this->getGenericTemplate('Secure Areas', 'Sicherheitsbereiche'); }
    private function getEquipmentSecurityTemplate(): string { return $this->getGenericTemplate('Equipment Security', 'Gerätesicherheit'); }
    private function getChangeManagementTemplate(): string { return $this->getGenericTemplate('Change Management', 'Änderungsmanagement'); }
    private function getCapacityManagementTemplate(): string { return $this->getGenericTemplate('Capacity Management', 'Kapazitätsmanagement'); }
    private function getMalwareProtectionTemplate(): string { return $this->getGenericTemplate('Malware Protection', 'Schutz vor Schadsoftware'); }
    private function getBackupProcedureTemplate(): string { return $this->getGenericTemplate('Backup Procedure', 'Datensicherung'); }
    private function getLoggingMonitoringTemplate(): string { return $this->getGenericTemplate('Logging and Monitoring', 'Protokollierung und Überwachung'); }
    private function getNetworkSecurityTemplate(): string { return $this->getGenericTemplate('Network Security', 'Netzwerksicherheit'); }
    private function getInfoTransferTemplate(): string { return $this->getGenericTemplate('Information Transfer', 'Informationsübertragung'); }
    private function getSecureSDLCTemplate(): string { return $this->getGenericTemplate('Secure SDLC', 'Sicherer Entwicklungslebenszyklus'); }
    private function getSecDevTemplate(): string { return $this->getGenericTemplate('Security in Development', 'Sicherheit in der Entwicklung'); }
    private function getTestDataTemplate(): string { return $this->getGenericTemplate('Test Data Management', 'Verwaltung von Testdaten'); }
    private function getSupplierSecurityTemplate(): string { return $this->getGenericTemplate('Supplier Security', 'Lieferantensicherheit'); }
    private function getServiceDeliveryTemplate(): string { return $this->getGenericTemplate('Service Delivery Management', 'Service-Delivery-Management'); }
    private function getEvidenceCollectionTemplate(): string { return $this->getGenericTemplate('Evidence Collection', 'Beweissicherung'); }
    private function getICTReadinessTemplate(): string { return $this->getGenericTemplate('ICT Readiness', 'ICT-Notfallvorsorge'); }
    private function getLegalComplianceTemplate(): string { return $this->getGenericTemplate('Legal Compliance', 'Rechtliche Compliance'); }

    private function getGenericTemplate(string $title, string $description): string
    {
        return <<<MD
# $title

## 1. Zweck
$description

## 2. Geltungsbereich
[Zu definieren]

## 3. Verantwortlichkeiten
[Zu definieren]

## 4. Verfahren
[Zu definieren]

## 5. Überwachung und Messung
[Zu definieren]

## 6. Dokumentation
[Zu definieren]

**Hinweis**: Dies ist ein Vorlagen-Dokument. Bitte passen Sie es an Ihre spezifischen Anforderungen an.
MD;
    }

    private function convertToHtml(string $markdown): string
    {
        // Simple markdown to HTML conversion
        $html = $markdown;
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^\- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>', $html);

        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>\n<title>ISO 27001 Procedure</title>\n</head>\n<body>\n$html\n</body>\n</html>";
    }
}
