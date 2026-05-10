# Compliance-Wizard-System — Benutzerhandbuch (v3.5)

Der Compliance-Wizard fuehrt Nutzer strukturiert durch die Anforderungen eines
Compliance-Frameworks. Antworten werden als Session-Snapshot gespeichert und koennen
mit frueheren Laeufen verglichen werden.

---

## 22 verfuegbare Wizards

| Wizard | Standard / Regulierung | Maturity-Varianten |
|---|---|---|
| ISO 27001:2022 | ISO 27001 Annex A + Clauses 4-10 | Baseline / Enhanced |
| NIS2 | EU-Richtlinie 2022/2555 + NIS2-UmsuCG (BGBl. 2025) | Baseline / Enhanced |
| DORA | EU 2022/2554 (Level-1 + Level-2 RTS/ITS) | Baseline / Enhanced |
| TISAX | VDA ISA 6.0 | Baseline / Enhanced |
| GDPR / DSGVO | DSGVO + BDSG | Baseline / Enhanced |
| ISO 22301 | Business Continuity (BCM) | Standard |
| ISO 27701 | Datenschutz-ISMS | Standard |
| ISO 27017 | Cloud Security (CLD-Erweiterungen) | Standard |
| ISO 27018 | PII-Schutz in Public Clouds | Standard |
| ISO 42001 | KI-Management-System | Baseline / Enhanced |
| BSI IT-Grundschutz | BSI 200-1/2/3/4 | Standard |
| BSI C5:2020 | Cloud-Sicherheit BSI | Standard |
| BSI C5:2026 | Cloud-Sicherheit BSI (Neue Fassung) | Standard |
| NIST CSF 2.0 | NIST Cybersecurity Framework | Standard |
| KRITIS | BSI-Kritis-Dachgesetz | Standard |
| PCI-DSS 4.0.1 | Zahlungskartenindustrie | Standard |
| SOC 2 | AICPA Trust Services Criteria | Standard |
| EU AI Act | EU 2024/1689 (alle 113 Artikel) | Baseline / Enhanced |
| EUCS | ENISA EU Cloud Security Scheme | Standard |
| EU CRA | Cyber Resilience Act (Annex I) | Standard |
| MRIS v1.5 | Branchen-Reife-Referenz (intern) | Standard |
| Industry-Baseline-Express | Kombinations-Wizard (Tag-1-Onboarding) | Preset-basiert |

---

## WizardSession — 22 Slots

Jeder Wizard speichert seinen Zustand in einer eigenen `WizardSession`-Instanz
(Doctrine-Entity, tenant-scoped). Ein Slot entspricht einem Wizard-Typ.

- Pro Nutzer und Tenant wird ein Session-Objekt pro Wizard-Typ angelegt.
- Beim neuerlichen Starten des Wizards wird der letzte Snapshot geladen.
- Ein "Neu beginnen"-Button setzt den Slot zurueck und archiviert den alten
  Snapshot (er bleibt in der History abrufbar).

Sessions werden in `wizard_sessions` gespeichert; die History in `wizard_session_history`.

---

## Wizard-History-Diff-View (V4-EF-3)

Nach mindestens zwei abgeschlossenen Wizard-Laeufen erscheint der Tab "Verlauf".

**Funktion:** Zwei Snapshots auswaehlen und nebeneinander vergleichen.

- Antwort-Unterschiede werden farbig hervorgehoben (alt / neu).
- Score-Delta wird oben als `+5 Punkte / -3 Anforderungen erfullt` angezeigt.
- Der Diff kann als PDF exportiert werden (siehe "Compare-PDF-Export").

Typische Anwendungsfaelle:

- Nachweis der Reife-Verbesserung vor einem Audit
- Vergleich vor/nach einem neuen Kontroll-Set
- Management-Reporting ueber Fortschritt im Quartal

---

## Industry-Preset Express-Path (Tag-1-Onboarding)

Der Express-Wizard ist ein Sonderfall: Er fragt nicht einzelne Anforderungen ab,
sondern drei hochrangige Fragen:

1. Branche (9 Optionen: Produktion, Finanzdienstleister, Gesundheitswesen,
   Automotive, Cloud/Hosting, IT-Dienstleister, KRITIS, MSP, Generisch)
2. Unternehmensgroesse (KMU < 250 / Mittelstand 250-1.000 / Enterprise > 1.000)
3. Vorhandene Zertifizierungen (ISO 9001, ISO 27001, etc.)

Aus diesen Antworten aktiviert der `IndustryPresetService` automatisch:

- Relevante Compliance-Module (z. B. `nis2_dora` fuer KRITIS-Branchen)
- Einen vorausgefuellten Framework-Katalog mit empfohlenen Controls
- Einen Branchen-Reife-Baseline als Soll-Profil fuer das Dashboard

Der Express-Path kann jederzeit durch einen vollstaendigen Wizard-Lauf
verfeinert werden.

---

## Compare-PDF-Export

Aus dem History-Diff-View heraus kann ein PDF-Bericht erstellt werden.

Inhalt des Compare-PDF:

- Deckblatt mit Tenant-Name, Erstellungsdatum, Wizard-Typ, Snapshot-Daten
- Executive Summary (Score-Delta, Anzahl neu erfuellter / weggefallener Anforderungen)
- Detailseiten: eine Zeile pro Anforderung mit Alt-/Neu-Antwort und Ampelstatus
- Unterschriften-Zeile fuer Freigabe durch Management

Exportiert via `ComplianceExportService` -> DomPDF.

---

## Catalogue-Coverage-KPI

Am Ende jedes Wizards zeigt das Ergebnis zwei Kennzahlen:

1. **Score** (Maturity-Punkte, 0-100): Wie reif ist die Umsetzung?
2. **Catalogue-Coverage**: `X / Y Anforderungen aus dem Framework-Katalog erfuellt`
   als Fortschrittsbalken.

Beide KPIs fliessen in das ISMS-Health-Score-Dashboard ein.

---

## Cross-Framework-Mapping-Hub

Unter `/de/compliance/mapping-hub` sind alle Cross-Framework-Mappings sichtbar.

| Kennzahl | Wert (v3.5) |
|---|---|
| Persistente Mappings | ~3.543 |
| Mapping-Bibliotheken (YAML-Fixtures) | 56 |
| Lifecycle-Stufen | draft → review → approved → published |
| Lex-Specialis-Markierungen | DORA <-> NIS2, NIS2-UmsuCG <-> DORA (DE-Finanz) |

Transitive Ableitung: Ein Nachweis (Evidence) kann mehrere Frameworks gleichzeitig
erfullen, wenn ein Mapping existiert. Das System berechnet die Coverage automatisch
und zeigt im Wizard "Bereits durch [ISO 27001 Control A.8.7] abgedeckt" an.

Neue Mappings durchlaufen den 4-Stufen-Lifecycle. `ROLE_COMPLIANCE_MANAGER` kann
Mappings aus dem Status `review` in `approved` ueberleiten.

---

## Maturity-Varianten (Baseline / Enhanced)

Wizards mit zwei Varianten stellen je nach gewaehlter Variante unterschiedliche
Narrative bereit:

- **Baseline (KMU-pragmatisch):** Minimal-Anforderungen fuer eine erste Umsetzung,
  pragmatisch formuliert, ISO-9001-Analogien fuer Quereinsteiger.
- **Enhanced (audit-ready):** Vollstaendige Nachweispflichten, Referenz auf
  spezifische Normparagraphen, Hinweise fuer Zertifizierungs-Audits.

Die Variante kann zwischen Wizard-Laeufen gewechselt werden; der Score wird
entsprechend neu berechnet.

---

## Wizard-Module-Mapping

Wizards aktivieren beim Abschluss automatisch relevante Module, sofern der Nutzer
dies bestaetigt:

| Wizard | Aktivierte Module |
|---|---|
| GDPR-Wizard | `privacy` |
| DORA-Wizard | `nis2_dora` |
| NIS2-Wizard | `nis2_dora` |
| BSI IT-Grundschutz | `bsi_grundschutz` |
| ISO 42001 / EU AI Act | `ai_governance` |
| ISO 27017 / C5 / C5:2026 | `cloud_security` |
| BCM / ISO 22301 | `bcm` |
| TISAX | `tisax` |

Der `IndustryPresetService` kann Module auch im Express-Path aktivieren, ohne
dass ein vollstaendiger Wizard-Lauf erforderlich ist.

---

## Verwandte Dokumente

- `docs/user-guide/PERSONA_DASHBOARDS.md` — Rollenspezifische Dashboards
- `docs/user-guide/MODULE_AKTIVIERUNG.md` — Modulverwaltung fuer Administratoren
- `docs/MODULE_GATING_GUIDE.md` — Entwickler-Referenz fuer Module-Gating
- `docs/architecture/CROSS_FRAMEWORK_MAPPINGS.md` — Mapping-Architektur
