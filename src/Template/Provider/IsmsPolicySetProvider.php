<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\Document;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * ISO 27001 baseline policy set (6 documents).
 *
 * Foundation P-14. Six policies every ISO 27001 ISMS needs on day one:
 *  - ISMS-Leitlinie (Information Security Policy)
 *  - AUP (Acceptable Use Policy)
 *  - Access Control Policy
 *  - Crypto Policy
 *  - Business Continuity Policy
 *  - Incident Response Plan
 *
 * Each template fabricates a Document entity with `policyBody` prefilled
 * with a 1-3 KB Markdown starter draft. The Apply-Controller maps every
 * template to a draft Document under a `policy/<slug>.md` virtual path —
 * the user then refines content via the existing policy-body editor.
 */
final class IsmsPolicySetProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        $defs = $this->policyDefinitions();

        foreach (['de', 'en'] as $lang) {
            foreach ($defs as $slug => $def) {
                $de = $lang === 'de';
                $title = $de ? $def['title_de'] : $def['title_en'];
                $body = $de ? $def['body_de'] : $def['body_en'];

                yield new SystemTemplate(
                    key: 'document.policy.' . $slug . '.' . $lang,
                    entityClass: Document::class,
                    module: 'documents',
                    language: $lang,
                    name: $title,
                    description: $de ? $def['description_de'] : $def['description_en'],
                    prefill: [
                        'filename' => sprintf('policy_%s_%s.md', $slug, $lang),
                        'originalFilename' => sprintf('policy_%s.md', $slug),
                        'mimeType' => 'text/markdown',
                        'fileSize' => strlen($body),
                        'filePath' => sprintf('templates/policies/%s_%s.md', $slug, $lang),
                        'category' => $def['category'],
                        'description' => $de ? $def['description_de'] : $def['description_en'],
                        'status' => 'draft',
                        'tisaxInformationClassification' => 'internal',
                        'policyBody' => $body,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function policyDefinitions(): array
    {
        return [
            'isms-leitlinie' => [
                'category' => 'policy',
                'title_de' => 'ISMS-Leitlinie',
                'title_en' => 'Information Security Policy',
                'description_de' => 'Übergeordnete Leitlinie zum ISMS gemäß ISO 27001 Kap. 5.2 — Geltungsbereich, Ziele, Verantwortlichkeiten.',
                'description_en' => 'Top-level ISMS policy per ISO 27001 Cl. 5.2 — scope, objectives, responsibilities.',
                'body_de' => <<<MD
                # ISMS-Leitlinie

                ## 1. Zweck und Geltungsbereich
                Diese Leitlinie definiert das Informationssicherheits-Managementsystem (ISMS) der Organisation gemäß **ISO/IEC 27001:2022 Kap. 5.2**. Sie gilt für alle Mitarbeitenden, Auftragnehmer und externen Dienstleister, die Zugang zu Informationswerten haben.

                ## 2. Sicherheitsziele
                - Schutz der **Vertraulichkeit, Integrität und Verfügbarkeit** aller relevanten Informationswerte
                - Erfüllung **gesetzlicher und vertraglicher Anforderungen** (DSGVO, BSI, branchenspezifische Vorgaben)
                - Kontinuierliche Verbesserung des ISMS gemäß **PDCA-Zyklus**

                ## 3. Verantwortlichkeiten
                | Rolle | Verantwortung |
                |---|---|
                | Geschäftsführung | Ressourcenbereitstellung, Freigabe der Leitlinie, Management Review |
                | CISO / ISB | Operativer Betrieb des ISMS, Risikobewertung, Berichterstattung |
                | Fachbereiche | Umsetzung von Maßnahmen, Meldung von Vorfällen |
                | Mitarbeitende | Einhaltung dieser Leitlinie, Schulungsteilnahme |

                ## 4. Risikomanagement
                Risiken werden gemäß **ISO 27005** identifiziert, bewertet und behandelt. Die Risikoakzeptanzkriterien werden jährlich überprüft.

                ## 5. Verstöße
                Verstöße gegen diese Leitlinie können disziplinarische Maßnahmen nach sich ziehen.

                ## 6. Geltung
                Diese Leitlinie tritt mit Freigabe durch die Geschäftsführung in Kraft und wird mindestens jährlich überprüft.

                **Version:** 1.0 — *Entwurf, von ISMS-Vorlage generiert*
                MD,
                'body_en' => <<<MD
                # Information Security Policy

                ## 1. Purpose and Scope
                This policy defines the organisation's Information Security Management System (ISMS) per **ISO/IEC 27001:2022 Cl. 5.2**. It applies to all employees, contractors, and third-party service providers with access to information assets.

                ## 2. Security Objectives
                - Protection of **confidentiality, integrity, and availability** of all relevant information assets
                - Compliance with **legal and contractual obligations** (GDPR, sectoral requirements)
                - Continual improvement of the ISMS per the **PDCA cycle**

                ## 3. Responsibilities
                | Role | Responsibility |
                |---|---|
                | Executive Management | Resource provision, policy approval, management review |
                | CISO | Operational ISMS, risk assessment, reporting |
                | Business units | Implementation of measures, incident reporting |
                | Employees | Compliance with this policy, training participation |

                ## 4. Risk Management
                Risks are identified, assessed, and treated per **ISO/IEC 27005**. Risk-acceptance criteria are reviewed annually.

                ## 5. Violations
                Breaches of this policy may result in disciplinary action.

                ## 6. Effective Date
                This policy enters into force upon approval by executive management and is reviewed at least annually.

                **Version:** 1.0 — *Draft, generated from ISMS template*
                MD,
            ],
            'aup' => [
                'category' => 'policy',
                'title_de' => 'Richtlinie zur akzeptablen Nutzung (AUP)',
                'title_en' => 'Acceptable Use Policy (AUP)',
                'description_de' => 'Erlaubte und untersagte Nutzung von IT-Ressourcen gemäß ISO 27001 A.5.10.',
                'description_en' => 'Permitted and prohibited use of IT resources per ISO 27001 A.5.10.',
                'body_de' => <<<MD
                # Richtlinie zur akzeptablen Nutzung (AUP)

                ## 1. Geltungsbereich
                Diese Richtlinie regelt die Nutzung von IT-Systemen, Netzwerken, E-Mail, Internet, mobilen Endgeräten und Cloud-Diensten gemäß **ISO/IEC 27001 A.5.10**.

                ## 2. Erlaubte Nutzung
                - Geschäftliche Tätigkeiten im Rahmen des Arbeitsverhältnisses
                - Eingeschränkte Privatnutzung, sofern nicht ausdrücklich anders geregelt
                - Nutzung freigegebener Software und Cloud-Dienste

                ## 3. Untersagte Nutzung
                - Aufruf rechtswidriger, beleidigender oder pornografischer Inhalte
                - Installation nicht freigegebener Software
                - Umgehen von Sicherheitsmechanismen (z.B. VPN, MFA)
                - Weitergabe von Zugangsdaten
                - Nutzung privater Cloud-Dienste für dienstliche Daten ohne Freigabe

                ## 4. Verantwortlichkeiten
                Jede Person, die IT-Ressourcen nutzt, ist verantwortlich für die Einhaltung dieser Richtlinie.

                ## 5. Überwachung
                Stichprobenhafte Protokollkontrollen sind im Rahmen der gesetzlichen Möglichkeiten zulässig (vorab Information durch Betriebsrat / Datenschutzbeauftragten).

                **Version:** 1.0
                MD,
                'body_en' => <<<MD
                # Acceptable Use Policy (AUP)

                ## 1. Scope
                This policy governs the use of IT systems, networks, email, internet, mobile devices, and cloud services per **ISO/IEC 27001 A.5.10**.

                ## 2. Permitted Use
                - Business activities within the scope of employment
                - Limited personal use unless explicitly restricted
                - Use of approved software and cloud services

                ## 3. Prohibited Use
                - Accessing illegal, offensive, or pornographic content
                - Installing unapproved software
                - Bypassing security mechanisms (e.g. VPN, MFA)
                - Sharing credentials
                - Using personal cloud services for business data without approval

                ## 4. Responsibilities
                Every user is responsible for compliance with this policy.

                ## 5. Monitoring
                Sample log reviews are permitted within legal limits (subject to works-council / DPO consultation).

                **Version:** 1.0
                MD,
            ],
            'access-control' => [
                'category' => 'policy',
                'title_de' => 'Zugriffskontroll-Richtlinie',
                'title_en' => 'Access Control Policy',
                'description_de' => 'Vergabe, Verwaltung und Entzug von Zugriffsrechten gemäß ISO 27001 A.5.15-A.5.18.',
                'description_en' => 'Granting, managing, and revoking access rights per ISO 27001 A.5.15-A.5.18.',
                'body_de' => <<<MD
                # Zugriffskontroll-Richtlinie

                ## 1. Grundsätze
                Zugriffsrechte werden nach den Prinzipien **Need-to-Know** und **Least Privilege** vergeben (ISO 27001 A.5.15).

                ## 2. Benutzerlebenszyklus (Joiner / Mover / Leaver)
                | Phase | Maßnahme |
                |---|---|
                | Joiner | HR-Trigger → Provisioning mit Standard-Rollen |
                | Mover | Vorgesetzten-Freigabe + Entzug nicht mehr benötigter Rechte |
                | Leaver | Sofort-Sperrung am letzten Arbeitstag, Audit-Log |

                ## 3. Privilegierte Zugriffe
                - Admin-Konten ausschließlich personalisiert
                - MFA verpflichtend
                - Vier-Augen-Prinzip bei kritischen Änderungen
                - Vierteljährliche Re-Zertifizierung

                ## 4. Externe Zugriffe
                Auftragnehmer erhalten zeitlich befristete Zugänge mit dokumentierter Freigabe.

                **Version:** 1.0
                MD,
                'body_en' => <<<MD
                # Access Control Policy

                ## 1. Principles
                Access rights are granted following **Need-to-Know** and **Least Privilege** principles (ISO 27001 A.5.15).

                ## 2. Joiner / Mover / Leaver Lifecycle
                | Phase | Action |
                |---|---|
                | Joiner | HR trigger → provisioning with default roles |
                | Mover | Manager approval + revocation of no-longer-needed rights |
                | Leaver | Immediate lock on last working day, audit log |

                ## 3. Privileged Access
                - Personalised admin accounts only
                - MFA mandatory
                - Four-eyes principle for critical changes
                - Quarterly recertification

                ## 4. External Access
                Contractors receive time-limited access with documented approval.

                **Version:** 1.0
                MD,
            ],
            'crypto' => [
                'category' => 'policy',
                'title_de' => 'Kryptografie-Richtlinie',
                'title_en' => 'Cryptography Policy',
                'description_de' => 'Mindestanforderungen an Verschlüsselung gemäß ISO 27001 A.8.24 und BSI TR-02102.',
                'description_en' => 'Minimum cryptographic requirements per ISO 27001 A.8.24 and BSI TR-02102.',
                'body_de' => <<<MD
                # Kryptografie-Richtlinie

                ## 1. Geltungsbereich
                Diese Richtlinie regelt den Einsatz kryptografischer Verfahren im Unternehmen (ISO 27001 A.8.24).

                ## 2. Mindeststandards
                | Anwendungsfall | Algorithmus | Mindestlänge |
                |---|---|---|
                | Symmetrisch | AES-GCM | 256 Bit |
                | Asymmetrisch | RSA / ECDSA | RSA 3072 / ECC 256 |
                | Hashing | SHA-2 / SHA-3 | 256 Bit |
                | TLS | TLS 1.2 mit PFS | — |
                | Passwort-Hashing | Argon2id / scrypt | Speicher >= 64 MB |

                Verboten: **MD5, SHA-1, RC4, DES, 3DES, TLS 1.0/1.1**.

                ## 3. Schlüsselverwaltung
                - Schlüsselrotation mindestens jährlich
                - Hardware Security Module (HSM) für hochsensitive Schlüssel
                - Backup von Schlüsseln in separatem, kontrolliertem Speicher

                ## 4. Referenzen
                - BSI TR-02102 (jährlich aktualisiert)
                - NIST SP 800-57

                **Version:** 1.0
                MD,
                'body_en' => <<<MD
                # Cryptography Policy

                ## 1. Scope
                This policy governs the use of cryptographic mechanisms (ISO 27001 A.8.24).

                ## 2. Minimum Standards
                | Use case | Algorithm | Minimum strength |
                |---|---|---|
                | Symmetric | AES-GCM | 256 bit |
                | Asymmetric | RSA / ECDSA | RSA 3072 / ECC 256 |
                | Hashing | SHA-2 / SHA-3 | 256 bit |
                | TLS | TLS 1.2 with PFS | — |
                | Password hashing | Argon2id / scrypt | memory >= 64 MB |

                Forbidden: **MD5, SHA-1, RC4, DES, 3DES, TLS 1.0/1.1**.

                ## 3. Key Management
                - Annual key rotation minimum
                - HSM for highly sensitive keys
                - Key backup in separately controlled storage

                ## 4. References
                - BSI TR-02102 (updated annually)
                - NIST SP 800-57

                **Version:** 1.0
                MD,
            ],
            'bc' => [
                'category' => 'policy',
                'title_de' => 'Business-Continuity-Richtlinie',
                'title_en' => 'Business Continuity Policy',
                'description_de' => 'Rahmenrichtlinie BCM gemäß ISO 22301 + ISO 27001 A.5.29-A.5.30.',
                'description_en' => 'BCM framework policy per ISO 22301 + ISO 27001 A.5.29-A.5.30.',
                'body_de' => <<<MD
                # Business-Continuity-Richtlinie

                ## 1. Zweck
                Sicherstellung der Fortführung kritischer Geschäftsprozesse bei Störungen und Krisen (ISO 22301, ISO 27001 A.5.29-A.5.30).

                ## 2. Anwendungsbereich
                Alle Geschäftsprozesse mit identifizierter Mindestaufrechterhaltungszeit (Maximum Tolerable Period of Disruption — MTPD).

                ## 3. RTO/RPO-Klassen
                | Stufe | RTO | RPO | Beispielprozesse |
                |---|---|---|---|
                | Kritisch | <= 4h | <= 1h | Kundenportal, Zahlungsverkehr |
                | Hoch | <= 24h | <= 4h | ERP, CRM |
                | Mittel | <= 72h | <= 24h | Reporting, Backoffice |
                | Niedrig | > 72h | > 24h | Archiv |

                ## 4. Übungen
                - Tabletop-Übung mindestens jährlich
                - Vollständige Wiederherstellungs-Übung mindestens alle 2 Jahre
                - Lessons Learned dokumentiert

                ## 5. Krisenstab
                Bei Aktivierung des Krisenstabs gelten die Notfall-Handlungsanweisungen (separat dokumentiert).

                **Version:** 1.0
                MD,
                'body_en' => <<<MD
                # Business Continuity Policy

                ## 1. Purpose
                Continuity of critical business processes during disruptions and crises (ISO 22301, ISO 27001 A.5.29-A.5.30).

                ## 2. Scope
                All business processes with identified Maximum Tolerable Period of Disruption (MTPD).

                ## 3. RTO/RPO Tiers
                | Tier | RTO | RPO | Example processes |
                |---|---|---|---|
                | Critical | <= 4h | <= 1h | Customer portal, payment |
                | High | <= 24h | <= 4h | ERP, CRM |
                | Medium | <= 72h | <= 24h | Reporting, back-office |
                | Low | > 72h | > 24h | Archive |

                ## 4. Exercises
                - Tabletop exercise at least annually
                - Full recovery exercise at least every 2 years
                - Documented lessons learned

                ## 5. Crisis Team
                Activation triggers the documented crisis-response plan.

                **Version:** 1.0
                MD,
            ],
            'incident-response' => [
                'category' => 'policy',
                'title_de' => 'Incident-Response-Plan',
                'title_en' => 'Incident Response Plan',
                'description_de' => 'Behandlung von Sicherheitsvorfällen gemäß ISO 27001 A.5.24-A.5.28.',
                'description_en' => 'Security incident handling per ISO 27001 A.5.24-A.5.28.',
                'body_de' => <<<MD
                # Incident-Response-Plan

                ## 1. Zweck
                Strukturierte Behandlung von Sicherheitsvorfällen (ISO 27001 A.5.24-A.5.28).

                ## 2. Phasen
                1. **Identifikation** — Meldung über zentrales Postfach `security@<organisation>`
                2. **Triage** — Klassifizierung (low/medium/high/critical) durch CISO
                3. **Eindämmung** — Isolation betroffener Systeme
                4. **Beseitigung** — Ursachenbehebung, Wiederherstellung
                5. **Lessons Learned** — Dokumentation, Maßnahmen-Ableitung

                ## 3. Meldepflichten
                - **DSGVO Art. 33** — Aufsichtsbehörde innerhalb 72h bei personenbezogenen Datenverletzungen
                - **NIS2 Art. 23** — Frühwarnung an CSIRT innerhalb 24h
                - **DORA Art. 19** — ICT-Major-Incident an Aufsichtsbehörde unverzüglich

                ## 4. Rollen
                | Rolle | Verantwortung |
                |---|---|
                | CISO | Gesamt-Eskalation, Behördenkommunikation |
                | ISB | Operative Behandlung |
                | DSB | DSGVO-Bewertung |
                | Krisenstab | Bei kritischen Vorfällen |

                ## 5. Übungen
                Mindestens halbjährlich Incident-Drill.

                **Version:** 1.0
                MD,
                'body_en' => <<<MD
                # Incident Response Plan

                ## 1. Purpose
                Structured handling of security incidents (ISO 27001 A.5.24-A.5.28).

                ## 2. Phases
                1. **Identification** — report via central inbox `security@<organisation>`
                2. **Triage** — classification (low/medium/high/critical) by CISO
                3. **Containment** — isolation of affected systems
                4. **Eradication** — root-cause fix, recovery
                5. **Lessons Learned** — documentation, derived actions

                ## 3. Reporting Duties
                - **GDPR Art. 33** — supervisory authority within 72h for personal-data breaches
                - **NIS2 Art. 23** — early warning to CSIRT within 24h
                - **DORA Art. 19** — major ICT incident to authority without delay

                ## 4. Roles
                | Role | Responsibility |
                |---|---|
                | CISO | Overall escalation, authority communication |
                | ISO | Operational handling |
                | DPO | GDPR assessment |
                | Crisis team | For critical incidents |

                ## 5. Exercises
                Incident drill at least semi-annually.

                **Version:** 1.0
                MD,
            ],
        ];
    }
}
