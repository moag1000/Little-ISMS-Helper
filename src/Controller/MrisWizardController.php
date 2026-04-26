<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * MRIS-Wizards — drei statische GET-only-Anleitungen zur Loesung der
 * Top-3-Junior-Blocker (siehe docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md):
 *
 *   1. „Reine Reibung" — 5-Schritt-Entscheidungsroutine.
 *   2. Reifegrad-Evidence — Audit-taugliche Belegliste pro MHC und
 *      Reifegrad-Stufe (Initial / Defined / Managed) fuer alle 13 MHCs.
 *   3. AI-Risiko-Klasse — Entscheidungsmatrix mit 12 typischen Tools nach
 *      EU AI Act (Art. 6 + Anhang III).
 *
 * Inhalte stammen primaer aus fixtures/mris/help-texts.yaml unter dem
 * Block `blocker_solutions`. Falls dieser Block (noch) nicht gepflegt
 * ist, faellt der Controller auf eine eingebettete statische Variante
 * zurueck, die aus den Top-3-Blocker-Beschreibungen der Junior-Request-
 * Dokumentation abgeleitet wurde.
 *
 * Quelle Fachkonzept: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: Creative Commons Attribution 4.0
 * International (CC BY 4.0).
 */
#[IsGranted('ROLE_USER')]
final class MrisWizardController extends AbstractController
{
    /**
     * Liest fixtures/mris/help-texts.yaml und gibt den Block
     * `blocker_solutions` zurueck. Liefert ein leeres Array, falls der
     * Block fehlt (Aufrufer entscheidet ueber Fallback).
     *
     * @return array<string, mixed>
     */
    private function loadBlockerSolutions(): array
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $yamlPath = $projectDir . '/fixtures/mris/help-texts.yaml';

        if (!is_file($yamlPath) || !is_readable($yamlPath)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($yamlPath) ?? [];
        $block = $data['blocker_solutions'] ?? null;

        return is_array($block) ? $block : [];
    }

    #[Route('/mris/wizard/pure-friction', name: 'app_mris_wizard_pure_friction', methods: ['GET'])]
    public function pureFriction(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $routine = $solutions['pure_friction_routine'] ?? null;

        if (!is_array($routine) || empty($routine['steps']) || !is_array($routine['steps'])) {
            // Fallback: 5-Schritt-Routine aus docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md
            // Top-Blocker 1 + help-texts.yaml Beispiel "90-Tage-Passwortwechsel".
            $routine = [
                'title' => '5-Schritt-Entscheidungsroutine — Reine Reibung',
                'intro' => 'Wenn ein Annex-A-Control im Audit als „Reine Reibung" markiert ist, '
                    . 'darfst du es nicht stillschweigend streichen. Folge dieser Routine, '
                    . 'um eine audit-taugliche Entscheidung zu dokumentieren.',
                'steps' => [
                    [
                        'no' => 1,
                        'title' => 'Bedrohungslage pruefen',
                        'detail' => 'Welcher reale Angriffsvektor wird durch das Control adressiert? '
                            . 'Suche Belege in CISA KEV, BSI Lagebericht, MITRE ATT&CK oder eigenem '
                            . 'Incident-Backlog. Wenn nichts gefunden wird, liegt der Verdacht auf '
                            . '„Mythos-Control" nahe.',
                        'evidence' => 'Verlinkte Bedrohungs-Quelle (CISA-KEV-ID, BSI-Doc-ID, ATT&CK-Tactic-ID).',
                    ],
                    [
                        'no' => 2,
                        'title' => 'Wirkung messen',
                        'detail' => 'Welche KPI weist die Schutzwirkung nach? Coverage, Erkennungsrate, '
                            . 'MTTC, KEV-Patch-Latency. Liefert das Control keine messbare Zahl, ist es '
                            . 'ein Compliance-Theater-Kandidat.',
                        'evidence' => 'KPI-Snapshot oder Begruendung „nicht messbar".',
                    ],
                    [
                        'no' => 3,
                        'title' => 'Risk-Owner-Freigabe einholen',
                        'detail' => 'Lege dem fachlichen Risk-Owner (Geschaeftsbereich) drei Optionen vor: '
                            . '(a) anpassen, (b) ersetzen durch wirksameres Control, (c) streichen. '
                            . 'Junior-ISB entscheidet nicht allein — Vier-Augen-Prinzip.',
                        'evidence' => 'Schriftliche Freigabe (E-Mail / Workflow-Step) mit gewaehlter Option.',
                    ],
                    [
                        'no' => 4,
                        'title' => 'SoA-Begruendung dokumentieren',
                        'detail' => 'Im Statement of Applicability die Aenderung begruenden: '
                            . 'Quelle (z.B. NIST SP 800-63B §5.1.1.2), Risk-Owner-Freigabe, '
                            . 'Datum, neuer Status. Bei „Streichung" zusaetzlich Annex-A-Cross-Reference.',
                        'evidence' => 'SoA-Eintrag mit Begruendungs-Text und Audit-Trail.',
                    ],
                    [
                        'no' => 5,
                        'title' => 'Restrisiko + Review-Termin',
                        'detail' => 'Lege ein Restrisiko an (auch wenn klein) und setze einen jaehrlichen '
                            . 'Review-Termin. Bedrohungslagen aendern sich — was heute Reibung ist, kann '
                            . 'morgen wieder relevant werden.',
                        'evidence' => 'Risk-Entry + Kalender-Eintrag fuer Annual-Review.',
                    ],
                ],
                'example' => '90-Tage-Passwortwechsel: NIST SP 800-63B (2017) widerlegt Wirksamkeit, '
                    . 'Helpdesk-Tickets steigen, Schutz null. Risk-Owner waehlt Option (c) Streichung. '
                    . 'SoA-Begruendung verweist auf NIST + Risk-Owner-Freigabe vom 2026-04-26.',
                'norm_refs' => [
                    'ISO/IEC 27001:2022 Clause 6.1.3 d) — SoA-Begruendung',
                    'NIST SP 800-63B §5.1.1.2 — Memorized Secret Verifiers',
                    'MRIS v1.5 Kap. 6 — Reine Reibung',
                ],
            ];
        }

        return $this->render('mris/wizard/pure_friction.html.twig', [
            'routine' => $routine,
            'fallback_used' => empty($solutions),
        ]);
    }

    #[Route('/mris/wizard/maturity-evidence', name: 'app_mris_wizard_maturity_evidence', methods: ['GET'])]
    public function maturityEvidence(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $matrix = $solutions['maturity_evidence_per_mhc'] ?? null;

        if (!is_array($matrix) || empty($matrix['mhcs']) || !is_array($matrix['mhcs'])) {
            // Fallback: 13 MHCs aus help-texts.yaml Item 5 + Reifegrad-Stufen
            // aus Item 6. Evidence-Beispiele audit-tauglich nach CMMI-Logik.
            $matrix = [
                'title' => 'Reifegrad-Evidence pro MHC',
                'intro' => 'Pro MHC drei Reifegrade — fuer jeden Reifegrad eine Liste audit-tauglicher '
                    . 'Belege. Initial = „erste Schritte dokumentiert", Defined = „org-weit verbindlich", '
                    . 'Managed = „gemessen, gesteuert, KPI". Klick auf MHC, um die Belegliste zu sehen.',
                'mhcs' => $this->buildDefaultMhcEvidence(),
            ];
        }

        return $this->render('mris/wizard/maturity_evidence.html.twig', [
            'matrix' => $matrix,
            'fallback_used' => empty($solutions),
        ]);
    }

    #[Route('/mris/wizard/ai-risk-class', name: 'app_mris_wizard_ai_risk_class', methods: ['GET'])]
    public function aiRiskClass(): Response
    {
        $solutions = $this->loadBlockerSolutions();
        $matrix = $solutions['ai_risk_decision_matrix'] ?? null;

        if (!is_array($matrix) || empty($matrix['tools']) || !is_array($matrix['tools'])) {
            // Fallback: 12 typische Tools + 4-Step-Decision-Flow nach EU AI Act
            // (Art. 6 + Anhang III). Quellen: AI-Act-Erwaegungsgruende + ENISA-Guidance.
            $matrix = [
                'title' => '12-Tools-Entscheidungsmatrix — AI-Risiko-Klasse',
                'intro' => 'Klassifiziere KI-Tools nach EU AI Act in vier Klassen: minimal, begrenzt, hoch, '
                    . 'verboten. Die Matrix listet 12 Praxis-Tools mit empfohlener Klasse, AI-Act-Verweis '
                    . 'und Begruendung. Fuer eigene Tools nutze den 4-Schritt-Decision-Flow darunter.',
                'decision_flow' => [
                    [
                        'no' => 1,
                        'title' => 'Verbotene Praktik (Art. 5)?',
                        'detail' => 'Social Scoring, biometrische Echtzeit-Identifikation im oeffentlichen '
                            . 'Raum, manipulative Subliminal-Techniken — wenn ja: Verboten, sofort stoppen.',
                    ],
                    [
                        'no' => 2,
                        'title' => 'Hochrisiko-Anwendungsfall (Anhang III)?',
                        'detail' => 'KRITIS-Steuerung, Bildung/Pruefung, Beschaeftigung/HR, kritische '
                            . 'Dienstleistungen, Strafverfolgung, Migration/Asyl, Justiz, Wahlen — wenn ja: '
                            . 'Hochrisiko, volle Konformitaetsbewertung (Art. 6, 8-15).',
                    ],
                    [
                        'no' => 3,
                        'title' => 'Transparenzpflicht (Art. 50)?',
                        'detail' => 'Chatbots, Emotion-Recognition, Biometric-Categorization, Deepfakes, '
                            . 'KI-generierter Content — wenn ja: Begrenztes Risiko, Kennzeichnungspflicht.',
                    ],
                    [
                        'no' => 4,
                        'title' => 'Sonst: Minimales Risiko',
                        'detail' => 'Spam-Filter, Game-AI, Recommender ohne Profiling — keine speziellen '
                            . 'AI-Act-Pflichten, aber MRIS MHC-13 (AI-Governance) und ISO 42001 weiter '
                            . 'einhalten.',
                    ],
                ],
                'tools' => [
                    [
                        'tool' => 'GitHub Copilot',
                        'class' => 'minimal',
                        'class_label' => 'Minimal',
                        'ai_act_ref' => 'Kein Anhang III; GPAI Art. 51-55 (Anbieter)',
                        'rationale' => 'Code-Vorschlaege im Entwickler-Workflow — kein Hochrisiko-Use-Case. '
                            . 'Anbieter-Pflichten beim Modellanbieter, Nutzer-seitig MHC-13 + Coding-Policy.',
                    ],
                    [
                        'tool' => 'Cursor IDE',
                        'class' => 'minimal',
                        'class_label' => 'Minimal',
                        'ai_act_ref' => 'Kein Anhang III; GPAI-Klausel beim Modellanbieter',
                        'rationale' => 'KI-IDE auf GPAI-Basis — wie Copilot. Achtung: Code-Exfil-Risiko, '
                            . 'Extension-Allowlist + Telemetrie-Pruefung in MHC-13 dokumentieren.',
                    ],
                    [
                        'tool' => 'Claude Code (Anthropic)',
                        'class' => 'minimal',
                        'class_label' => 'Minimal',
                        'ai_act_ref' => 'Kein Anhang III; GPAI Art. 51-55 (Anbieter)',
                        'rationale' => 'Agentischer Coding-Assistent — Standard-Tooling. MHC-13 verlangt '
                            . 'Capability-Scope (Schreibrechte, Datei-Zugriff) und Audit-Trail.',
                    ],
                    [
                        'tool' => 'ChatGPT Enterprise',
                        'class' => 'minimal',
                        'class_label' => 'Minimal',
                        'ai_act_ref' => 'Kein Anhang III; Art. 50 falls als Chatbot extern',
                        'rationale' => 'Allgemeiner Office-Assistent. Hochrisiko nur bei spezifischem '
                            . 'Anhang-III-Einsatz (HR-Screening, Bildung). DPA + AVV beachten.',
                    ],
                    [
                        'tool' => 'KI-gestuetzter Recruiter / CV-Screener',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Anhang III Nr. 4 — Beschaeftigung',
                        'rationale' => 'Vorauswahl von Bewerbern oder Personalentscheidungen — explizit '
                            . 'als Hochrisiko gelistet. Volle Konformitaetsbewertung, DSFA, Bias-Audit.',
                    ],
                    [
                        'tool' => 'Robo-Advisor (Finanz-Beratung)',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Anhang III Nr. 5 b) — Kreditwuerdigkeitspruefung',
                        'rationale' => 'Wenn Bonitaets-/Scoring-Funktion enthalten: Hochrisiko. Reine '
                            . 'Portfolio-Optimierung ohne Score: minimal. DORA + MaRisk zusaetzlich.',
                    ],
                    [
                        'tool' => 'Predictive Policing',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Anhang III Nr. 6 — Strafverfolgung',
                        'rationale' => 'Risikoeinschaetzung von Personen oder Gebieten zur Strafverfolgung '
                            . '— Hochrisiko. Profiling natuerlicher Personen kann nach Art. 5 sogar '
                            . 'verboten sein.',
                    ],
                    [
                        'tool' => 'Medical-Imaging-Diagnose-KI',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Art. 6 Abs. 1 + MDR (Medizinprodukt)',
                        'rationale' => 'Sicherheitsbauteil eines Medizinprodukts unter MDR — Hochrisiko per '
                            . 'Art. 6 Abs. 1. Konformitaetsbewertung integriert mit MDR-Verfahren.',
                    ],
                    [
                        'tool' => 'Fraud-Detection (Banking)',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Anhang III Nr. 5 b) — Kreditwuerdigkeit / Finanz',
                        'rationale' => 'Wenn Auswirkungen auf Kunden-Bonitaet oder Kontosperrung: '
                            . 'Hochrisiko. Reine Backend-Anomalie-Erkennung ohne Kundenwirkung: minimal. '
                            . 'BaFin + DORA zusaetzlich.',
                    ],
                    [
                        'tool' => 'Marketing-Recommender (E-Commerce)',
                        'class' => 'minimal',
                        'class_label' => 'Minimal',
                        'ai_act_ref' => 'Kein Anhang III; DSGVO Art. 22 bei Profiling',
                        'rationale' => 'Produktvorschlaege ohne automatisierte Einzelfallentscheidung — '
                            . 'minimal. Bei automatisierten Preis-/Kreditentscheidungen wird daraus '
                            . 'Hochrisiko.',
                    ],
                    [
                        'tool' => 'Smart-Home-Sprachassistent',
                        'class' => 'limited',
                        'class_label' => 'Begrenzt',
                        'ai_act_ref' => 'Art. 50 — Transparenzpflicht (Chatbot)',
                        'rationale' => 'Chatbot-aehnliche Interaktion — Nutzer muss erkennen, dass er mit '
                            . 'KI spricht. Keine Hochrisiko-Funktion, aber Kennzeichnungspflicht.',
                    ],
                    [
                        'tool' => 'KRITIS-Steuerung (SCADA + KI)',
                        'class' => 'high',
                        'class_label' => 'Hochrisiko',
                        'ai_act_ref' => 'Anhang III Nr. 2 — Kritische Infrastruktur',
                        'rationale' => 'KI als Sicherheitsbauteil kritischer digitaler oder physischer '
                            . 'Infrastruktur — Hochrisiko. NIS2 + MRIS MHC-13 + ISO 42001 obligatorisch.',
                    ],
                ],
                'norm_refs' => [
                    'EU AI Act (VO 2024/1689) Art. 5, 6, 50 + Anhang III',
                    'ISO/IEC 42001:2023 — AI Management System',
                    'MRIS v1.5 MHC-13 — AI/Agent Governance',
                    'ENISA Multilayer Framework for Good Cybersecurity Practices for AI (2023)',
                ],
            ];
        }

        return $this->render('mris/wizard/ai_risk_class.html.twig', [
            'matrix' => $matrix,
            'fallback_used' => empty($solutions),
        ]);
    }

    /**
     * Default-Evidence-Liste fuer alle 13 MHCs, je 3 Reifegrade.
     * Quelle: help-texts.yaml MHC-Codes-Item 5 + Reifegrad-Item 6.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDefaultMhcEvidence(): array
    {
        return [
            [
                'code' => 'MHC-01',
                'name' => 'Identitaet & MFA',
                'levels' => [
                    'initial' => [
                        'MFA-Pilot dokumentiert (Konfig-Screenshot, Tester-Liste).',
                        'IdP-Inventar als Tabelle (manuell gepflegt).',
                    ],
                    'defined' => [
                        'Org-Policy „MFA verpflichtend fuer alle User", freigegeben durch Geschaeftsleitung.',
                        'Coverage-Bericht je Quartal, Abweichungen mit Risk-Akzeptanz.',
                    ],
                    'managed' => [
                        'KPI: phishing-resistente MFA-Coverage > 95% (FIDO2/WebAuthn).',
                        'Automatischer IdP-Drift-Alert + monatlicher Review im Management-Report.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-02',
                'name' => 'Endpoint',
                'levels' => [
                    'initial' => [
                        'EDR auf >50% der Server-Endpoints, Wartungsplan vorhanden.',
                        'Aktuelle Hardening-Baseline (CIS / BSI SiSyPHuS) als Dokument.',
                    ],
                    'defined' => [
                        'Org-weit verbindliche EDR-Policy, alle Server + 80% Clients.',
                        'Quartalsweiser Compliance-Scan gegen Baseline mit Abweichungs-Tracking.',
                    ],
                    'managed' => [
                        'KPI: EDR-Coverage > 98%, MTTD < 24h.',
                        'Automatisches Asset-Discovery + Drift-Reporting an SIEM.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-03',
                'name' => 'Privileged Access',
                'levels' => [
                    'initial' => [
                        'Liste privilegierter Accounts (Admin / Service) gepflegt.',
                        'Manuelle PIM-Reviews mit Vier-Augen-Prinzip dokumentiert.',
                    ],
                    'defined' => [
                        'PAM-Loesung im Einsatz, alle Tier-0-Zugaenge ueber Vault.',
                        'Just-in-Time-Access-Policy und quartalsweises Re-Certification.',
                    ],
                    'managed' => [
                        'KPI: Standing-Privilege-Anteil < 5%, Session-Recording aktiv.',
                        'Automatisierte Anomalie-Erkennung auf privilegierten Sessions.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-04',
                'name' => 'Patching',
                'levels' => [
                    'initial' => [
                        'Patch-Backlog-Liste (manuell), Schwellwert „kritisch / hoch".',
                        'Monatliches Patch-Window mit Change-Ticket.',
                    ],
                    'defined' => [
                        'SLA: KEV-Patches < 14 Tage, Critical < 30 Tage, schriftlich freigegeben.',
                        'Patch-Compliance-Report je Asset-Klasse, Eskalation bei Verstoss.',
                    ],
                    'managed' => [
                        'KPI: KEV-Patch-Latency-Median < 7 Tage, automatisches Tracking.',
                        'Vulnerability-Scanner + Ticket-Auto-Erstellung + Asset-Verknuepfung.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-05',
                'name' => 'Backup & Recovery',
                'levels' => [
                    'initial' => [
                        'Backup-Plan dokumentiert, mindestens ein Restore-Test im letzten Jahr.',
                        '3-2-1-Regel als Soll, Abweichungen begruendet.',
                    ],
                    'defined' => [
                        'Org-weite Backup-Policy mit RPO/RTO je Asset-Klasse.',
                        'Halbjaehrlicher Restore-Test pro kritischem System, Protokoll.',
                    ],
                    'managed' => [
                        'KPI: Restore-Test-Success-Rate > 95%, Immutable-Backup-Coverage > 80%.',
                        'Automatisierte Restore-Tests + Auswertung in Management-Review.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-06',
                'name' => 'Logging & Detection',
                'levels' => [
                    'initial' => [
                        'Zentrale Log-Sammlung fuer Server + IdP, Aufbewahrung 90 Tage.',
                        'Erste Detection-Use-Cases (Failed-Logon, Admin-Anomalie).',
                    ],
                    'defined' => [
                        'SIEM-Use-Cases gegen MITRE ATT&CK gemappt, dokumentiert.',
                        'Tier-1-Triage-SOP, Eskalationswege ans Incident-Team.',
                    ],
                    'managed' => [
                        'KPI: MTTD < 1h, Use-Case-Coverage > 70% Tactic-Spread.',
                        'Threat-Hunting-Backlog + Continuous-Validation (Atomic Red Team).',
                    ],
                ],
            ],
            [
                'code' => 'MHC-07',
                'name' => 'Email & Phishing',
                'levels' => [
                    'initial' => [
                        'SPF + DKIM + DMARC mit p=none aktiv, Reports gesammelt.',
                        'Phishing-Mailbox / Report-Button im Mail-Client verfuegbar.',
                    ],
                    'defined' => [
                        'DMARC p=quarantine oder reject org-weit, regelmaessige Reports.',
                        'Phishing-Awareness-Trainings 2x/Jahr, Klickraten-Reporting.',
                    ],
                    'managed' => [
                        'KPI: Phishing-Klick-Rate < 5%, Report-Rate > 25%.',
                        'Automatische URL-Detonation + In-Kontext-Coaching nach Klick.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-08',
                'name' => 'Network Segmentation',
                'levels' => [
                    'initial' => [
                        'Netzplan vorhanden, mindestens DMZ + interne Zone getrennt.',
                        'Firewall-Regeln dokumentiert, manuelles Review jaehrlich.',
                    ],
                    'defined' => [
                        'Zonen-Modell org-weit (Mgmt, OT, Office, DMZ), Default-Deny.',
                        'Microsegmentation in kritischen Bereichen, dokumentiert.',
                    ],
                    'managed' => [
                        'KPI: East-West-Traffic-Visibility > 80%, Lateral-Movement-Tests bestanden.',
                        'Automatisches Policy-Drift-Reporting + Topologie-Inventar.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-09',
                'name' => 'Cloud Hardening',
                'levels' => [
                    'initial' => [
                        'CSPM-Tool aktiv, monatlicher Findings-Bericht.',
                        'IAM-Baseline (Root-Account-Schutz, MFA fuer Admin) dokumentiert.',
                    ],
                    'defined' => [
                        'Landing-Zone mit Guardrails (SCP / Azure Policy), org-weit verbindlich.',
                        'CCM-Mapping zu Cloud-Controls-Matrix v4.x je Provider.',
                    ],
                    'managed' => [
                        'KPI: CCM-Coverage > 90%, Critical-Misconfig-MTTR < 24h.',
                        'Automatisches Drift-Reporting + Continuous-Compliance-Scan.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-10',
                'name' => 'Supply Chain',
                'levels' => [
                    'initial' => [
                        'Lieferanten-Liste mit Risiko-Klasse (manuell).',
                        'AVV / DPA fuer kritische Anbieter abgelegt.',
                    ],
                    'defined' => [
                        'SBOM-Pflicht fuer eigene Software, Lieferanten-Audit-Programm.',
                        'TPRM-Workflow mit jaehrlicher Re-Assessment fuer Tier-1-Lieferanten.',
                    ],
                    'managed' => [
                        'KPI: SBOM-Coverage > 90%, Supplier-Risk-Score in KPI-Dashboard.',
                        'Automatische Vuln-Korrelation SBOM x KEV + DORA-Register-Sync.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-11',
                'name' => 'Awareness & Training',
                'levels' => [
                    'initial' => [
                        'Onboarding-Schulung mit Security-Modul, Teilnehmer-Liste.',
                        'Jaehrliche Awareness-Mail mit Klick-Tracking.',
                    ],
                    'defined' => [
                        'Rollenbasierte Trainings (Dev, Admin, User, Mgmt), Quizz-Pflicht.',
                        'Phishing-Simulationen 4x/Jahr, Auswertung im Mgmt-Report.',
                    ],
                    'managed' => [
                        'KPI: Training-Completion > 95%, Phishing-Resistance-Score gemessen.',
                        'Adaptive Lernpfade + Just-in-Time-Coaching nach Incidents.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-12',
                'name' => 'Incident Response',
                'levels' => [
                    'initial' => [
                        'IR-Plan dokumentiert, Eskalationswege definiert.',
                        'Letzter Tabletop-Test im laufenden Jahr.',
                    ],
                    'defined' => [
                        'Playbooks fuer Top-5-Szenarien (Ransomware, BEC, Datenleck, DDoS, Insider).',
                        'Halbjaehrlicher Live-Drill + Post-Mortem-Pflicht mit Lessons-Learned.',
                    ],
                    'managed' => [
                        'KPI: MTTR-Median < SLA, Lessons-Learned in Backlog umgesetzt > 80%.',
                        'TLPT (DORA Art. 26) bestanden + Retainer-Vertrag mit DFIR-Anbieter.',
                    ],
                ],
            ],
            [
                'code' => 'MHC-13',
                'name' => 'AI / Agent Governance',
                'levels' => [
                    'initial' => [
                        'AI-Agent-Inventar gepflegt mit Capability-Scope.',
                        'Erste Bedrohungsmodelle fuer 2-3 Top-Agents dokumentiert.',
                    ],
                    'defined' => [
                        'AI-Use-Policy + Extension-Allowlist org-weit verbindlich.',
                        'Pflicht-Bedrohungsmodell + Doku je Agent vor Go-Live.',
                    ],
                    'managed' => [
                        'KPI: Doku-Vollstaendigkeit > 90%, Risiko-Klasse je Agent reviewed.',
                        'EU-AI-Act-Klassifikation + ISO 42001-Konformitaet dokumentiert.',
                    ],
                ],
            ],
        ];
    }
}
