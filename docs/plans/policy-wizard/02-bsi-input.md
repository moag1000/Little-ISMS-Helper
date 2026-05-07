# BSI IT-Grundschutz — Pflicht-Richtlinien-Set

**Quelle:** BSI IT-Grundschutz-Kompendium Edition 2023 (Stand: Februar 2023), BSI-Standards 200-1, 200-2, 200-3, 200-4 (200-4 final 2023).
**Scope:** Wizard-Spezifikation für Auto-Generierung des Pflicht-Richtlinien-Sets bei reiner BSI-Compliance oder ISO/BSI-Dual-Compliance.
**Persona:** BSI-Specialist, konsultierend für Policy-Wizard.

> **Lese-Hinweis:** Baustein-IDs folgen dem Format `SCHICHT.NUMMER.ANFORDERUNG` (z. B. `ISMS.1.A6` = Schicht ISMS, Baustein 1, Anforderung 6). Die Edition 2023 ist die maßgebliche Referenz; Edition 2022 hat noch leicht abweichende Bausteinstruktur (insbesondere in CON, NET, INF). Wir referenzieren 2023.

---

## 1. Top-Level Leitlinie

### 1.1 IT-Sicherheitsleitlinie (Pflicht-Dokument Nr. 1)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ISMS.1.A4` (Leitlinie zur Informationssicherheit) — Basis-Anforderung |
| **Verstärkende Anforderung** | `ISMS.1.A5` (Vertrag mit der Leitungsebene), `ISMS.1.A2` (Übernahme der Gesamtverantwortung durch die Leitung) |
| **Pflicht-Niveau** | **Basis** — Pflicht in allen drei Vorgehensweisen (Basis-, Standard-, Kern-Absicherung) |
| **Mandatierender Standard** | BSI 200-1 Kap. 6.2 + BSI 200-2 Kap. 4 |
| **ISO-27001-Mapping** | Annex A 5.1 (Policies for information security) + Clause 5.2 (Information security policy) |
| **Review-Zyklus** | Mindestens alle 2 Jahre, anlassbezogen sofort (§ ISMS.1.A11) |

#### Pflicht-Inhaltsabschnitte (lt. BSI 200-2 Kap. 4)
1. Stellenwert der Informationssicherheit für Institution
2. Sicherheitsziele (Vertraulichkeit, Integrität, Verfügbarkeit, ggf. Authentizität, Verbindlichkeit)
3. Verbindung zwischen Geschäftszielen und Sicherheitszielen
4. Bezug zu gesetzlichen / regulatorischen Verpflichtungen (BSIG, KRITIS-DachG, NIS2-UmsuCG, ggf. DSGVO)
5. Geltungsbereich (organisatorisch, geographisch, technisch)
6. Wahl der Vorgehensweise (Basis / Standard / Kern)
7. Sicherheitsorganisation: ISB, IS-Management-Team, Verantwortliche
8. Verbindlichkeit für alle Mitarbeitenden
9. Verpflichtung zur kontinuierlichen Verbesserung
10. Genehmigung durch die Leitungsebene (mit Datum + Unterschrift)

#### Tenant-Settings-Inputs (Wizard erfragt)
- `tenant.legal_name` (Behörden- oder Unternehmensname, voll ausformuliert)
- `tenant.legal_form` (Behörde / Körperschaft / GmbH / AG / e.V. / KdöR)
- `tenant.scope_description` (z. B. "Sämtliche IT-Verfahren der Bundesbehörde X am Standort Bonn")
- `tenant.scope_geographic` (Standorte: Mehrfachauswahl)
- `tenant.scope_organizational` (Abteilungen: Mehrfachauswahl oder "alle")
- `tenant.scope_excluded` (Freitext: Was ist explizit AUS dem Scope?)
- `tenant.security_goals_priority` (CIA + optional A/V Reihenfolge)
- `tenant.isb_name`, `tenant.isb_email`, `tenant.isb_role_in_org`
- `tenant.is_management_committee_members` (Liste der ISMS-Team-Mitglieder)
- `tenant.leadership_signatory_name`, `tenant.leadership_signatory_role`
- `tenant.legal_basis` (BSIG §8a / KRITIS-DachG / NIS2 / Behördenpflicht / Freiwillig)
- `tenant.review_cycle_months` (Default 24, BSI-Pflicht max. 24)

#### Genehmigungskette
- ISB-Vorbereitung → ISMS-Team-Review → Leitungsebene-Freigabe (4-Augen-Prinzip mandatory; siehe `ISMS.1.A2`)
- Wizard erzeugt Workflow-Instance: "Leitlinien-Genehmigung" mit 3 Schritten + Audit-Log

---

## 2. Schicht-spezifische Richtlinien

### 2.1 Schicht ISMS (Sicherheitsmanagement)

#### 2.1.1 ISMS-Konzept (Sicherheitskonzept)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ISMS.1.A6` (Erstellung eines Sicherheitskonzepts) — Basis |
| **Verstärkende Anforderungen** | `ISMS.1.A7` (Aufrechterhaltung), `ISMS.1.A11` (Aktualisierung) |
| **Pflicht-Niveau** | Basis |
| **Mandatierender Standard** | BSI 200-2 Kap. 5–9 |
| **ISO-27001-Mapping** | Clause 4.3 (Scope), Clause 6 (Planning), Annex A 5.1 |
| **Review-Zyklus** | Bei jeder Strukturänderung; mindestens jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Strukturanalyse (Zielobjekte, Anwendungen, IT-Systeme, Räume, Kommunikationsverbindungen)
2. Schutzbedarfsfeststellung (siehe Kap. 4)
3. Modellierung nach IT-Grundschutz (Bausteinzuordnung)
4. IT-Grundschutz-Check (Soll/Ist)
5. Risikoanalyse (bei "hoch"/"sehr hoch" oder Kern-Absicherung — siehe BSI 200-3)
6. Konsolidierung der Sicherheitsmaßnahmen
7. Realisierungsplan (Priorisierung, Verantwortliche, Termine, Restrisiken)

**Tenant-Settings-Inputs:**
- `tenant.bsi_methodology` (Basis / Standard / Kern)
- `tenant.protection_levels` (Default: normal/hoch/sehr hoch — BSI-Standard 3-stufig)
- `tenant.modeling_approach` (Vollständige Modellierung Y/N)

**Verlinkte Bausteine:** Alle anwendbaren aus Modellierung — wird durch Wizard automatisch befüllt nach Strukturanalyse.

---

#### 2.1.2 Sicherheitsorganisation / ISMS-Rollenkonzept

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ISMS.1.A1` (Übernahme der Gesamtverantwortung), `ISMS.1.A3` (Festlegung Sicherheitsorganisation), `ISMS.1.A8` (Integration in Abläufe), `ISMS.1.A10` (Erstellung der Sicherheitskonzeption) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Clause 5.3 (Roles, responsibilities), Annex A 5.2 (Roles), 5.3 (Segregation of duties) |
| **Review-Zyklus** | Bei jedem Personalwechsel, mindestens jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Rolle ISB (Zuständigkeit, Befugnisse, Berichtsweg direkt zur Leitungsebene)
2. IS-Management-Team (Zusammensetzung, Sitzungsfrequenz)
3. Bereichs-/Projekt-ISBs (falls Standard-Absicherung)
4. Verantwortliche je Schicht (Anwendungsverantwortliche, Systemverantwortliche)
5. Eskalationswege
6. Zusammenarbeit mit Datenschutzbeauftragtem (DSB), Risikomanagement, Notfallbeauftragtem

**Tenant-Settings-Inputs:**
- `tenant.isb_reports_to` (Pflicht: direkt an Leitung, nicht an IT-Leitung)
- `tenant.has_separate_dsb` (Y/N — DSB darf NICHT identisch mit ISB sein nach BSI-Empfehlung + DSGVO Art. 38)
- `tenant.has_isms_committee` (Y/N)
- `tenant.committee_members[]` (Rolle, Name, Email)
- `tenant.escalation_path` (Strukturierter Eskalations-Flow)

---

### 2.2 Schicht ORP (Organisation und Personal)

#### 2.2.1 Organisationsrichtlinie (allg. Sicherheitsregelung Mitarbeiter)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ORP.1.A1` (Festlegung Verantwortlichkeiten), `ORP.1.A2` (Aufgaben & Zuständigkeiten Mitarbeiter), `ORP.1.A3` (Pflichten der Mitarbeiter) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 5.4 (Management responsibilities), 6.2 (Terms and conditions) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Allgemeine Verhaltensregeln am Arbeitsplatz (Clean Desk, Bildschirmsperre)
2. Vertretungsregelungen (`ORP.1.A4`)
3. Umgang mit Informationen unterschiedlicher Vertraulichkeitsstufen
4. Meldepflichten (Sicherheitsvorfälle → ISB)
5. Sanktionen bei Verstößen
6. Verfahren für Besuchermanagement (Cross-Ref zu `INF.1.A5`)

**Tenant-Settings-Inputs:**
- `tenant.classification_scheme` (z. B. öffentlich / intern / vertraulich / streng vertraulich oder VS-NfD/VS-V/Geheim/Streng-Geheim für Behörden)
- `tenant.has_clean_desk_policy` (Default Y)
- `tenant.visitor_management_required` (Y/N)

---

#### 2.2.2 Personalrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ORP.2.A1` (Geregelte Einstellung), `ORP.2.A2` (Geregelte Aufgaben/Zuständigkeiten), `ORP.2.A3` (Geregeltes Verfahren beim Weggang), `ORP.2.A4` (Vertretungsregelungen), `ORP.2.A5` (Vorbeugung gegen Personalausfall) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `ORP.2.A14` (Aufgabenwechsel), `ORP.2.A15` (Qualifikation Vertretung) |
| **ISO-27001-Mapping** | Annex A 6.1 (Screening), 6.2 (Terms), 6.5 (Termination), 6.6 (NDA) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Onboarding-Prozess (Verpflichtung auf Datenschutz/Geheimhaltung, Einweisung Sicherheitsleitlinie)
2. Hintergrundprüfung (für sicherheitsrelevante Positionen, ggf. Sicherheitsüberprüfungsgesetz SÜG)
3. Schulungspflicht beim Eintritt (siehe ORP.3)
4. Aufgaben- und Rollenwechsel innerhalb der Institution
5. Offboarding-Prozess (Rückgabe Assets, Sperrung Zugänge, Exit-Interview, Geheimhaltung über Beendigung hinaus)
6. Regelung für externe Mitarbeitende / Praktikanten / Werkstudenten
7. Vertretungsregelungen (Wer vertritt wen?)

**Tenant-Settings-Inputs:**
- `tenant.has_background_checks` (Y/N — bei Behörden / KRITIS oft Pflicht)
- `tenant.security_clearance_levels[]` (Ü1/Ü2/Ü3 nach SÜG, falls relevant)
- `tenant.uses_external_staff` (Y/N)
- `tenant.offboarding_grace_period_days` (Default 30)
- `tenant.nda_template_version`

---

#### 2.2.3 Awareness- und Schulungsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ORP.3.A1` (Schulungsbedarfsermittlung), `ORP.3.A2` (Etablierung Schulungs- und Sensibilisierungsprogramm), `ORP.3.A3` (Sensibilisierung der Leitungsebene), `ORP.3.A4` (Konzeption Awareness) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `ORP.3.A5` (Schulung des Personals), `ORP.3.A6` (Schulung Administratoren), `ORP.3.A7` (Spezielle Schulungen sensible Bereiche) |
| **ISO-27001-Mapping** | Annex A 6.3 (Awareness, education, training), Clause 7.2 (Competence), 7.3 (Awareness) |
| **Review-Zyklus** | Jährlich (Schulungsplan), Inhalte alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Schulungsbedarfsermittlung (zielgruppenspezifisch: Anwender / Admin / Führungskraft / DSB / ISB)
2. Awareness-Maßnahmen (Phishing-Tests, Plakate, Newsletter, E-Learning)
3. Erstschulung beim Eintritt + Wiederholungsschulung jährlich (mindestens)
4. Spezialschulungen für Admins (Patch-Mgmt, Härtung, Logging)
5. Schulungserfolgskontrolle (Tests, Quiz, Auswertung)
6. Schulungsdokumentation (Teilnehmer, Datum, Inhalt)
7. Schulungs-Roadmap mit Themen, Frequenz, Verantwortlichen

**Tenant-Settings-Inputs:**
- `tenant.training_frequency_months` (Default 12)
- `tenant.has_phishing_simulation` (Y/N)
- `tenant.training_lms_used` (Freitext / Dropdown)
- `tenant.target_groups[]` (Anwender, Admin, Führung, neue MA, externe MA)

---

#### 2.2.4 Identitäts- und Berechtigungsmanagement-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `ORP.4.A1` (Regelung für Einrichtung/Löschung Benutzerkennungen), `ORP.4.A2` (Einrichtung/Löschung von Berechtigungen), `ORP.4.A3` (Dokumentation), `ORP.4.A4` (Überprüfung Zugriffsrechte), `ORP.4.A5` (Vergabe Zutrittsberechtigung), `ORP.4.A8` (Regelung des Passwortgebrauchs), `ORP.4.A9` (Identifikation und Authentisierung), `ORP.4.A22` (Mehr-Faktor-Authentisierung) |
| **Pflicht-Niveau** | Basis (A1–A9) + Standard (A22) |
| **ISO-27001-Mapping** | Annex A 5.15–5.18 (Access control), 8.2 (Privileged access), 8.3 (Information access restriction), 8.5 (Secure authentication) |
| **Review-Zyklus** | Jährlich (Berechtigungsreview), Konzept alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Antrags-, Genehmigungs- und Rücknahmeprozess für Accounts und Berechtigungen
2. Rollen-/Rechte-Konzept (Need-to-know, Least Privilege, Funktionstrennung)
3. Passwort-Policy (Länge, Komplexität, Wechselregel — BSI-Empfehlung seit 2020: KEIN regelmäßiger Passwort-Wechsel mehr ohne Anlass!)
4. Mehr-Faktor-Authentisierung (Pflicht bei hohem Schutzbedarf, Admin-Accounts, externen Zugängen)
5. Privileged Access Management (Trennung administrativer und persönlicher Accounts)
6. Account-Lifecycle (Joiner/Mover/Leaver)
7. Re-Zertifizierung der Berechtigungen (mindestens jährlich, bei hohem Schutzbedarf häufiger)
8. Notfall-/Service-Accounts (geregelt, dokumentiert, überwacht)
9. Single-Sign-On / Federation (falls verwendet)

**Tenant-Settings-Inputs:**
- `tenant.password_policy.min_length` (BSI-Empfehlung: ≥8, bei hohem Schutzbedarf ≥12, bei sehr hoch ≥20)
- `tenant.password_policy.complexity_required` (Y/N)
- `tenant.password_policy.rotation_days` (BSI 2020+: 0 = kein Wechsel ohne Anlass; alte Pflicht 90 Tage entfällt!)
- `tenant.mfa_required_for[]` (Admins, Externe, Privileged, Alle)
- `tenant.recertification_cycle_months` (Default 12)
- `tenant.has_pam_solution` (Y/N)

---

### 2.3 Schicht CON (Konzepte und Vorgehensweisen)

#### 2.3.1 Kryptokonzept

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.1.A1` (Auswahl geeigneter kryptographischer Verfahren), `CON.1.A2` (Datensicherung), `CON.1.A3` (Verschlüsselung Kommunikationsverbindungen), `CON.1.A4` (Geeignetes Schlüsselmanagement), `CON.1.A6` (Kryptokonzept), `CON.1.A7` (Sicherheit Krypto-Module) |
| **Pflicht-Niveau** | Basis (A1, A2, A3, A4, A6) |
| **ISO-27001-Mapping** | Annex A 8.24 (Use of cryptography) |
| **BSI-Referenz** | TR-02102-1/-2/-3/-4 (Kryptographische Verfahren: Empfehlungen und Schlüssellängen) |
| **Review-Zyklus** | Alle 2 Jahre + bei jedem TR-02102-Update |

**Pflicht-Inhaltsabschnitte:**
1. Klassifikation der zu schützenden Daten (Vertraulichkeit, Integrität)
2. Anwendungsfälle (Transport: TLS, E-Mail-S/MIME oder PGP; Ruhe: Disk-/File-Encryption; Backup; mobile Datenträger; Datenbanken)
3. Erlaubte Algorithmen und Mindest-Schlüssellängen (Verweis auf BSI TR-02102 mit aktuellem Stand)
4. Schlüssellebenszyklus (Generierung, Verteilung, Speicherung, Sperrung, Vernichtung, Recovery, Escrow)
5. PKI-Architektur (CA-Hierarchie, Certificate Authority, RA, OCSP/CRL)
6. Verantwortlichkeiten (Crypto-Officer, Backup-Owner)
7. Notfallregelung (Schlüsselverlust, Kompromittierung)
8. Cross-Border-Aspekte (US-Cloud, Krypto-Export)

**Tenant-Settings-Inputs:**
- `tenant.crypto_min_tls_version` (Default 1.2, ab 2027 1.3)
- `tenant.crypto_uses_pki` (Y/N)
- `tenant.crypto_pki_provider` (eigene CA / extern / mixed)
- `tenant.crypto_key_escrow` (Y/N — relevant für Behörden)
- `tenant.crypto_quantum_safe_required` (Y/N — für Daten mit Schutzbedarf >10y)

---

#### 2.3.2 Datenschutz-Richtlinie (BSI x DSGVO/BDSG)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.2.A1` (Umsetzung Standard-Datenschutzmodell), `CON.2.A2` (Sensibilisierung) |
| **Pflicht-Niveau** | Basis |
| **Hinweis** | `CON.2` referenziert das **Standard-Datenschutzmodell (SDM) V3.0** der DSK (Datenschutzkonferenz) als zentrale Methodik. BSI selbst gibt KEINE eigene DSGVO-Implementierungsmethodik — verweist auf DSK/SDM und BfDI. |
| **ISO-27001-Mapping** | Annex A 5.34 (Privacy and PII) |
| **ISO-27701-Mapping** | Voller PIMS — separater Wizard (siehe Memory: ISO-27701-Wizard bereits vorhanden) |
| **Review-Zyklus** | Jährlich (DSGVO-Aktualisierung) |

**Empfehlung:** Bei Tenants mit DPO-Modul aktiv → `CON.2`-Richtlinie als **Cross-Reference** zu bestehender Datenschutzleitlinie aus DPO-Wizard rendern, NICHT doppelt erzeugen. Wizard prüft `tenant.has_active_dpo_wizard` und zeigt Warnung bei Duplikat.

**Pflicht-Inhaltsabschnitte (falls eigenständig):**
1. Verantwortlichkeit (Verantwortlicher, Auftragsverarbeiter, DSB)
2. Verzeichnis von Verarbeitungstätigkeiten (Verweis auf VVT-Modul)
3. Rechtsgrundlagen (DSGVO Art. 6, ggf. Art. 9)
4. Betroffenenrechte (Verweis auf DSR-Modul / DSAR)
5. DSFA-Pflicht (Verweis auf DPIA-Modul)
6. Meldepflicht bei Verletzungen (72h, Art. 33/34 — Verweis auf DataBreach-Workflow)
7. Auftragsverarbeitung (AVV nach Art. 28)
8. Drittlandtransfers (Art. 44–49, SCC, BCR)
9. Standard-Datenschutzmodell-Schutzziele (Datensparsamkeit, Transparenz, Intervenierbarkeit etc.)

**Tenant-Settings-Inputs:**
- `tenant.has_active_dpo_wizard` (computed, blockiert Doppelerzeugung)
- `tenant.processes_personal_data` (Y/N — falls N: nur Cross-Ref auf "nicht anwendbar")
- `tenant.has_dpo` (Y/N — Pflicht ab 20 MA mit personenbezogener DV oder Kerntätigkeit)

---

#### 2.3.3 Datensicherungskonzept

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.3.A1` (Erhebung Einflussfaktoren), `CON.3.A2` (Festlegung Verfahren), `CON.3.A3` (Datensicherungskonzept), `CON.3.A4` (Schutz der Datensicherung), `CON.3.A5` (Regelmäßige Datensicherung), `CON.3.A6` (Datensicherung trotz Vermeidung gemeinsam genutzter Anteile) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `CON.3.A8` (Eignungsprüfung Backup-Verfahren), `CON.3.A9` (Voraussetzungen für Online-Datensicherung), `CON.3.A10` (Verpflichtung Mitarbeiter zur Datensicherung) |
| **Erhöhte-Schutzbedarfs-Anforderungen** | `CON.3.A11`–`A15` (z. B. Auslagerung, Test der Wiederherstellung) |
| **ISO-27001-Mapping** | Annex A 8.13 (Information backup) |
| **Review-Zyklus** | Jährlich, bei jeder Backup-Architektur-Änderung sofort |

**Pflicht-Inhaltsabschnitte:**
1. Klassifikation Daten (RPO/RTO je Datenklasse)
2. Backup-Strategie (3-2-1: 3 Kopien, 2 Medien, 1 offsite — BSI-Empfehlung)
3. Backup-Verfahren (Voll/Inkrementell/Differenziell, Zyklen)
4. Aufbewahrungsfristen (gesetzlich + geschäftlich)
5. Schutz der Backups (Verschlüsselung, Zugriffsschutz, Air-Gap, Immutability)
6. Wiederherstellungstest (mindestens jährlich; bei hohem Schutzbedarf halbjährlich)
7. Verantwortliche (Backup-Owner pro System)
8. Spezialfälle: Cloud-Backup (Cross-Ref `OPS.2.2`), mobile Geräte, Datenbanken
9. Dokumentationspflicht (Backup-Logs, Restore-Tests, Abweichungen)

**Tenant-Settings-Inputs:**
- `tenant.backup_strategy` (3-2-1 / 3-2-1-1-0 / Custom)
- `tenant.backup_offsite_required` (Default Y)
- `tenant.backup_immutability_required` (bei "hoch": Y)
- `tenant.recovery_test_frequency_months` (Default 12)
- `tenant.uses_cloud_backup` (Y/N — triggert OPS.2.2)

---

#### 2.3.4 Lösch- und Vernichtungsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.6.A1` (Regelung für Löschen und Vernichten), `CON.6.A2` (Ordnungsgemäßes Löschen und Vernichten von schützenswerten Betriebsmitteln und Informationen), `CON.6.A3` (Löschen und Vernichten von besonders schutzbedürftigen Betriebsmitteln und Informationen) |
| **Pflicht-Niveau** | Basis (A1, A2) + Standard (A3) |
| **DIN-Referenz** | DIN 66399 (Sicherheitsstufen P-1 bis P-7 für Papier, T-1 bis T-7 für magnetische Datenträger, E-1 bis E-7 für elektronische Datenträger, etc.) |
| **ISO-27001-Mapping** | Annex A 7.10 (Storage media), 7.14 (Secure disposal/re-use of equipment), 8.10 (Information deletion) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Klassifikation Datenträger (Sicherheitsstufen DIN 66399)
2. Verfahren je Klasse (Überschreiben, Degaussing, physische Vernichtung)
3. Verantwortlichkeiten (Asset-Owner, IT-Betrieb, Dienstleister)
4. Nachweispflichten (Vernichtungszertifikate, Inventarlisten)
5. Cloud-Daten (Anbieter-Lösch-Bestätigung, Crypto-Erase)
6. Mobile Geräte (BYOD vs. Firmen-Geräte)
7. Aufbewahrungsfristen vor Löschung (steuerrechtlich, handelsrechtlich, DSGVO Art. 17)
8. Notfall-Vernichtung (Verlust, Diebstahl)
9. Auswahl externer Dienstleister (Zertifizierung nach DIN 66399, Auditrechte)

**Tenant-Settings-Inputs:**
- `tenant.uses_external_disposal_provider` (Y/N)
- `tenant.disposal_provider_din66399_certified` (Y/N — Pflicht bei Y)
- `tenant.required_security_levels[]` (P-3 / P-4 / P-5 / P-7 für sehr hoch)

---

#### 2.3.5 Richtlinie für Auslandsreisen / Mobiles Arbeiten

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.7.A1` (Sicherheitsrichtlinie zu Auslandsreisen), `CON.7.A2` (Sensibilisierung der Mitarbeitenden), `CON.7.A3` (Identifikation von Vorschriften), `CON.7.A4` (Verschlüsselung tragbarer IT-Systeme), `CON.7.A5` (Sichere Nutzung öffentlicher WLANs), `CON.7.A6` (Schutz vor Blicken/Lauschangriffen) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 6.7 (Remote working), 7.9 (Security of assets off-premises), 8.1 (User endpoint devices) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Geltungsbereich (Dienstreise national / EU / Drittland)
2. Erlaubte Geräte (Travel-Notebook, Travel-Phone, kein BYOD)
3. Datenklassifikation für Reise (was darf mit, was nicht)
4. Verschlüsselungspflicht (Festplatte, externe Datenträger, Mobile Devices)
5. Verbindungssicherheit (VPN-Pflicht, kein Public-WiFi ohne VPN)
6. Verhalten bei Grenzkontrollen (insbes. USA/CN/RU — Geräte abgeben? Gesonderte Reise-Geräte?)
7. Verhalten bei Verlust / Diebstahl (sofortige Meldung ISB, Remote-Wipe-Pflicht)
8. Sichtschutz / Privacy-Filter
9. Verbindlichkeit Sensibilisierung vor jeder Auslandsreise in Hochrisikoländer
10. Spezifische Länderregelungen (Verweis auf BfV-Reisesicherheits-Hinweise)

**Tenant-Settings-Inputs:**
- `tenant.allows_international_travel` (Y/N)
- `tenant.high_risk_countries[]` (Default: BfV-Liste — CN, RU, IR, KP, ggf. US bei sehr hoch)
- `tenant.uses_travel_devices` (Y/N)
- `tenant.vpn_mandatory_remote` (Default Y)

---

#### 2.3.6 Software-Entwicklungsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.8.A1` (Auswahl Vorgehensmodells), `CON.8.A2` (Auswahl Entwicklungsumgebung), `CON.8.A3` (Auswahl vertrauenswürdiger Werkzeuge), `CON.8.A4` (Schulung Projektbeteiligter), `CON.8.A5` (Sichere Systementwicklung), `CON.8.A6` (Versionsverwaltung Quellcode) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `CON.8.A7` (Erstellung Sicherheitsanforderungen), `CON.8.A8` (Geeignete Steuerung Softwareentwicklung), `CON.8.A9` (Geregelte Übernahme), `CON.8.A10` (Versionsverwaltung), `CON.8.A11` (Änderungsmanagement), `CON.8.A12` (Härtung Plattformen) |
| **Hoch-Schutzbedarfs** | `CON.8.A19`–`A22` (Threat-Modeling, SAST, DAST, Fuzzing) |
| **ISO-27001-Mapping** | Annex A 8.25–8.31 (Secure development lifecycle), 8.28 (Secure coding) |
| **OWASP-Bezug** | OWASP ASVS, OWASP SAMM (BSI verweist nicht direkt, aber kompatibel) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Geltungsbereich (Eigenentwicklung / Anpassung / Open-Source / Auftragsentwicklung)
2. Vorgehensmodell (Wasserfall / Agile / DevSecOps)
3. Sicherheitsanforderungen je Phase (Konzept, Design, Implementation, Test, Betrieb, Außerbetriebnahme)
4. Threat Modeling (bei hohem Schutzbedarf Pflicht)
5. Secure Coding Guidelines (sprachspezifisch — z. B. CERT-C, OWASP-Cheatsheet)
6. Code-Review-Pflicht (4-Augen-Prinzip)
7. Test-Strategie (Unit, Integration, SAST, DAST, Pentest)
8. Release-Management (Freigabeprozess, siehe `OPS.1.1.6`)
9. Source-Code-Schutz (Repository-Zugriff, Secrets-Mgmt, kein Hardcoding)
10. Lieferkette (SBOM, Dependency-Scanning, Lizenz-Compliance)
11. Auftragsentwickler (vertragliche Anforderungen, Auditrechte)

**Tenant-Settings-Inputs:**
- `tenant.does_software_development` (Y/N — falls N: gesamte Richtlinie überspringen)
- `tenant.development_methodology` (Wasserfall / Scrum / Kanban / Mixed)
- `tenant.uses_external_developers` (Y/N)
- `tenant.sast_tool_used`, `tenant.dast_tool_used` (Freitext / Dropdown)
- `tenant.has_pentest_program` (Y/N)

---

#### 2.3.7 Richtlinie zum Informationsaustausch

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.9.A1` (Festlegung zulässiger Empfänger), `CON.9.A2` (Vereinbarung über Informationsaustausch), `CON.9.A3` (Unterweisung Mitarbeiter) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 5.14 (Information transfer) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Klassifikation der Informationen
2. Erlaubte Übertragungswege je Klasse (E-Mail unverschlüsselt / S/MIME / PGP / verschlüsselter Cloud-Share / Kurier / Boten)
3. Empfängerprüfung (insb. bei Externen)
4. NDA-Pflichten vor Austausch
5. Spezifika für Behörden: VS-NfD-Versand, Krypto-Hardware
6. Versand-Logs
7. Eingangsbearbeitung (Plausibilität, Signaturprüfung)

**Tenant-Settings-Inputs:**
- `tenant.uses_smime` (Y/N)
- `tenant.uses_pgp` (Y/N)
- `tenant.handles_classified_data` (Y/N — VS-NfD)

---

#### 2.3.8 Webanwendungs-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `CON.10.A1` (Authentisierung), `CON.10.A2` (Zugriffskontrolle), `CON.10.A3` (Sichere Konfiguration), `CON.10.A4` (Schutz vertraulicher Daten), `CON.10.A5` (Protokollierung sicherheitsrelevanter Ereignisse) |
| **Pflicht-Niveau** | Basis |
| **Hinweis** | Anwendung wenn Tenant Webanwendungen entwickelt ODER betreibt — gilt zusätzlich zu CON.8 |
| **ISO-27001-Mapping** | Annex A 8.26 (Application security requirements) |
| **OWASP-Bezug** | OWASP Top 10, ASVS L2/L3 |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Authentisierung (Session-Mgmt, MFA, Brute-Force-Schutz)
2. Eingabevalidierung (Input-Sanitization, Output-Encoding, CSRF, XSS, SQLi)
3. Zugriffskontrolle (Function-Level, Object-Level)
4. Verschlüsselung (TLS, Hashing für Passwörter)
5. Logging (Auth-Events, Privilege-Eskalation, Anomalien)
6. Härtung (Security-Header, kein Stack-Trace im Produktivbetrieb)
7. Vulnerability-Mgmt (CVE-Monitoring, Patch-Frequenz)

---

### 2.4 Schicht OPS (Betrieb)

#### 2.4.1 IT-Administrations-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.1.2.A1` (Regelung Verantwortlichkeiten), `OPS.1.1.2.A4` (Vertretungsregelung), `OPS.1.1.2.A5` (Nachvollziehbarkeit Aktivitäten), `OPS.1.1.2.A6` (Schutz administrativer Tätigkeiten), `OPS.1.1.2.A21` (PAM) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `OPS.1.1.2.A11` (Aufgabenwechsel), `OPS.1.1.2.A14` (Fortbildung Admins), `OPS.1.1.2.A15` (Verzicht Standard-Accounts) |
| **ISO-27001-Mapping** | Annex A 8.2 (Privileged access), 8.18 (Use of privileged utilities) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Trennung administrativer / persönlicher Accounts
2. Privileged-Access-Workflows (Antrag, Genehmigung, Auditierung)
3. Jump-Hosts / Bastion-Hosts für admin. Zugriffe
4. Out-of-band-Mgmt (separate Netze)
5. 4-Augen-Prinzip bei kritischen Operationen (z. B. Domain-Admin-Aktionen)
6. Logging aller administrativen Aktionen + Auswertung
7. Notfall-Admin-Zugänge (Sealed Envelope, Break-Glass)
8. Outsourcing administrativer Tätigkeiten (Cross-Ref `OPS.2.3`)

---

#### 2.4.2 Patch- und Änderungsmanagement-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.1.3.A1` (Konzept), `OPS.1.1.3.A2` (Festlegung Verantwortlichkeiten), `OPS.1.1.3.A3` (Konfigurations-Mgmt), `OPS.1.1.3.A4` (Inventarisierung), `OPS.1.1.3.A5` (Synchronisierung), `OPS.1.1.3.A6` (Einsatz Schutzmaßnahmen) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `OPS.1.1.3.A8`–`A14` (Test-Verfahren, Eskalation, Backout) |
| **ISO-27001-Mapping** | Annex A 8.8 (Vulnerability mgmt), 8.32 (Change management), 8.9 (Configuration mgmt) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Patch-SLAs nach Kritikalität (CVSS / BSI-Warnstufen)
   - **BSI-Empfehlung**: Critical innerhalb 24-72h, High 7d, Medium 30d, Low 90d
2. Inventar (CMDB-Verbindung)
3. Test-Umgebung vor Produktiv-Rollout
4. Change-Advisory-Board (CAB) — Standard-, Normal-, Notfall-Changes
5. Backout-Plan
6. Notfall-Patches (Out-of-cycle)
7. EOL-Mgmt (was tun bei nicht mehr gepatchten Systemen?)
8. Reporting an ISB / Leitung

**Tenant-Settings-Inputs:**
- `tenant.patch_sla_critical_hours` (Default 72)
- `tenant.patch_sla_high_days` (Default 7)
- `tenant.has_test_environment` (Y/N)
- `tenant.has_cab` (Y/N)

---

#### 2.4.3 Schutz vor Schadprogrammen

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.1.4.A1` (Schadprogramm-Konzept), `OPS.1.1.4.A2` (Auswahl Schutzmaßnahmen), `OPS.1.1.4.A3` (Auswahl Schutzprodukt), `OPS.1.1.4.A4` (Aktualisierung), `OPS.1.1.4.A5` (Nutzung systemspezifischer Mechanismen), `OPS.1.1.4.A6` (Sensibilisierung), `OPS.1.1.4.A7` (Meldungen Schadprogramm-Vorfälle) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 8.7 (Protection against malware) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Schutz auf allen Endpoints + Servern + Mail-Gateway
2. Update-Frequenz Signaturen (mindestens täglich)
3. Verhalten Mitarbeiter bei Verdacht (nicht ausschalten, nicht trennen, ISB melden)
4. Zentrales Reporting / SIEM-Anbindung
5. Restoration nach Befall
6. Spezialfälle (Air-gapped Systeme, ICS/OT — Cross-Ref `IND.*`)

---

#### 2.4.4 Protokollierungsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.1.5.A1` (Erstellung Protokoll-Konzept), `OPS.1.1.5.A2` (Konfiguration Protokollierung), `OPS.1.1.5.A3` (Aufbewahrung), `OPS.1.1.5.A4` (Datenschutz) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `OPS.1.1.5.A5`–`A10` (Auswertung, Alarmierung, Archivierung) |
| **ISO-27001-Mapping** | Annex A 8.15 (Logging), 8.16 (Monitoring activities), 8.17 (Clock synchronization) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Was wird protokolliert (Auth, Privilegierung, Zugriff schützenswerte Daten, Sicherheitsverstöße, Admin-Aktionen)
2. Protokollformat (BSI empfiehlt strukturiert: CEF/Syslog/JSON)
3. Zentrale Sammelstelle (SIEM)
4. Aufbewahrungsfristen (gesetzlich + operativ; mindestens 30d, bei KRITIS 12 Monate)
5. Schutz der Protokolle (Integrität, Unveränderbarkeit, Zugriffsschutz)
6. Auswertung (manuell + automatisiert, Use Cases)
7. Datenschutz-Konformität (Mitbestimmung Betriebsrat, DSFA)
8. Alarmierung (Schwellwerte, Eskalation)
9. Zeitsynchronisation (NTP) — Pflicht für forensische Auswertbarkeit

---

#### 2.4.5 Software-Test- und -Freigabe-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.1.6.A1` (Erstellung Konzept Software-Tests), `OPS.1.1.6.A2` (Software-Tests bei funktionalen Änderungen), `OPS.1.1.6.A3` (Bewertung), `OPS.1.1.6.A4` (Freigabeverfahren) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 8.29 (Security testing), 8.31 (Separation of dev/test/prod) |

**Pflicht-Inhaltsabschnitte:**
1. Test-Pflicht vor Produktivnahme
2. Test-Umgebung (separiert von Produktiv)
3. Test-Daten (anonymisiert / synthetisch — DSGVO-konform)
4. Freigabe-Workflow (Fachverantwortliche + ISB bei sicherheitsrelevanten Komponenten)
5. Dokumentation (Testfälle, Ergebnisse, Restrisiken)

---

#### 2.4.6 Telearbeits- / Home-Office-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.2.4.A1` (Regelungen für Telearbeit), `OPS.1.2.4.A2` (Sicherheitstechnische Anforderungen Telearbeitsplatz), `OPS.1.2.4.A3` (Sensibilisierung) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 6.7 (Remote working), 7.1 (Physical security perimeters für Heimarbeitsplatz) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Berechtigte Personenkreise / Tätigkeiten
2. Anforderungen an häuslichen Arbeitsplatz (abschließbarer Raum, Sichtschutz, Familienangehörige)
3. Erlaubte Geräte (Firmen-Laptop only / BYOD)
4. Verbindungssicherheit (VPN-Pflicht, MFA)
5. Druck- und Aufbewahrungsregelungen
6. Verlust/Diebstahl-Meldewege
7. Datenschutz (insb. Auftragsverarbeitung wenn private Geräte)
8. Kontrollrechte des Arbeitgebers (Mitbestimmung BR)

**Tenant-Settings-Inputs:**
- `tenant.allows_home_office` (Y/N)
- `tenant.allows_byod` (Y/N — bei "hoch": empfohlen N)
- `tenant.has_works_council` (Y/N — Mitbestimmung)

---

#### 2.4.7 Fernwartungs-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.1.2.5.A1` (Planung Einsatz Fernwartung), `OPS.1.2.5.A2` (Sicherheitsmaßnahmen), `OPS.1.2.5.A3` (Authentisierung), `OPS.1.2.5.A4` (Protokollierung) |
| **Pflicht-Niveau** | Basis |
| **Hinweis** | Insbesondere relevant für ICS/OT (Cross-Ref `IND.1`) |
| **ISO-27001-Mapping** | Annex A 5.7 (Threat intel), 8.21 (Network services) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Erlaubte Fernwartungstools (zentrale Liste)
2. Authentisierung (MFA, persönliche Accounts, kein Shared)
3. Session-Aufzeichnung (insb. bei externen Dienstleistern)
4. Genehmigungsprozess pro Session (Just-in-Time)
5. Netzsegmentierung (separate Fernwartungs-Zone)
6. Vertragliche Anforderungen Externe (NDA, SLA, Audit)
7. Notfall-Trennung (Kill-Switch)

---

#### 2.4.8 Lieferantensicherheits- / Outsourcing-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.2.3.A1` (Festlegung Sicherheitsanforderungen), `OPS.2.3.A2` (Sorgfältige Auswahl Outsourcing-Dienstleister), `OPS.2.3.A3` (Vertragsgestaltung), `OPS.2.3.A4` (Vereinbarung Mandantenfähigkeit), `OPS.2.3.A5` (Erstellung Sicherheitskonzept gemeinsam) — analog auch `OPS.2.1` für Anbieterseite |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 5.19–5.23 (Supplier relationships) |
| **DORA-Mapping** | Art. 28–30 (ICT third-party risk) — relevant bei Finanzbranche |
| **Review-Zyklus** | Jährlich + bei jedem neuen Vertrag |

**Pflicht-Inhaltsabschnitte:**
1. Lieferanten-Klassifikation (kritisch / nicht-kritisch nach Datenzugriff + Verfügbarkeit)
2. Onboarding-Prozess (Due Diligence, Bonität, Zertifikate ISO 27001 / C5 / SOC2)
3. Vertragsklauseln-Mindestset (NDA, AVV, SLA, Audit-Rechte, Subunternehmer-Genehmigung, Exit-Klauseln)
4. Laufendes Monitoring (KPIs, Vorfallmeldungen)
5. Audit-Rhythmus (jährlich kritisch, alle 2-3 Jahre nicht-kritisch)
6. Exit- und Reversibilitäts-Strategie (insb. Cloud — Datenrückgabe-Format, Lösch-Bestätigung)
7. Supply-Chain-Mapping (Sub-Sub-Auftragnehmer)
8. Cross-Ref Cloud → `OPS.2.2`

**Tenant-Settings-Inputs:**
- `tenant.has_critical_suppliers` (Y/N)
- `tenant.uses_cloud` (Y/N — triggert OPS.2.2)
- `tenant.is_dora_regulated` (Y/N — strengere Anforderungen)

---

#### 2.4.9 Cloud-Nutzungsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `OPS.2.2.A1` (Erstellung Cloud-Nutzungsstrategie), `OPS.2.2.A2` (Erstellung Sicherheitsrichtlinie für Cloud-Nutzung), `OPS.2.2.A3` (Service-Definition für Cloud-Dienste durch den Anwender), `OPS.2.2.A4` (Festlegung Verantwortungsbereiche), `OPS.2.2.A5` (Planung Übergang) |
| **Pflicht-Niveau** | Basis |
| **Cross-Ref** | BSI C5:2020 / C5:2026, ISO 27017, ISO 27018 |
| **ISO-27001-Mapping** | Annex A 5.23 (Information security for use of cloud services) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Cloud-Strategie (Public / Private / Hybrid / Multi-Cloud)
2. Datenklassifikations-Matrix → Cloud-Eignung
3. Anforderungen an Cloud-Anbieter (C5-Testat-Pflicht? ISO 27001?)
4. Verantwortungsmatrix (Shared-Responsibility-Modell — IaaS / PaaS / SaaS)
5. Datenstandort + Drittlandtransfers (Schrems-II-konform)
6. Verschlüsselung (Customer-Managed Keys vs. Provider-Managed)
7. Identity-Federation
8. Logging / Monitoring (Provider-Logs + eigene)
9. Backup-Verantwortung (Cloud-Provider sichert NICHT im SaaS-Default!)
10. Exit-Strategie

**Tenant-Settings-Inputs:**
- `tenant.cloud_strategy` (Public-First / Cloud-Smart / Cloud-Last / On-Prem-Only)
- `tenant.cloud_geo_restriction` (DE-only / EU-only / EU+adäquate Drittländer / global)
- `tenant.requires_c5_testat` (Y/N — Pflicht bei KRITIS / sehr hoch)

---

### 2.5 Schicht DER (Detektion und Reaktion)

#### 2.5.1 Detektionsrichtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `DER.1.A1` (Erstellung Sicherheitsrichtlinie zur Detektion), `DER.1.A2` (Einhaltung rechtlicher Bedingungen), `DER.1.A3` (Festlegung Verantwortlichkeiten), `DER.1.A4` (Nutzung von Logmeldungen für Detektion) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `DER.1.A5`–`A11` (kontinuierliche Überwachung, Korrelation, Alarmierung) |
| **ISO-27001-Mapping** | Annex A 8.16 (Monitoring activities) |
| **Review-Zyklus** | Jährlich |

**Pflicht-Inhaltsabschnitte:**
1. Detektions-Strategie (SIEM, IDS/IPS, EDR, NDR, UEBA)
2. Use Cases / Detection Rules (z. B. nach MITRE ATT&CK)
3. False-Positive-Mgmt
4. Alarmierungs- und Eskalations-Wege (24/7 vs. Bürozeiten)
5. SOC-Modell (intern / extern / hybrid)
6. Datenschutz (Mitbestimmung BR, DSFA bei UEBA!)
7. Threat Intelligence Feeds (MISP, kommerziell, BSI-Lagebild)

**Tenant-Settings-Inputs:**
- `tenant.has_siem` (Y/N)
- `tenant.has_soc` (intern / extern / kein)
- `tenant.soc_coverage` (24/7 / 8x5 / Bürozeiten)

---

#### 2.5.2 Sicherheitsvorfall-Reaktionsrichtlinie (Incident Response)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `DER.2.1.A1` (Definition Sicherheitsvorfall), `DER.2.1.A2` (Erstellung Richtlinie zur Behandlung), `DER.2.1.A3` (Festlegung Meldewege und Ansprechpartner), `DER.2.1.A4` (Eindämmung), `DER.2.1.A5` (Wiederherstellung), `DER.2.1.A6` (Nachbearbeitung) |
| **Pflicht-Niveau** | Basis |
| **Standard-Anforderungen** | `DER.2.1.A7`–`A14` (Spezialisierte Teams, externe Unterstützung) |
| **ISO-27001-Mapping** | Annex A 5.24–5.28 (Incident management) |
| **Regulatorische Cross-Ref** | NIS2 Art. 23 (24h Frühwarnung, 72h Meldung, 1m Final), DSGVO Art. 33 (72h), DORA Art. 17–23 (ICT-related incidents), KRITIS-Meldepflicht (BSIG §8b) |
| **Review-Zyklus** | Jährlich + nach jedem Major Incident |

**Pflicht-Inhaltsabschnitte:**
1. Definition: Was ist ein Sicherheitsvorfall vs. Störung?
2. Klassifikations-Matrix (Schweregrad: niedrig / mittel / hoch / kritisch)
3. Meldewege (intern: ISB; extern: BSI, Aufsichtsbehörden, Strafverfolgung)
4. Reaktions-Phasen: Erkennen → Bewerten → Eindämmen → Beseitigen → Wiederherstellen → Lessons Learned
5. CSIRT/CERT (intern oder extern, z. B. CERT-Bund)
6. Eskalations-Stufen + Verantwortliche
7. Forensik-Schnittstelle (Cross-Ref `DER.2.2`)
8. Externe Kommunikation (Pressestelle, Kunden, Aufsicht)
9. Übungs-Pflicht (mindestens jährlich Tabletop-Exercise)
10. Spezifische Meldepflichten (NIS2: 24h/72h/1m, DSGVO: 72h, KRITIS: unverzüglich)

**Tenant-Settings-Inputs:**
- `tenant.has_csirt` (intern / extern / kein)
- `tenant.csirt_provider` (Freitext)
- `tenant.is_kritis` (Y/N — BSI-Meldepflicht §8b BSIG)
- `tenant.is_nis2_essential_or_important` (essential / important / out-of-scope)
- `tenant.notification_obligations[]` (BSI, BfDI, BaFin, BNetzA, Branchen-CERT)

---

#### 2.5.3 IT-Forensik-Richtlinie

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `DER.2.2.A1` (Prüfung rechtlicher und regulatorischer Rahmenbedingungen), `DER.2.2.A2` (Erstellung eines Leitfadens für Beweissicherung), `DER.2.2.A3` (Schulung) |
| **Pflicht-Niveau** | Basis |
| **ISO-27001-Mapping** | Annex A 5.28 (Collection of evidence) |
| **Review-Zyklus** | Alle 2 Jahre |

**Pflicht-Inhaltsabschnitte:**
1. Vorbereitungsmaßnahmen (Forensik-Toolkit, geschultes Personal, geeignete Speicherkapazitäten)
2. Beweissicherung (Chain-of-Custody, Hashing, Write-Blocker)
3. Wann eigene Forensik vs. externer Dienstleister
4. Rechtliche Aspekte (Mitbestimmung, Strafanzeige-Entscheidung)
5. Datenschutz bei Forensik (DSGVO Art. 6 Abs. 1 lit. f / Art. 10)
6. Zusammenarbeit mit BSI / LKA / BKA / StA

---

#### 2.5.4 Notfallmanagement-Richtlinie (Brücke zu BSI 200-4)

| Attribut | Wert |
|---|---|
| **Baustein-Referenz** | `DER.4.A1` (Erstellung Leitlinie zum Notfallmanagement), `DER.4.A2` (Festlegung Ziele und Verantwortlichkeiten), `DER.4.A3` (Erstellung Notfallkonzept), `DER.4.A4` (Festlegung Notfallorganisation) |
| **Pflicht-Niveau** | Basis |
| **Mandatierender Standard** | BSI 200-4 (BCM) — final 2023, ersetzt vorherigen 100-4 |
| **ISO-Mapping** | ISO 22301:2019 (BCMS), ISO 27001 Annex A 5.29–5.30 (ICT readiness for business continuity) |
| **Review-Zyklus** | Jährlich + nach jedem aktivierten Notfall |

**Pflicht-Inhaltsabschnitte (BSI 200-4 Reaktiv-/Aufbau-Stufe):**
1. Notfall-Leitlinie (analog zur Sicherheits-Leitlinie, aber für BCM)
2. Notfall-Organisation: Krisenstab, Notfallbeauftragter, Wiederanlaufteams
3. BIA (Business Impact Analyse) — Verweis auf BIA-Modul
4. Notfallvorsorge (Redundanzen, Standorte, Dienstleister)
5. Notfallpläne (Geschäftsfortführung + Wiederanlauf)
6. Krisenkommunikation (intern + extern + Behörden)
7. Übungs- und Test-Konzept (Tabletop, Simulation, Echtbetrieb-Test — jährlich)
8. Aktivierungs-Trigger
9. Schnittstelle zu Incident Response (DER.2.1)

**Tenant-Settings-Inputs:**
- `tenant.has_bcm_team` (Y/N)
- `tenant.bcm_methodology` (BSI 200-4 / ISO 22301 / hybrid)
- `tenant.bia_completed` (computed via Modul)
- `tenant.crisis_team_members[]`

> **Cross-Ref:** Wizard prüft `tenant.has_active_bcm_module` und nutzt vorhandenen Krisenstab/BIA-Daten als Auto-Fill statt Doppelerfassung.

---

### 2.6 Optionale weitere Schichten (nicht im Kern-Pflicht-Set, aber häufig relevant)

| Schicht | Relevanz | Wann anbieten? |
|---|---|---|
| **APP** (Anwendungen) | Modular: Office, Webserver, Datenbank, Verzeichnisdienst, Groupware, Archiv | Nach Strukturanalyse, abhängig von Tenant.applications_used[] |
| **SYS** (IT-Systeme) | Server-OS, Client-OS, Mobile, Virtualisierung, Container | Nach Strukturanalyse |
| **NET** (Netze) | Netzwerk-Architektur, Firewalls, WLAN, VPN | Wenn Tenant Netze betreibt (fast immer) |
| **INF** (Infrastruktur) | Gebäude, Rechenzentrum, Verkabelung | Wenn eigene RZ / nicht reines Cloud-Modell |
| **IND** (Industrielle IT) | ICS / OT / SCADA | Nur wenn Tenant produzierendes Gewerbe / KRITIS-Sektoren Energie/Wasser/Verkehr |

**Wizard-Empfehlung:** Schichten APP/SYS/NET/INF/IND **nicht** im Pflicht-Richtlinien-Wizard generieren — diese sind objektspezifisch und werden besser durch den IT-Grundschutz-Check je Zielobjekt abgedeckt. Wizard erzeugt nur ISMS + ORP + CON + OPS + DER als "Top-Level-Policies".

---

## 3. Verbindung zu BSI 200-1 / 200-2 / 200-4

### 3.1 BSI 200-1 — Managementsysteme für Informationssicherheit (ISMS)

Mandatiert als Meta-Standard die **Existenz** der ISMS-Leitlinie und das ISMS-Konzept als Ganzes. Vergleichbar mit ISO 27001 Clause 4–10.

**Pflicht-Dokumente aus 200-1:**
- IT-Sicherheitsleitlinie (Kap. 6.2)
- Sicherheitsorganisation (Kap. 7)
- Sicherheitsstrategie / -konzept (Kap. 6, 8)
- Kontinuierlicher Verbesserungsprozess (Kap. 9)

### 3.2 BSI 200-2 — IT-Grundschutz-Methodik

Mandatiert die **konkrete Vorgehensweise** für ISMS-Erstellung, inkl. Strukturanalyse, Schutzbedarfsfeststellung, Modellierung, IT-Grundschutz-Check, Realisierungsplan.

**Vorgehensweisen (entscheidet Tenant im Wizard Schritt 1):**

| Vorgehensweise | Anwendungsfall | Implikation für Wizard |
|---|---|---|
| **Basis-Absicherung** | Einstieg, kleine Org, geringer Schutzbedarf | Nur Basis-Anforderungen pro Baustein, vereinfachte Doku, KEINE Risikoanalyse |
| **Standard-Absicherung** | Vollumfänglich, "normaler" Schutzbedarf | Basis + Standard-Anforderungen, Risikoanalyse bei "hoch"/"sehr hoch" |
| **Kern-Absicherung** | Geschäftskritische Werte ("Kronjuwelen") | Alle Anforderungs-Niveaus + erweiterte Risikoanalyse, fokussierter Scope |

**Wizard-Implikation:** Auswahl von `tenant.bsi_methodology` filtert die zu generierenden Anforderungen pro Richtlinie. Bei "Basis" werden Standard-/Hoch-Sektionen ausgegraut bzw. weggelassen.

### 3.3 BSI 200-3 — Risikoanalyse auf Basis IT-Grundschutz

Mandatiert die ergänzende Risikoanalyse für Zielobjekte mit Schutzbedarf "hoch" / "sehr hoch" oder unter Kern-Absicherung. **KEIN eigenes Pflicht-Dokument im Top-Level-Set**, aber Methodik-Doku für Risikoanalyse-Verfahren (siehe Kap. 4 — Methode-Doku).

### 3.4 BSI 200-4 — Business Continuity Management

Mandatiert das Notfallmanagement-System (BCMS). Nutzt den Bauestein `DER.4` als Anker im Grundschutz-Kompendium, eigentliche Methodik liegt im 200-4. Drei Stufen analog zu 200-2:
- **Reaktiv-BCMS** (Einstieg)
- **Aufbau-BCMS** (vollumfänglich)
- **Standard-BCMS** (zertifizierungsfähig nach ISO 22301)

### 3.5 ISO 27001 Annex A — Cross-Reference-Mapping (Auszug)

| BSI-Baustein | Richtlinie | ISO 27001:2022 Annex A | ISO 27002:2022 |
|---|---|---|---|
| ISMS.1.A4 | Leitlinie | 5.1, Clause 5.2 | 5.1 |
| ORP.1 | Organisation | 5.4 | 5.4 |
| ORP.2 | Personal | 6.1, 6.2, 6.5 | 6.1–6.6 |
| ORP.3 | Awareness | 6.3 | 6.3 |
| ORP.4 | IAM | 5.15–5.18, 8.2, 8.3, 8.5 | 5.15–5.18 |
| CON.1 | Krypto | 8.24 | 8.24 |
| CON.2 | Datenschutz | 5.34 | 5.34 |
| CON.3 | Backup | 8.13 | 8.13 |
| CON.6 | Löschen/Vernichten | 7.10, 7.14, 8.10 | 7.10, 7.14, 8.10 |
| CON.7 | Auslandsreisen | 6.7, 7.9, 8.1 | 6.7 |
| CON.8 | SW-Entwicklung | 8.25–8.31 | 8.25–8.31 |
| CON.9 | Informationsaustausch | 5.14 | 5.14 |
| CON.10 | Webanwendungen | 8.26 | 8.26 |
| OPS.1.1.2 | IT-Admin | 8.2, 8.18 | 8.2, 8.18 |
| OPS.1.1.3 | Patch/Change | 8.8, 8.32, 8.9 | 8.8, 8.32 |
| OPS.1.1.4 | Malware | 8.7 | 8.7 |
| OPS.1.1.5 | Logging | 8.15, 8.16, 8.17 | 8.15–8.17 |
| OPS.1.1.6 | SW-Test/Freigabe | 8.29, 8.31 | 8.29 |
| OPS.1.2.4 | Telearbeit | 6.7 | 6.7 |
| OPS.1.2.5 | Fernwartung | 8.21 | 8.21 |
| OPS.2.2 | Cloud | 5.23 | 5.23 |
| OPS.2.3 | Outsourcing | 5.19–5.23 | 5.19–5.23 |
| DER.1 | Detektion | 8.16 | 8.16 |
| DER.2.1 | Incident Response | 5.24–5.28 | 5.24–5.28 |
| DER.2.2 | Forensik | 5.28 | 5.28 |
| DER.4 | Notfall/BCM | 5.29, 5.30 | 5.29, 5.30 |

**Wizard-Implikation für Dual-Compliance:** Bei `tenant.compliance_frameworks = [ISO27001, BSI]` rendert der Wizard die Richtlinie mit zusätzlichem Mapping-Block am Ende ("Diese Richtlinie erfüllt auch ISO 27001 Annex A …"), und die generierten `Document` records bekommen Tags BEIDE Frameworks plus Linkbar an `Control`-Records aus beiden Mappings.

---

## 4. Schutzbedarfsfeststellung — Methode-Dokument (Sonderfall)

**Wichtig:** Die Schutzbedarfsfeststellung ist KEINE Richtlinie im Sinne einer Verhaltens-/Regelvorschrift, sondern eine **Methoden-Beschreibung**. Wizard sollte diese als separates Dokument-Typ "Methode" erzeugen, nicht als "Richtlinie".

### 4.1 Pflicht-Dokument: Schutzbedarfsfeststellungs-Methodik

| Attribut | Wert |
|---|---|
| **Mandatierender Standard** | BSI 200-2 Kap. 6 |
| **Pflicht-Niveau** | Basis (für alle Vorgehensweisen) |
| **Doku-Typ** | Methode (nicht Richtlinie) |
| **Cross-Ref** | ISO 27005 (Risk Mgmt), ISO 27001 Clause 6.1.2 |

**Pflicht-Inhaltsabschnitte:**
1. Schutzbedarfskategorien (BSI-Standard 3-stufig: normal / hoch / sehr hoch — alternativ tenant-spezifisch 4-stufig)
2. Definition je Stufe pro Schutzziel (Vertraulichkeit, Integrität, Verfügbarkeit, ggf. Authentizität, Verbindlichkeit)
3. Schadensszenarien-Katalog (BSI-Vorlage: Verstöße gegen Gesetze/Verträge, Beeinträchtigung Selbstbestimmungsrecht, körperl. Unversehrtheit, Aufgabenerfüllung, negative Innen-/Außenwirkung, finanzielle Auswirkungen)
4. Vererbungs-Regeln (Maximumprinzip, Verteilungseffekt, Kumulationseffekt — siehe BSI 200-2 Kap. 6.2)
5. Dokumentationsvorlagen für Anwendungen / IT-Systeme / Räume / Kommunikationsverbindungen
6. Abnahme-Verfahren durch Fachverantwortliche

**Tenant-Settings-Inputs:**
- `tenant.protection_levels` (Default 3-stufig BSI-Standard, alternativ 4-stufig)
- `tenant.protection_levels_definitions` (pro Stufe + Schutzziel: Schadenshöhen/-szenarien)
- `tenant.uses_inheritance_max_principle` (Default Y — Maximumprinzip aus BSI 3.6)
- `tenant.uses_distribution_effect` (Y/N — Verteilungseffekt erlaubt)
- `tenant.uses_cumulation_effect` (Y/N — Kumulationseffekt erlaubt)

> **Memory-Cross-Ref:** Bestehende `AssetDependencyService` aus dem Schutzbedarfsvererbungs-Projekt (siehe MEMORY: `project_schutzbedarfsvererbung_deferred.md`) implementiert das Maximumprinzip bereits. Wizard sollte hier nur die Methodik **dokumentieren** und auf den existierenden Service verweisen.

---

## 5. Tenant-Settings-Inputs — Wizard-Schritt-Gruppierung

### Schritt 1: Vorgehensweise und Scope (Pflicht zuerst)

| Feld | Typ | Default | Validierung |
|---|---|---|---|
| `bsi_methodology` | Radio | Standard | Pflicht |
| `scope_description` | Textarea (max 2000) | — | Pflicht, mind. 50 Zeichen |
| `scope_geographic` | Multi-Select (Standorte) | Tenant-Hauptsitz | Pflicht ≥1 |
| `scope_organizational` | Multi-Select (Abteilungen) | Alle | Pflicht ≥1 |
| `scope_excluded` | Textarea | leer | Optional, aber Audit-relevant |

### Schritt 2: Branche und Regulatorik

| Feld | Typ | Default | Implikationen |
|---|---|---|---|
| `is_authority` | Y/N | N | Y → BSIG-Pflicht, andere Sprache (Behörden-Diktion) |
| `is_kritis` | Y/N | N | Y → BSIG §8a Audit alle 2 Jahre, §8b Meldepflicht |
| `kritis_sector` | Dropdown | — | Energie/Wasser/Ernährung/IKT/Gesundheit/Finanzen/Transport/Siedlungsabfall (BSI-KRITIS-VO) |
| `is_nis2_in_scope` | Y/N | N | Y → eigene Richtlinien-Anpassungen |
| `nis2_classification` | Radio | important | essential / important |
| `is_dora_regulated` | Y/N | N | Y → strengere Outsourcing/ICT-Risk-Anforderungen |

### Schritt 3: Sprache und Schutzbedarfsschema

| Feld | Typ | Default |
|---|---|---|
| `language_preference` | Multi-Select | DE-only (BSI-Default) — DE+EN bei intl. Konzernen |
| `protection_scheme` | Radio | 3-stufig BSI-Standard |
| `classification_scheme` | Radio | öffentlich/intern/vertraulich/streng vertraulich (Wirtschaft) ODER VS-NfD/VS-V/Geheim/Streng-Geheim (Behörden VS-A) |

### Schritt 4: Notfallorganisation

| Feld | Typ | Default | Cross-Ref |
|---|---|---|---|
| `has_crisis_team` | Y/N | N | Aktiviert DER.4-Templates mit Krisenstab-Sektion |
| `crisis_team_lead` | User-Select | ISB | Pflicht bei Y |
| `bcm_methodology` | Radio | BSI 200-4 Reaktiv-Stufe | Reaktiv / Aufbau / Standard / kein BCM |
| `existing_bcm_module_active` | computed | — | Y → Daten aus BCM-Modul ziehen, kein Doppel-Setup |

### Schritt 5: Module und Cross-References

| Feld | Computed aus | Effekt |
|---|---|---|
| `has_active_dpo_wizard` | DB-Check | Cross-Ref CON.2 statt Generierung |
| `has_active_bcm_module` | DB-Check | Cross-Ref DER.4 statt vollständige Generierung |
| `has_iso27001_active` | tenant.compliance_frameworks | Dual-Compliance-Rendering aktivieren |
| `does_software_development` | Manual Y/N | CON.8 + CON.10 generieren ja/nein |
| `uses_cloud` | Manual Y/N | OPS.2.2 generieren ja/nein |
| `uses_external_disposal` | Manual Y/N | CON.6 angepasst |

### Schritt 6: Approval-Routing

| Feld | Typ | Default |
|---|---|---|
| `isb_approver` | User-Select | Tenant.isb_user |
| `leadership_approver` | User-Select | — Pflicht |
| `requires_4_eyes` | Boolean | true (BSI ISMS.1.A2) |

### Schritt 7: Review und Verbindlichkeit

| Feld | Typ | Default |
|---|---|---|
| `review_cycle_months` | Integer | 24 (BSI Pflicht-Max) |
| `auto_remind_before_days` | Integer | 60 |
| `notification_recipients[]` | Multi-User | ISB, ISMS-Team |

---

## 6. Hierarchy Considerations — Konzern vs. Tochter

### 6.1 Konzernweite vs. tochterspezifische Richtlinien

BSI bietet **kein** explizites Konzernkonzept (im Gegensatz zu ISO 27001 mit "interested parties"). Pragmatische Aufteilung:

| Richtlinie | Konzernebene? | Tochterebene? | Override-Möglichkeit? |
|---|---|---|---|
| Top-Level-Leitlinie (ISMS.1.A4) | **Ja** | Ergänzend "Sicherheitserklärung" | Tochter darf strenger, nicht weicher |
| ORP.1 Organisation | Rahmen | Konkretisierung | Geltungsbereich anpassbar |
| ORP.2 Personal | Wenn HR zentral | Sonst je Tochter | Tarifrechtlich oft tochterspezifisch |
| ORP.3 Awareness | Konzernrahmen + lokale Sprache | — | — |
| ORP.4 IAM | Wenn IDP/AD zentral | — | — |
| CON.1 Krypto | **Konzernweit** (Algorithmen einheitlich) | — | Strenger erlaubt |
| CON.2 Datenschutz | Pro Verantwortlichen — bei Konzernverbund häufig je Tochter | — | DSGVO Art. 4 Nr. 7 |
| CON.3 Backup | Architekturentscheidung Konzern | Lokale RTO/RPO | — |
| CON.6 Löschen | Konzernrahmen | Lokale Dienstleister | — |
| CON.7 Reisen | Konzernweit | — | — |
| CON.8 SW-Entwicklung | Wenn zentrale Entwicklung | Sonst je Tochter | — |
| OPS.1.1.* (Admin/Patch/Logging) | Wenn zentraler IT-Betrieb | Sonst je Tochter | — |
| OPS.2.2/2.3 (Cloud/Outsourcing) | **Konzernweit** (Vertragshoheit) | Lokale Anwendung | — |
| DER.* (Incident/Forensik/BCM) | Konzernrahmen + Krisenstab | Lokale Eskalation | — |

### 6.2 BSI-Spezifika für Konzerne

- **BSIG §8a Audit-Rhythmus:** Bei KRITIS alle 2 Jahre Pflicht-Audit. Im Konzern muss jede KRITIS-Tochter **einzeln** prüfen — Konzernaudit nur ergänzend zulässig.
- **Behörden-Konzerne:** BSI-Compliance bezieht sich auf **die** Behörde. Geschäftsbereiche/Ämter erstellen ihre eigene Sicherheitskonzeption mit Bezug zur Hausleitung (Ressort).
- **BSIG §8c (Hersteller) ≠ §8a (Betreiber)** — getrennte Pflichten beachten.

### 6.3 Override-Regeln im Wizard

```
Konzernebene: Sets policy with `inheritance_mode = 'mandatory'` or `'baseline'`.
Tochterebene: Wizard zeigt vererbte Felder read-only, neue Felder optional zur lokalen Spezifizierung.
- 'mandatory' → Tochter darf NUR ergänzen, niemals überschreiben.
- 'baseline'  → Tochter darf strenger, nie schwächer (BSI-Mindestniveau bleibt erhalten).
- 'guideline' → Tochter darf abweichen mit Begründungspflicht (Audit-Trail).
```

> **Memory-Cross-Ref:** Existierender `compliance_inheritance` Translation-Domain + entsprechende Services/Entities decken dieses Konzept bereits ab. Wizard nutzt vorhandene Inheritance-Layer.

---

## 7. Risiken bei Auto-Generierung

### 7.1 Das BSI-spezifische Längen-Problem

BSI-Bausteine sind **deutlich detaillierter** als ISO-27001-Controls. Ein vollständiger CON.8-Baustein hat über 30 Anforderungen. Auto-generierte Richtlinien werden so schnell 40-80 Seiten lang.

**Folgen:**
- Anwender lesen sie nicht (Compliance-Theater)
- ISB überfordert beim Tailoring
- Dokumentation wirkt aufgebläht und ungelebt

**Mitigation im Wizard:**
- **Layered Rendering:** Pflicht (Basis) groß, Standard zusammengeklappt, Hoch nur on-demand
- **Tailoring-Workshop-Modus:** Wizard fragt pro Anforderung "Anwendbar Y/N/teilweise + Begründung" statt alles zu generieren
- **Verweis statt Wiederholung:** Häufig wird derselbe Inhalt in mehreren Bausteinen wiederholt — Wizard erzeugt Glossar/Querverweise

### 7.2 BSI-Audit-Akzeptanz von Vorlagen

BSI akzeptiert "Richtlinien nach Vorlage" grundsätzlich — **aber** der ISB muss in der Audit-Situation **Tailoring beweisen** ("Warum ist diese Anforderung bei IHNEN so umgesetzt?"). Generische Boilerplate-Texte ohne tenant-spezifischen Kontext fallen sofort negativ auf.

**Pflicht-Tailoring-Felder pro Richtlinie (mind. 3):**
1. **Geltungsbereich-Konkretisierung** — pro Tenant individuell, kein Generic
2. **Verantwortliche-Liste** — namentlich, mit aktuellen Rollen
3. **Eigenes Beispiel / Anwendungsfall** — mind. 1 konkreter Tenant-Bezug pro Richtlinie

**Wizard-Implikation:** Wizard markiert diese 3 Felder als "Audit-relevant — generischer Text führt zu Nonconformity". Status-Indicator zeigt für jede Richtlinie "Tailoring-Niveau: rot/gelb/grün".

### 7.3 Behörden-spezifische Risiken

- Behörden müssen oft Geheimhaltungsstufen (VS-NfD, VS-V) behandeln. Generische Templates dürfen NICHT die Vertraulichkeitsstufe verwässern.
- Behörden-Diktion ("Hausleitung", "Geschäftsbereich", "Ressort") vs. Wirtschafts-Diktion ("Geschäftsführung", "Bereich") — Wizard muss `is_authority` Flag konsequent in Variable-Substitution durchziehen.

### 7.4 KRITIS-spezifische Risiken

- Falsche oder fehlende Meldepflichten in `DER.2.1` (BSIG §8b) → Bußgeld bis 20 Mio EUR oder 4% Jahresumsatz
- Auto-generierte Cloud-Richtlinie (`OPS.2.2`) ohne C5-Testat-Pflicht → Audit-Defizit
- Wizard muss bei `is_kritis = Y` zwingend C5/§8a-Sektionen einfügen

### 7.5 NIS2-Übergangsprobleme (Stand Mai 2026)

NIS2-UmsuG ist final verabschiedet. Wizard muss NIS2-Meldepflichten in `DER.2.1` aktualisiert haben (24h Frühwarnung, 72h-Detail-Meldung, 1-Monat-Final-Bericht). Veraltete Templates aus 2024-Phase sind zu vermeiden.

### 7.6 Keine Konkurrenz-Tools im Code/Doku

> **MEMORY-Pflicht:** Generierte Richtlinien dürfen NIEMALS namentlich Verinice/Vanta/Drata/HiScout etc. erwähnen. Standards (ISO/BSI/NIST/DSK) immer OK.

---

## 8. Empfehlungen

### 8.1 2-Spuren-Rendering (Compliance-Mode-Switch)

Wizard erkennt `tenant.compliance_frameworks` und rendert wie folgt:

| Mode | Header-Block | Mapping-Sektion | Sprache |
|---|---|---|---|
| **BSI-only** | "BSI IT-Grundschutz Edition 2023" | Nur Baustein-Refs | DE-Default, EN nur bei intl. Konzern |
| **ISO-only** | "ISO/IEC 27001:2022" | Nur Annex-A-Refs | DE oder EN je Tenant |
| **ISO + BSI Dual** | Beide Frameworks im Header | Cross-Map-Tabelle am Ende jeder Richtlinie | DE+EN, Mapping-Tabelle als Annex |
| **BSI + DORA / NIS2** | Hauptstandard + Sektor-Annex | Sektor-spezifischer Annex | DE-Default |

### 8.2 Variable-Substitution-Standards

Wizard nutzt einheitliche Twig-artige Platzhalter:

```
{{ tenant.legal_name }}
{{ tenant.scope_description }}
{{ tenant.isb_name }}
{{ tenant.isb_email }}
{{ tenant.leadership_signatory_name }}
{{ tenant.legal_form }}
{{ tenant.bsi_methodology | label }}   → "Standard-Absicherung"
{{ tenant.protection_scheme | levels }} → "normal / hoch / sehr hoch"
{{ today_de }}                           → "06.05.2026"
{{ document.version }}
{{ document.next_review_date | format_de }}
```

**Pflicht:** Alle Platzhalter im finalen Document müssen substituiert sein. Wizard validiert vor "Speichern" — ein offen gelassenes `{{ ... }}` blockiert den Save mit klarem Hinweis.

### 8.3 Genehmigungsworkflow (4-Augen-Prinzip)

Wizard erzeugt automatisch Workflow-Instances aus dem bestehenden Workflow-Modul:

```
Step 1: ISB-Vorbereitung (Status: Draft)
  Trigger: Wizard "Generate" Button
  Owner: ISB
  Auto-Progress: Wenn ISB "Submit for Approval" klickt
Step 2: Leitungs-Freigabe (Status: Pending Approval)
  Owner: tenant.leadership_signatory
  Auto-Progress: Bei Klick "Approve" — speichert Datum + User in Document.approved_at/_by
Step 3: Veröffentlichung (Status: Published)
  Owner: ISB
  Auto-Action: Notification an alle Mitarbeiter, Eintrag in Audit-Log
Step 4: Review-Reminder (Status: Active, mit Folge-Workflow)
  Trigger: Cron, X Tage vor next_review_date
  Owner: ISB
```

### 8.4 Document-Linking-Strategie

Generierte `Document` Records bekommen folgende Verknüpfungen:

```
Document
├── tags: ['richtlinie', 'BSI-IT-Grundschutz', 'Edition-2023', 'ISMS.1.A4', 'Pflicht']
├── compliance_framework: BSI-IT-Grundschutz (Edition 2023)
├── linked_bausteine: [ISMS.1, ORP.1, ...]
├── linked_anforderungen: [ISMS.1.A4, ISMS.1.A5, ...]
├── linked_iso27001_controls: [A.5.1, A.5.2, ...] (bei Dual-Compliance)
├── linked_workflow_instance: <Approval-Workflow-ID>
├── parent_policy: <Top-Level-Leitlinie-Document-ID> (bei Sub-Richtlinien)
└── tenant_id: <multi-tenancy-Pflicht>
```

### 8.5 Versionierung und Lebenszyklus

- Major-Version (1.0 → 2.0): Bei BSI-Edition-Wechsel oder Strukturänderung
- Minor-Version (1.0 → 1.1): Bei kleinen inhaltlichen Updates
- Patch-Version (1.0 → 1.0.1): Bei Tippfehlern, Klarstellungen

Wizard speichert komplette Version-History (Document-Versions-Tabelle), nie Überschreiben — Audit-Pflicht.

### 8.6 Edition-Update-Strategie (BSI-Kompendium 2024/2025/...)

BSI veröffentlicht jährlich neue Editionen. Wizard muss:
1. Edition-Tag im Document speichern (`bsi_edition: '2023'`)
2. Bei Edition-Wechsel **Diff-Report** erstellen (welche Bausteine geändert/neu/entfernt)
3. ISB entscheidet pro Tenant, ob Migration jetzt oder in nächstem Review-Zyklus

### 8.7 Pre-Generation-Checks (Wizard-Validierung)

Vor "Generate Policy Set" prüft Wizard:

| Check | Fail-Verhalten | Begründung |
|---|---|---|
| ISB benannt? | Hard-Block | ISMS.1.A4 Pflicht-Feld |
| Leitungssignator benannt? | Hard-Block | ISMS.1.A2 Pflicht |
| Scope ≥ 50 Zeichen + nicht generic? | Soft-Warn | Audit-Tailoring |
| Mind. 1 Standort + Abteilung? | Hard-Block | Geltungsbereich-Pflicht |
| BSI-Methodik gewählt? | Hard-Block | BSI 200-2 Pflicht |
| Krisenstab benannt bei has_crisis_team=Y? | Hard-Block | DER.4 |
| Klassifikationsschema definiert? | Hard-Block | ORP.1 |

### 8.8 "Alva-Tipp" Setup-Shortcuts

> **MEMORY-Pflicht:** Bei Detection unauffälliger "Alva-Tipp"-Card im Wizard:
> - "Alva schlägt vor: 3-stufiges Schutzbedarfsschema (BSI-Standard)" → ein Klick übernimmt
> - "Alva sieht: Sie haben kein BCM-Modul aktiv — wollen Sie DER.4 als reduzierte Variante (Reaktiv-BCMS) generieren?"
> - "Alva-Hint: Bei `is_kritis = Y` empfiehlt sich automatisches Hinzufügen der OPS.2.2 mit C5-Testat-Pflicht. Übernehmen?"

Pattern aus `project_alva_hint_foundation.md` — Tier-System, Versioning, Render+Dismiss-Telemetrie.

### 8.9 Empfohlene Wizard-Step-Reihenfolge

1. **Welcome** (Erkläre Sinn, Dauer ~20 Min)
2. **Vorgehensweise + Scope** (Schritt 1)
3. **Branche/Regulatorik** (Schritt 2)
4. **Sprache + Schutzbedarfsschema** (Schritt 3)
5. **Notfallorganisation** (Schritt 4)
6. **Module Cross-Reference** (Schritt 5)
7. **Approval-Routing** (Schritt 6)
8. **Review-Settings** (Schritt 7)
9. **Preview & Tailoring** (Wizard zeigt Liste der zu generierenden Richtlinien, ISB markiert Tailoring-Felder)
10. **Generate** (mit Hard-Block bei ungelösten Validierungen)
11. **Post-Generate Approval-Submission** (Workflow startet automatisch)

### 8.10 Was der Wizard explizit NICHT generiert

- **Methode-Doku Schutzbedarfsfeststellung** als separates Doc-Typ (siehe Kap. 4)
- **Risikoanalyse-Methodik (BSI 200-3)** — eigene Method-Doku, separater Wizard-Flow
- **APP/SYS/NET/INF/IND-Schicht-Richtlinien** — diese sind zielobjektspezifisch, gehören in IT-Grundschutz-Check, nicht Top-Level-Wizard
- **VVT (Verzeichnis von Verarbeitungstätigkeiten)** — gehört in DPO-Modul
- **BIA (Business Impact Analyse)** — gehört in BCM-Modul
- **Lieferanten-Verträge / AVV** — Vertrags-Templates, nicht Richtlinien

### 8.11 Persönliche Empfehlungen aus BSI-Audit-Praxis

| Empfehlung | Warum |
|---|---|
| **Pflicht-Mindestset zuerst, Optionales danach** | Verhindert Wizard-Abbruch bei Überforderung |
| **Konsequente Cross-Reference** statt Doppelung | BSI-Auditor findet sonst Widersprüche |
| **3-stufiges Schutzbedarfsschema als Default** | 4-stufig ist nur in seltenen Fällen sinnvoll und führt zu Mapping-Problemen mit anderen Frameworks |
| **DSGVO-Cross-Ref bewusst flach halten** | DSGVO ist eigene Welt — nicht mit BSI vermengen |
| **Templates auf Edition 2023 pflegen** | Edition-Drift sofort erkennbar machen |
| **Generated-Documents als 'Draft'** | Niemals direkt 'Approved' — Genehmigungs-Workflow erzwingen |
| **Audit-Trail für jede Wizard-Run** | Wer hat wann mit welchen Settings generiert? Pflicht. |

---

## Anhang A — Pflicht-Richtlinien-Set: Tabellarische Übersicht

| # | Richtlinie | Baustein | Pflicht-Niveau | Mandatiert von | ISO-27001-Map | Review |
|---|---|---|---|---|---|---|
| 1 | IT-Sicherheitsleitlinie | ISMS.1.A4 | Basis | 200-1, 200-2 | A 5.1 + Cl. 5.2 | 2J |
| 2 | ISMS-Konzept (Sicherheitskonzept) | ISMS.1.A6 | Basis | 200-2 | Cl. 4.3, 6 | 1J |
| 3 | Sicherheitsorganisation | ISMS.1.A1, A3, A8 | Basis | 200-1 | A 5.2, 5.3 | 1J |
| 4 | Organisationsrichtlinie | ORP.1.A1–A3 | Basis | 200-2 | A 5.4 | 2J |
| 5 | Personalrichtlinie | ORP.2.A1–A5 | Basis | 200-2 | A 6.1, 6.2, 6.5 | 2J |
| 6 | Awareness-Richtlinie | ORP.3.A1–A4 | Basis | 200-2 | A 6.3 | 1J |
| 7 | IAM-Richtlinie | ORP.4.A1–A9, A22 | Basis+Std | 200-2 | A 5.15–5.18, 8.2 | 1J |
| 8 | Kryptokonzept | CON.1.A1–A4, A6 | Basis | 200-2 + TR-02102 | A 8.24 | 2J |
| 9 | Datenschutz-Richtlinie | CON.2.A1–A2 | Basis | 200-2 + DSGVO | A 5.34 | 1J |
| 10 | Datensicherungskonzept | CON.3.A1–A6 | Basis | 200-2 | A 8.13 | 1J |
| 11 | Lösch-/Vernichtungsrichtlinie | CON.6.A1–A2 | Basis | 200-2 + DIN 66399 | A 7.10, 7.14, 8.10 | 2J |
| 12 | Auslandsreisen-Richtlinie | CON.7.A1–A6 | Basis | 200-2 | A 6.7, 7.9, 8.1 | 2J |
| 13 | Software-Entwicklungsrichtlinie | CON.8.A1–A6 | Basis | 200-2 | A 8.25–8.31 | 1J |
| 14 | Informationsaustausch-Richtlinie | CON.9.A1–A3 | Basis | 200-2 | A 5.14 | 2J |
| 15 | Webanwendungs-Richtlinie | CON.10.A1–A5 | Basis | 200-2 | A 8.26 | 1J |
| 16 | IT-Administrations-Richtlinie | OPS.1.1.2.A1–A6, A21 | Basis | 200-2 | A 8.2, 8.18 | 1J |
| 17 | Patch-/Change-Mgmt-Richtlinie | OPS.1.1.3.A1–A6 | Basis | 200-2 | A 8.8, 8.32, 8.9 | 1J |
| 18 | Schadprogramm-Schutz-Richtlinie | OPS.1.1.4.A1–A7 | Basis | 200-2 | A 8.7 | 1J |
| 19 | Protokollierungsrichtlinie | OPS.1.1.5.A1–A4 | Basis | 200-2 | A 8.15–8.17 | 1J |
| 20 | SW-Test-/Freigabe-Richtlinie | OPS.1.1.6.A1–A4 | Basis | 200-2 | A 8.29, 8.31 | 1J |
| 21 | Telearbeits-Richtlinie | OPS.1.2.4.A1–A3 | Basis | 200-2 | A 6.7 | 2J |
| 22 | Fernwartungs-Richtlinie | OPS.1.2.5.A1–A4 | Basis | 200-2 | A 8.21 | 1J |
| 23 | Cloud-Nutzungsrichtlinie | OPS.2.2.A1–A5 | Basis | 200-2 + C5 | A 5.23 | 1J |
| 24 | Outsourcing-/Lieferanten-Richtlinie | OPS.2.3.A1–A5 | Basis | 200-2 | A 5.19–5.23 | 1J |
| 25 | Detektionsrichtlinie | DER.1.A1–A4 | Basis | 200-2 | A 8.16 | 1J |
| 26 | Incident-Response-Richtlinie | DER.2.1.A1–A6 | Basis | 200-2 | A 5.24–5.28 | 1J |
| 27 | IT-Forensik-Richtlinie | DER.2.2.A1–A3 | Basis | 200-2 | A 5.28 | 2J |
| 28 | Notfallmanagement-Richtlinie | DER.4.A1–A4 | Basis | 200-4 | A 5.29, 5.30 / ISO 22301 | 1J |

**Summe:** 28 Pflicht-Richtlinien-Dokumente im Basis-Pflicht-Set + 1 Schutzbedarfsfeststellungs-Methode-Dokument = **29 generierte Documents** beim Standard-BSI-Wizard-Run.

---

## Anhang B — Quellen / Referenzen

- BSI IT-Grundschutz-Kompendium **Edition 2023**, BSI, Februar 2023, https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html
- BSI-Standard 200-1 "Managementsysteme für Informationssicherheit (ISMS)", Version 1.0, Oktober 2017
- BSI-Standard 200-2 "IT-Grundschutz-Methodik", Version 1.0, Oktober 2017 (mit Updates)
- BSI-Standard 200-3 "Risikoanalyse auf der Basis von IT-Grundschutz", Version 1.0, Oktober 2017
- BSI-Standard 200-4 "Business Continuity Management", Final 2023
- BSI TR-02102 "Kryptographische Verfahren: Empfehlungen und Schlüssellängen", Teile 1–4, jährliche Updates
- BSIG (BSI-Gesetz), Stand NIS2-UmsuG 2024/2025
- BSI C5:2020 (Cloud Computing Compliance Criteria Catalogue), Update C5:2026 angekündigt
- ISO/IEC 27001:2022 + ISO/IEC 27002:2022
- ISO/IEC 22301:2019 (BCMS)
- DIN 66399:2012 (Vernichtung von Datenträgern)
- Standard-Datenschutzmodell V3.0 (DSK), 2022

---

**Ende des Reports.**
