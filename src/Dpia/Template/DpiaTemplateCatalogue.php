<?php

declare(strict_types=1);

namespace App\Dpia\Template;

/**
 * F31 — Curated library of 3 sectoral DPIA templates.
 *
 * Content is IMMUTABLE application code — tenants CANNOT author or edit templates.
 * Norm references: §22 BDSG (Healthcare), DORA Art. 9-14 (Financial Services),
 * EU AI Act Annex III (AI High-Risk Systems).
 *
 * To add a template in a future sprint: add a DpiaTemplateDto to $templates
 * and provide translation keys in privacy.{de,en}.yaml.
 */
final class DpiaTemplateCatalogue
{
    /** @var list<DpiaTemplateDto> */
    private readonly array $templates;

    public function __construct()
    {
        $this->templates = [
            $this->buildHealthcareBdsgSect22(),
            $this->buildFinancialServicesDora(),
            $this->buildAiActAnnexIII(),
        ];
    }

    /** @return list<DpiaTemplateDto> */
    public function all(): array
    {
        return $this->templates;
    }

    public function find(string $key): ?DpiaTemplateDto
    {
        foreach ($this->templates as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }
        return null;
    }

    // =========================================================================
    // Template 1: Healthcare — §22 BDSG Gesundheitsdaten
    // =========================================================================

    private function buildHealthcareBdsgSect22(): DpiaTemplateDto
    {
        return new DpiaTemplateDto(
            key: 'healthcare_bdsg_sect22',
            nameTransKey: 'dpia.template.healthcare_bdsg_sect22.name',
            usageHint: 'dpia.template.healthcare_bdsg_sect22.usage_hint',
            icon: 'heart-pulse',
            processingDescription: 'Verarbeitung von Gesundheitsdaten gemäß § 22 Abs. 1 Nr. 1 Buchst. b BDSG i.V.m. Art. 9 Abs. 2 Buchst. h DSGVO zum Zweck der Gesundheitsversorgung.' . "\n\n"
                . 'Die Verarbeitung umfasst: Erhebung, Speicherung und Nutzung von Patientenstammdaten (Name, Geburtsdatum, Versicherungsnummer), Diagnose- und Behandlungsdaten (ICD-10-Codes, Laborbefunde, Bildgebung/DICOM), Medikationspläne, Arztbriefe und Entlassdokumentationen. Daten werden im Krankenhausinformationssystem (KIS) verarbeitet und über HL7 FHIR R4 Schnittstellen an nachgelagerte Systeme (Labor, Radiologie, Apotheke) übertragen.' . "\n\n"
                . 'Sonderkategorien gem. Art. 9 DSGVO: Gesundheitsdaten (primär), ggf. genetische Daten (bei Labordiagnostik).',
            processingPurposes: 'Primärzweck: Erbringung medizinischer Versorgungsleistungen (Diagnostik, Therapie, Pflege) gem. §§ 27–28 SGB V und § 22 Abs. 1 Nr. 1 Buchst. b BDSG.' . "\n\n"
                . 'Sekundärzwecke (jeweils eigenständige Rechtsgrundlage erforderlich):' . "\n"
                . '- Abrechnung gegenüber Kostenträgern (§ 301 SGB V)' . "\n"
                . '- Qualitätssicherung und Dokumentationspflichten (§ 137 SGB V, ärztliche Dokumentationspflicht)' . "\n"
                . '- Forschungszwecke nur mit gesonderter Einwilligung (Art. 89 DSGVO, § 27 BDSG)',
            dataCategories: ['health', 'identification', 'contact'],
            dataSubjectCategories: ['patients', 'customers'],
            necessityAssessment: 'Die Verarbeitung von Gesundheitsdaten ist für die Erbringung medizinischer Versorgungsleistungen unabdingbar notwendig (Art. 9 Abs. 2 Buchst. h DSGVO, § 22 Abs. 1 Nr. 1 Buchst. b BDSG). Ohne die Verarbeitung wäre eine sichere, lückenlose und nachvollziehbare Patientenversorgung nicht gewährleistbar.' . "\n\n"
                . 'Erforderlichkeitsprüfung nach Datenkategorie:' . "\n"
                . '- Stammdaten: Notwendig für eindeutige Patientenidentifikation, Verhinderung von Verwechslungen (Patientensicherheit)' . "\n"
                . '- Diagnosedaten: Notwendig für klinische Entscheidungsfindung und Therapieplanung' . "\n"
                . '- Laborwerte/Bildgebung: Notwendig für objektive Befundung und Verlaufskontrolle',
            proportionalityAssessment: 'Verhältnismäßigkeitsprüfung gem. Art. 5 Abs. 1 Buchst. c DSGVO (Datenminimierung):' . "\n\n"
                . '1. Geeignetheit: Elektronische Verarbeitung im KIS ist geeignet und nach heutigem Versorgungsstandard (§ 291a SGB V ePA-Pflicht) alternativlos.' . "\n"
                . '2. Erforderlichkeit: Nur für die jeweilige Behandlungsepisode erforderliche Daten werden aktiv verarbeitet; ältere Befunde stehen nur abrufbar zur Verfügung (Zugriff wird protokolliert).' . "\n"
                . '3. Angemessenheit: Der Eingriff in die informationelle Selbstbestimmung ist durch das hochrangige Ziel der Patientensicherheit und -versorgung gerechtfertigt.' . "\n"
                . '4. Speicherbegrenzung: Aufbewahrungsfristen gem. § 10 MBO-Ä (10 Jahre nach Behandlungsende), verlängerte Fristen für bestimmte Sonderdiagnosen (Strahlenbehandlung: 30 Jahre gem. § 85 StrlSchV).',
            legalBasis: 'art9_bdsg22',
            legislativeCompliance: 'Anwendbare Rechtsgrundlagen und Normen:' . "\n"
                . '- DSGVO Art. 9 Abs. 2 Buchst. h: Verarbeitung für Zwecke der Gesundheitsversorgung' . "\n"
                . '- § 22 Abs. 1 Nr. 1 Buchst. b BDSG: Nationale Öffnungsklausel für Gesundheitsdaten' . "\n"
                . '- § 10 MBO-Ä: Dokumentationspflicht (10-Jahres-Frist)' . "\n"
                . '- § 291a, § 301 SGB V: Abrechnungsdaten, elektronische Patientenakte' . "\n"
                . '- B3S Krankenhaus (BSI/DKG): Branchenspezifischer Sicherheitsstandard für Krankenhäuser' . "\n"
                . '- KHZG (Krankenhaus-Zukunftsgesetz): IT-Sicherheitsinvestitionen Pflichtbestandteil' . "\n"
                . '- ISO 27799:2016: Gesundheitsinformatik — ISMS für Healthcare' . "\n"
                . '- MDR 2017/745 (sofern medizinische Software betroffen): EU-Medizinprodukteverordnung',
            identifiedRisks: [
                [
                    'title' => 'Ransomware-Angriff / Datenverfügbarkeit',
                    'description' => 'Kryptoverschlüsselung des KIS führt zu Notbetrieb ohne elektronische Patientendaten; Verwechslungsgefahr, verzögerte Diagnostik.',
                    'likelihood' => 'likely',
                    'impact' => 'severe',
                    'severity' => 'critical',
                ],
                [
                    'title' => 'Unbefugter Zugriff durch internes Personal',
                    'description' => 'Prominente Patienten oder eigene Mitarbeitende werden ohne Behandlungsauftrag abgerufen (sog. "curiosity access"). Entdeckungsrisiko niedrig bei fehlender Zugriffsprotokollierung.',
                    'likelihood' => 'possible',
                    'impact' => 'major',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Datenweitergabe an Dritte ohne Rechtsgrundlage',
                    'description' => 'Fehlerhafte Konfiguration von FHIR-Schnittstellen führt zur Offenlegung gegenüber nicht berechtigten Dienstleistern oder Forschungseinrichtungen.',
                    'likelihood' => 'unlikely',
                    'impact' => 'major',
                    'severity' => 'high',
                ],
            ],
            riskLevel: 'high',
            likelihood: 'possible',
            impact: 'severe',
            dataSubjectRisks: 'Spezifische Risiken für die Betroffenen (Art. 35(7)(c) DSGVO):' . "\n"
                . '- Körperlicher Schaden: Verwechslung durch Datenverlust oder Datenfehler bei der Behandlung' . "\n"
                . '- Diskriminierung: Unberechtigte Weitergabe von psychischen Diagnosen oder HIV-Status an Arbeitgeber oder Versicherungen' . "\n"
                . '- Verlust von Vertraulichkeit: Preisgabe sensibler Diagnosen (Sucht, psychiatrische Erkrankungen) ohne Einwilligung' . "\n"
                . '- Finanzielle Nachteile: Missbrauch von Versicherungsdaten für Prämienanpassungen',
            technicalMeasures: 'Technische Schutzmaßnahmen (Art. 32 DSGVO, B3S Krankenhaus):' . "\n\n"
                . '1. Zugriffskontrolle: Rollenbasiertes Zugriffskonzept (RBAC) nach Behandlungsbezug; automatische Sessiontimeouts nach 5 Minuten Inaktivität; Zwei-Faktor-Authentifizierung für Remote-Zugriff (VPN + OTP)' . "\n"
                . '2. Verschlüsselung: AES-256 Encryption-at-Rest für alle Datenbanken und Backup-Medien; TLS 1.3 für alle Übertragungswege (FHIR, HL7, Web-UI)' . "\n"
                . '3. Protokollierung: Vollständiges Audit-Log aller Lesezugriffe auf Patientendaten (Art. 30 DSGVO); Anomalie-Erkennung bei ungewöhnlichen Zugriffsmustern (SIEM)' . "\n"
                . '4. Backups: 3-2-1-Backup-Strategie; Offline-Kalt-Backup für Ransomware-Resilienz; Recovery Time Objective (RTO) < 4h für kritische Systeme' . "\n"
                . '5. Netzwerksegmentierung: KIS-Netz physisch/logisch getrennt von allgemeinem Krankenhausnetz; Medizingerätenetz in eigenem VLAN' . "\n"
                . '6. Pseudonymisierung für Forschungsdaten: Einsatz eines zertifizierten Treuhandverfahrens (§ 27 BDSG)',
            organizationalMeasures: 'Organisatorische Schutzmaßnahmen (Art. 32 DSGVO, § 22 Abs. 2 BDSG):' . "\n\n"
                . '1. Verpflichtung: Alle Mitarbeitenden mit Datenzugang sind auf Datengeheimnis und ärztliche Schweigepflicht (§ 203 StGB) verpflichtet; jährliche Datenschutz-Schulung verpflichtend' . "\n"
                . '2. DSB: Bestellter betrieblicher Datenschutzbeauftragter (DSB) gem. Art. 37 DSGVO; Einbindung bei DSFA-Erstellung und -Genehmigung' . "\n"
                . '3. Auftragsverarbeitungsverträge: AVV mit allen IT-Dienstleistern (KIS-Hersteller, Rechenzentrum, Wartungspartner) nach Art. 28 DSGVO' . "\n"
                . '4. Zutrittskontrolle: Serverräume mit Zutrittsprotokollierung; Clean-Desk-Policy auf Stationen' . "\n"
                . '5. Incident-Response: Dokumentierter Meldeprozess für Datenpannen; 72-Stunden-Frist an Aufsichtsbehörde (Art. 33 DSGVO)' . "\n"
                . '6. Notfallplan: Business-Continuity-Plan für KIS-Ausfall mit Papier-Backup-Verfahren und definierten Verantwortlichkeiten',
            residualRiskAssessment: 'Nach Implementierung aller technischen und organisatorischen Maßnahmen verbleibt ein Restrisiko durch:' . "\n"
                . '- Insider-Bedrohungen trotz RBAC und Audit-Logging (vollständige Prävention nicht möglich)' . "\n"
                . '- Zero-Day-Schwachstellen in KIS-Software vor Patch-Verfügbarkeit' . "\n"
                . '- Social-Engineering-Angriffe auf medizinisches Personal' . "\n\n"
                . 'Das Restrisiko wird als vertretbar eingestuft, da die implementierten Maßnahmen dem Stand der Technik (B3S Krankenhaus, ISO 27799) entsprechen und die Abwägung mit dem lebensnotwendigen Verarbeitungszweck den verbleibenden Eingriff rechtfertigt.',
            residualRiskLevel: 'medium',
            requiresSupervisoryConsultation: false,
        );
    }

    // =========================================================================
    // Template 2: Financial Services — DORA ICT-Risiko
    // =========================================================================

    private function buildFinancialServicesDora(): DpiaTemplateDto
    {
        return new DpiaTemplateDto(
            key: 'financial_services_dora',
            nameTransKey: 'dpia.template.financial_services_dora.name',
            usageHint: 'dpia.template.financial_services_dora.usage_hint',
            icon: 'landmark',
            processingDescription: 'Verarbeitung personenbezogener Kundendaten im Rahmen digitaler operationeller Resilienz gem. DORA (EU 2022/2554) und DSGVO Art. 6 Abs. 1 Buchst. c i.V.m. Art. 6 Abs. 1 Buchst. f.' . "\n\n"
                . 'Die Verarbeitung umfasst: Kundenstammdaten (Name, Adresse, IBAN, Steuer-ID), Transaktionsdaten (Überweisungen, Kartenzahlungen, Wertpapierhandel), ICT-Risikomonitoringdaten (Systemzugriffslogs, Anomalie-Erkennungsdaten), Daten aus DORA-Drittanbieterverträgen (Subcontractor-Mapping). Daten werden in zentralen Banking-Core-Systemen, einem SIEM-System und einem DORA-Threat-Intelligence-Register verarbeitet.' . "\n\n"
                . 'Verarbeitungsdauer: Transaktionsdaten 10 Jahre (§ 147 AO, § 238 HGB); SIEM-Logs 12 Monate aktiv, 36 Monate Archiv; ICT-Vertragsdaten für Laufzeit + 10 Jahre.',
            processingPurposes: 'Primärzweck: Erfüllung regulatorischer ICT-Risikomanagement-Pflichten nach DORA Art. 9–14 (Identifizierung, Schutz, Erkennung, Reaktion, Wiederherstellung) als Teil der gesetzlichen Compliance-Verpflichtung (Art. 6 Abs. 1 Buchst. c DSGVO).' . "\n\n"
                . 'Sekundärzweck: Betrugs- und Geldwäscheprävention (§§ 10–17 GwG, PSD2 Art. 94–98), Erfüllung meldepflichtiger IKT-Vorfälle an BaFin/EBA/ENISA (DORA Art. 19), Sicherstellung des Schutzes von Kundenvermögen.',
            dataCategories: ['financial', 'identification', 'contact', 'online_identifiers'],
            dataSubjectCategories: ['customers', 'employees'],
            necessityAssessment: 'Die Verarbeitung ist zur Erfüllung gesetzlicher Pflichten unbedingt erforderlich (Art. 6 Abs. 1 Buchst. c DSGVO):' . "\n"
                . '- DORA Art. 9 Abs. 2: Pflicht zur kontinuierlichen Überwachung aller ICT-Systeme (erfordert Protokolldaten mit Personenbezug durch Nutzer-IDs)' . "\n"
                . '- DORA Art. 11: Pflicht zur Einrichtung von Backup- und Wiederherstellungsverfahren (erfordert Transaktionsdaten-Sicherung)' . "\n"
                . '- DORA Art. 28: Pflicht zur Verwaltung von IKT-Drittanbieterrisiken (erfordert Mapping von Drittanbieter-Zugriffen auf Systeme mit Kundendaten)' . "\n\n"
                . 'Ohne diese Verarbeitung würde das Institut gegen unmittelbar geltende EU-Verordnung verstoßen und riskiert Aufsichtsmaßnahmen durch BaFin/EZB.',
            proportionalityAssessment: 'Verhältnismäßigkeitsprüfung nach DORA-Proportionalitätsprinzip (DORA Art. 4 — proportionaler Ansatz je nach Systemrelevanz):' . "\n\n"
                . '1. Geeignetheit: Vollständige ICT-Ereignisprotokollierung ist das einzige technische Mittel, das den DORA Art. 19 Meldepflichten-Anforderungen genügt.' . "\n"
                . '2. Erforderlichkeit: Pseudonymisierung von Logs ist möglich für retrospektive Analysen; bei laufenden Bedrohungserkennungen ist Vollbezug erforderlich. Protokollierungstiefe wird nach Systemkritikalität gestaffelt (DORA Art. 9 Abs. 4 Buchst. a).' . "\n"
                . '3. Interessenabwägung: Regulatorisches Interesse an Systemstabilität des Finanzsystems überwiegt individuelles Datenschutzinteresse bei Logs (kein Eingriff in Transaktionsinhalte, nur Metadaten).',
            legalBasis: 'legal_obligation',
            legislativeCompliance: 'Anwendbare Rechtsgrundlagen und Normen:' . "\n"
                . '- DSGVO Art. 6 Abs. 1 Buchst. c: Rechtliche Verpflichtung (DORA als unmittelbar anwendbare EU-VO)' . "\n"
                . '- DSGVO Art. 6 Abs. 1 Buchst. f: Berechtigtes Interesse (Betrugsschutz, Vermögensschutz)' . "\n"
                . '- DORA (EU 2022/2554) Art. 9–14: ICT-Risikomanagementrahmen' . "\n"
                . '- DORA Art. 17–19: Klassifizierung, Dokumentation, Meldung von IKT-Vorfällen' . "\n"
                . '- DORA Art. 28–44: IKT-Drittanbieterrisikomanagement (TPRM)' . "\n"
                . '- PSD2 (EU 2015/2366) Art. 94–98: Datenschutz und Sicherheit im Zahlungsverkehr' . "\n"
                . '- GwG §§ 10–17: Know-Your-Customer und Transaction-Monitoring' . "\n"
                . '- § 25a KWG / § 26 ZAG: IT-Auslagerungsanforderungen (ergänzend zu DORA)',
            identifiedRisks: [
                [
                    'title' => 'IKT-Drittanbieterkonzentration (DORA Art. 29)',
                    'description' => 'Mehr als 30 % der IKT-Dienste bei einem einzigen Cloud-Provider; Ausfall führt zu Systemausfall mit Kundendatenverarbeitungs-Stopp und DORA-Meldepflicht.',
                    'likelihood' => 'unlikely',
                    'impact' => 'severe',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Datenmissbrauch durch Insider bei SIEM-Zugriff',
                    'description' => 'SIEM-Administratoren haben Zugriff auf unreduzierte Transaktions-Metadaten; Insider-Angriff oder Daten-Diebstahl für Marktmissbrauch (Art. 14/15 MAR).',
                    'likelihood' => 'unlikely',
                    'impact' => 'major',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Supply-Chain-Angriff auf IKT-Drittanbieter',
                    'description' => 'Kompromittierter Software-Update-Lieferant verschafft Angreifer persistenten Zugang zu Bankensystemen.',
                    'likelihood' => 'rare',
                    'impact' => 'severe',
                    'severity' => 'high',
                ],
            ],
            riskLevel: 'high',
            likelihood: 'possible',
            impact: 'major',
            dataSubjectRisks: 'Spezifische Risiken für Betroffene (DORA Art. 9 Abs. 2 i.V.m. DSGVO Art. 35(7)(c)):' . "\n"
                . '- Finanzieller Schaden: Unbefugter Zugriff auf Kontodaten ermöglicht Betrugsszenarien (Kontoübernahme, CEO-Fraud)' . "\n"
                . '- Diskriminierung: Aggregierte Verhaltensmuster aus Transaktionsdaten könnten für Credit-Scoring-Manipulation genutzt werden' . "\n"
                . '- Verlust von Vertraulichkeit: Offenlegung von Kontoständen und Anlagestrategien gegenüber Wettbewerbern oder Erpressern',
            technicalMeasures: 'Technische Schutzmaßnahmen (DORA Art. 9 Abs. 4, DSGVO Art. 32):' . "\n\n"
                . '1. ICT-Risikoüberwachung: SIEM mit Echtzeit-Alerting für anomale Transaktionsmuster und Zugriffsverhalten; Threat-Intelligence-Feed-Integration (MITRE ATT&CK Financial)' . "\n"
                . '2. Verschlüsselung: End-to-End-Verschlüsselung aller Transaktionskanäle (TLS 1.3, PFS); Tokenisierung von Kontonummern in Log-Systemen (PCI-DSS-Konformität)' . "\n"
                . '3. Zugriffskontrolle: Privileged Access Management (PAM) für Admin-Zugänge zu Corebankingsystem; Just-in-Time-Access für SIEM-Administratoren (Zero-Trust-Prinzip)' . "\n"
                . '4. Datentrennung: Separate Datenbankinstanzen für produktive Kundendaten und DORA-Analysedaten; kein direkter Analyst-Zugriff auf Produktionsdaten' . "\n"
                . '5. DORA-Resilienztests: Jährliche TLPT (Threat-Led Penetration Testing) gem. DORA Art. 26; Ergebnisse beeinflussen Schutzmaßnahmen',
            organizationalMeasures: 'Organisatorische Schutzmaßnahmen (DORA Art. 5, DSGVO Art. 32):' . "\n\n"
                . '1. IKT-Governance: Dedizierter CISO mit DORA-Verantwortung; IKT-Risikorahmen-Dokument (DORA Art. 5 Abs. 2); vierteljährliche Berichte an Leitungsorgan' . "\n"
                . '2. Drittanbieter-Management: Register kritischer IKT-Drittanbieter (DORA Art. 28 Abs. 3); Vertragliche Audit-Rechte; Ausstiegsstrategien für alle kritischen Provider dokumentiert' . "\n"
                . '3. Vorfallsmanagement: 24/7 Security Operations Center (SOC) oder MSSP-Vertrag; Erstmeldung an BaFin/EZB binnen 4h bei DORA-meldepflichtigen Vorfällen' . "\n"
                . '4. Mitarbeitersensibilisierung: Verpflichtende jährliche Cybersecurity-Schulung; Phishing-Simulationen vierteljährlich; Sonderschulung für SIEM/SOC-Personal' . "\n"
                . '5. DSB-Einbindung: Betrieblicher DSB (Art. 37 Abs. 1 Buchst. b DSGVO — Pflicht für Kreditinstitute) konsultiert bei DSFA und Vorfällen',
            residualRiskAssessment: 'Nach Implementierung der DORA-konformen Schutzmaßnahmen verbleibt ein Restrisiko durch:' . "\n"
                . '- Sophisticated State-Sponsored Attacks (APT), die regulatorische Kontrollen umgehen' . "\n"
                . '- Unbekannte Schwachstellen in Kernbankensoftware (Zero-Days)' . "\n"
                . '- Menschliches Versagen bei Vorfallserkennung trotz SIEM-Alerting' . "\n\n"
                . 'Das Restrisiko ist als tolerierbar einzustufen, da alle DORA-Pflichtanforderungen (Art. 9–14) implementiert sind und das Proportionalitätsprinzip (DORA Art. 4) die gewählte Maßnahmentiefe für das Institutsprofil rechtfertigt.',
            residualRiskLevel: 'medium',
            requiresSupervisoryConsultation: true,
        );
    }

    // =========================================================================
    // Template 3: AI Systems — EU AI Act Annex III High-Risk
    // =========================================================================

    private function buildAiActAnnexIII(): DpiaTemplateDto
    {
        return new DpiaTemplateDto(
            key: 'ai_act_annex_iii',
            nameTransKey: 'dpia.template.ai_act_annex_iii.name',
            usageHint: 'dpia.template.ai_act_annex_iii.usage_hint',
            icon: 'brain-circuit',
            processingDescription: 'Einsatz eines Hochrisiko-KI-Systems gemäß EU AI Act Annex III (Verordnung (EU) 2024/1689) mit Personenbezug. Das KI-System verarbeitet personenbezogene Daten zu Zwecken der automatisierten Entscheidungsunterstützung oder -findung in einem oder mehreren der in Annex III genannten Hochrisiko-Bereiche:' . "\n\n"
                . 'Typische Hochrisiko-KI-Bereiche (Annex III EU AI Act):' . "\n"
                . '- Nr. 1: Biometrische Identifizierung und Kategorisierung natürlicher Personen' . "\n"
                . '- Nr. 4: Beschäftigung, Personalverwaltung und Zugang zu Selbständigkeit (CV-Screening, Leistungsbewertung)' . "\n"
                . '- Nr. 5: Wesentliche private Dienstleistungen und Sozialleistungen (Kreditbewilligung, Versicherungstarife)' . "\n"
                . '- Nr. 6: Strafverfolgung (Risikobewertung, Lügendetektion)' . "\n"
                . '- Nr. 7: Migration und Grenzkontrolle (Risikobewertung von Asylantragstellern)' . "\n"
                . '- Nr. 8: Rechtspflege (Unterstützung von Gerichten)' . "\n\n"
                . 'Die Verarbeitung umfasst: Eingabedaten des Modells (je nach Use Case: Verhaltens-/Profil-/biometrische Daten), Modellinferenz-Logs, Entscheidungsausgaben mit Konfidenzwerten, Feedback-Daten für RLHF/Fine-Tuning.',
            processingPurposes: 'Zweck des KI-System-Einsatzes (gemäß EU AI Act Art. 9 Abs. 2 Buchst. b — Risikomanagement muss Zweck spezifizieren):' . "\n\n"
                . 'Primärzweck: [Konkret anzupassen — z.B.] Automatisierte Vorauswahl von Bewerbungen im Personalwesen (Annex III Nr. 4), Kreditwürdigkeitsbewertung (Annex III Nr. 5) oder Inhaltsmoderations-Entscheidungsunterstützung.' . "\n\n"
                . 'Rechtliche Grundlage der KI-gestützten Entscheidung: DSGVO Art. 22 Abs. 2 ist zu prüfen — vollautomatisierte Entscheidungen mit erheblicher Wirkung bedürfen ausnahmsweise Rechtsgrundlage (Vertrag, Gesetz, Einwilligung) und Recht auf menschliche Überprüfung.',
            dataCategories: ['behavioral', 'identification', 'online_identifiers'],
            dataSubjectCategories: ['customers', 'employees', 'users'],
            necessityAssessment: 'Erforderlichkeitsprüfung gem. EU AI Act Art. 9 Abs. 4 (Risikomanagementsystem) i.V.m. DSGVO Art. 35(7)(b):' . "\n\n"
                . 'Das KI-System ist für den Verwendungszweck erforderlich, wenn:' . "\n"
                . '1. Der Verwendungszweck nicht durch eine datenschutzfreundlichere Methode (regelbasierte Systeme, manuelle Prüfung) mit gleicher Qualität erreichbar ist (Subsidiaritätsprüfung)' . "\n"
                . '2. Die durch das KI-System erzeugte Entscheidungsqualität nachweislich höher ist als alternative Methoden (Validierungsdokumentation gem. EU AI Act Art. 9 Abs. 7)' . "\n"
                . '3. Die Verarbeitungstiefe (Merkmalsextraktion, Verhaltensanalyse) auf das für die Modellgüte Notwendige beschränkt wird' . "\n\n"
                . '[Ergebnis der Subsidiaritätsprüfung hier dokumentieren]',
            proportionalityAssessment: 'Verhältnismäßigkeitsprüfung unter Einbeziehung des EU AI Act Annex III Risikoprofils:' . "\n\n"
                . '1. Geeignetheit: KI-System entspricht dem Stand der Technik für den Verwendungszweck (Benchmarking-Dokumentation vorhanden); konform mit Normen EN/ISO 42001:2023 (KI-Managementsystem).' . "\n"
                . '2. Erforderlichkeit der Datentiefe: Datenminimierung im Feature-Engineering dokumentiert; keine Verwendung geschützter Merkmale gem. Art. 3 GG / Art. 19 AI Act als Primär-Features (Diskriminierungsfreiheit).' . "\n"
                . '3. Angemessenheit: Entscheidungstransparenz (EU AI Act Art. 13 — Transparenz-Pflicht Hochrisiko-KI); Betroffene werden über KI-Einsatz informiert; Human-in-the-Loop für Entscheidungen mit erheblicher Wirkung (Art. 14 AI Act).' . "\n"
                . '4. Folgenabschätzung für Gruppen: Besonderer Fokus auf potenziell diskriminierte Gruppen (Age, Gender, Ethnicity Parity-Tests dokumentieren).',
            legalBasis: 'legitimate_interests',
            legislativeCompliance: 'Anwendbare Rechtsgrundlagen und Normen:' . "\n"
                . '- DSGVO Art. 22: Automatisierte Entscheidungsfindung — Recht auf menschliche Überprüfung' . "\n"
                . '- DSGVO Art. 35: DSFA-Pflicht bei systematischer und umfangreicher Auswertung personenbezogener Daten (Art. 35 Abs. 3 Buchst. a)' . "\n"
                . '- EU AI Act (EU 2024/1689) Art. 9: Risikomanagementsystem für Hochrisiko-KI-Systeme' . "\n"
                . '- EU AI Act Annex III: Klassifizierung als Hochrisiko-KI-System (zutreffende Nr. angeben)' . "\n"
                . '- EU AI Act Art. 10: Anforderungen an Trainingsdaten (Repräsentativität, Fairness)' . "\n"
                . '- EU AI Act Art. 13: Transparenz und Bereitstellung von Informationen' . "\n"
                . '- EU AI Act Art. 14: Menschliche Aufsicht (Human Oversight)' . "\n"
                . '- EU AI Act Art. 17: Qualitätsmanagementsystem für Anbieter' . "\n"
                . '- ISO/IEC 42001:2023: KI-Managementsystem — Konformitätsnachweise' . "\n"
                . '- DSGVO Art. 25: Privacy by Design in KI-Systemen (Datenminimierung bei Feature Selection)',
            identifiedRisks: [
                [
                    'title' => 'Algorithmische Diskriminierung (EU AI Act Art. 10)',
                    'description' => 'Trainingsdaten mit historischem Bias führen zu systematisch nachteiligen Entscheidungen für geschützte Gruppen (Geschlecht, Alter, ethnische Herkunft). Rechtliches Risiko: AGG-Verstöße, DSGVO-Beschwerden, EU AI Act Art. 10 Abs. 3.',
                    'likelihood' => 'possible',
                    'impact' => 'major',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Undurchschaubare Entscheidungen (Explainability-Defizit)',
                    'description' => 'Black-Box-Modell kann Entscheidungen nicht nachvollziehbar begründen; verletzt DSGVO Art. 22 Abs. 3 (Recht auf Erklärung) und EU AI Act Art. 13 (Transparenz).',
                    'likelihood' => 'likely',
                    'impact' => 'moderate',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Datenvergiftung / Adversarial Attacks',
                    'description' => 'Manipulation von Trainingsdaten oder Eingabedaten zur Beeinflussung von Modellergebnissen; besonders kritisch bei sicherheitsrelevanten Hochrisiko-Anwendungen (EU AI Act Annex III Nr. 1, 6).',
                    'likelihood' => 'rare',
                    'impact' => 'severe',
                    'severity' => 'high',
                ],
                [
                    'title' => 'Zweckentfremdung von Profiling-Daten',
                    'description' => 'Verhaltens- und Inferenz-Logs werden für Sekundärzwecke genutzt, die der Betroffene nicht erwartet. Verstoß gegen Zweckbindung (DSGVO Art. 5 Abs. 1 Buchst. b).',
                    'likelihood' => 'possible',
                    'impact' => 'major',
                    'severity' => 'high',
                ],
            ],
            riskLevel: 'high',
            likelihood: 'possible',
            impact: 'major',
            dataSubjectRisks: 'Spezifische Risiken für Betroffene bei Hochrisiko-KI (EU AI Act Annex III, DSGVO Art. 35(7)(c)):' . "\n"
                . '- Diskriminierung: Systematisch benachteiligende Entscheidungen aufgrund von Bias im Modell (z.B. Ablehnung von Kreditanträgen, Nicht-Einladung zu Vorstellungsgesprächen)' . "\n"
                . '- Verlust von Autonomie: Undurchschaubare KI-Entscheidungen ohne wirksame Möglichkeit zur Anfechtung verletzen das Recht auf informationelle Selbstbestimmung' . "\n"
                . '- Soziale Kontrolle: Erstellung detaillierter Verhaltensprofile ohne Bewusstsein der Betroffenen; Profilbildung über Zeit' . "\n"
                . '- Physische Schäden: Bei sicherheitskritischen Hochrisiko-KI-Systemen (Annex III Nr. 1, 6) können fehlerhafte Entscheidungen unmittelbar körperliche Folgen haben',
            technicalMeasures: 'Technische Schutzmaßnahmen (EU AI Act Art. 9 Abs. 4 i.V.m. DSGVO Art. 32):' . "\n\n"
                . '1. Fairness & Bias-Testing: Vor Deployment: demografischer Parity-Test, Equal Opportunity Test, Calibration-Test für alle Annex-III-relevanten Gruppen; monatliches Post-Deployment-Monitoring auf Drift' . "\n"
                . '2. Explainability: SHAP/LIME-Erklärungen für jede Entscheidung; für betroffene Personen: verständliche Erklärung nach DSGVO Art. 22 Abs. 3 in max. 5 Sätzen; Entscheidungsbegründung wird gespeichert' . "\n"
                . '3. Datensparsamkeit: Feature-Selection nach Relevanz für Zielvariable (keine sensiblen Merkmale als Proxy); Input-Daten werden nach Inferenz gelöscht (kein persistentes Nutzerprofil ohne Einwilligung)' . "\n"
                . '4. Zugriffskontrolle auf Modellinferenz: API-Auth-Token mit Rate-Limiting; Audit-Log jeder Modell-Anfrage (Requester, Timestamp, Input-Hash, Output)' . "\n"
                . '5. Adversarial Robustness: Input-Validierung und Anomalie-Erkennung gegen Adversarial Examples; regelmäßige Red-Team-Tests auf das Modell',
            organizationalMeasures: 'Organisatorische Schutzmaßnahmen (EU AI Act Art. 9, 14, 17 i.V.m. DSGVO Art. 32):' . "\n\n"
                . '1. Human-in-the-Loop: Für alle Entscheidungen mit erheblicher Wirkung (Art. 22 DSGVO) ist ein menschlicher Prüfschritt obligatorisch; KI-Ausgabe dient als Empfehlung, nicht als finale Entscheidung' . "\n"
                . '2. AI-Risikomanagementsystem: Dokumentierter Prozess nach EU AI Act Art. 9; Risikoregister für das KI-System mit Eigentümer-Verantwortlichkeit' . "\n"
                . '3. Transparenz gegenüber Betroffenen: Informationspflicht über KI-Einsatz in Datenschutzerklärung (DSGVO Art. 13/14 + EU AI Act Art. 13); auf Anfrage Recht auf menschliche Überprüfung' . "\n"
                . '4. Post-Market-Monitoring: Quartalsweiser Review der Modellperformance und Bias-Metriken; Eskalationsprozess bei Drift oder unerwarteten Fairness-Verschlechterungen' . "\n"
                . '5. Anbieter-Dokumentation: Technische Dokumentation gem. EU AI Act Art. 11 + Annex IV vollständig; EU-Konformitätserklärung und CE-Kennzeichnung (sofern Anbieter); KI-Register-Eintrag (Art. 51 AI Act)',
            residualRiskAssessment: 'Nach Implementierung aller technischen und organisatorischen Maßnahmen (Human Oversight, Fairness-Tests, Explainability) verbleibt ein Restrisiko durch:' . "\n"
                . '- Nicht-erkennbare emergente Bias-Muster in Out-of-Distribution-Daten' . "\n"
                . '- Grenzen von XAI-Methoden (SHAP/LIME erklären, approximieren aber keinen vollständigen Kausalzusammenhang)' . "\n"
                . '- Schnell ändernde Regulierung (EU AI Act Delegierte Rechtsakte, Guidelines des AI Office)' . "\n\n"
                . 'Das Restrisiko ist vertretbar unter der Bedingung, dass Human-in-the-Loop-Kontrolle für Entscheidungen mit erheblicher Wirkung konsequent eingehalten wird und das Post-Market-Monitoring-Programm aktiv ist. Eine Konsultation der Aufsichtsbehörde wird wegen des verbleibenden Risikoprofils empfohlen (Art. 36 DSGVO).',
            residualRiskLevel: 'medium',
            requiresSupervisoryConsultation: true,
        );
    }
}
