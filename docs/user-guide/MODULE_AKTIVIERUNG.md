# Modul-Aktivierung — Benutzerhandbuch

> **Zielgruppe:** Compliance Manager, CISO, ISB, DPO  
> **Sprache:** Deutsch (primär) / English where indicated  
> **Cross-references:** [MODULE_GATING_GUIDE.md](../MODULE_GATING_GUIDE.md) (Entwickler) | [FORM_AUDIT_2026-05.md](../FORM_AUDIT_2026-05.md) (Auditoren)

---

## Überblick

Das ISMS-System zeigt nur die Formulare und Felder, die für Ihre aktiven Module
relevant sind. Ein reines ISO-27001-Unternehmen ohne Cloud-Services sieht keine
BSI-C5-Felder; eine Bank sieht MaRisk-Felder, eine IT-Firma ohne KI-Systeme sieht
keine EU-AI-Act-Felder.

Die Aktivierung erfolgt einmalig über den **Setup-Wizard** (`/setup-wizard`) und
kann jederzeit durch einen Administrator angepasst werden.

---

## Setup-Wizard — 8 Compliance-Fragen

Der Setup-Wizard stellt 8 Ja/Nein-Fragen, die automatisch die richtigen Module
aktivieren:

### Frage 1: Personenbezogene Daten
> „Verarbeiten Sie personenbezogene Daten (Kunden, Mitarbeiter, Partner)?"

**Ja → Modul `privacy` aktiv:**
- DSGVO Art. 30 Verarbeitungsverzeichnis (RoPA)
- Datenschutzverletzungen (Art. 33/34)
- Einwilligungs-Tracking mit Widerruf (Art. 7)
- Betroffenenanfragen mit Frist-Tracking (Art. 12/15-21)
- Datenschutz-Folgenabschätzung / DPIA (Art. 35)
- DSGVO-Subset in Risikobewertungen (Art. 35 Trigger)

---

### Frage 2: EU-Cyber-Resilience-Pflicht
> „Sind Sie KRITIS-Betreiber, Finanzinstitut oder Versicherung (NIS2 / DORA-pflichtig)?"

**Ja → Modul `nis2_dora` aktiv:**
- DORA ICT-Incident-Klassifikation (Art. 17)
- Regulatorische Meldepflicht bei Incidents (24h/72h Deadline)
- NIS2 Art. 23 Meldung
- DORA ICT-Risikokategorisierung in Risiken

---

### Frage 3: KI-Systeme
> „Setzen Sie KI-Systeme ein oder entwickeln Sie KI-Anwendungen?"

**Ja → Modul `ai_governance` aktiv:**
- EU AI Act Risk-Klassifikation von KI-Assets (Art. 6)
- Validierung gegen verbotene KI-Praktiken (Art. 5) — System blockiert Speichern
- Konformitäts-Assessment-Status (Art. 17)
- ISO 42001 AIMS-Referenz

---

### Frage 4: Cloud-Provider oder Cloud-Nutzer
> „Sind Sie Cloud-Service-Provider oder nutzen Sie Cloud-Dienste wesentlich?"

**Ja → Modul `cloud_security` aktiv:**
- ISO 27017-Referenzfelder in Controls (cloud-spezifische Sicherheitsmaßnahmen)
- ISO 27018-Felder (Cloud-Datenschutz)
- ISO 27701 PIMS-Referenz
- Shared-Responsibility-Dokumentation (Customer vs. Provider)
- BSI C5 / AIC4 Compliance-Tracking

---

### Frage 5: Aktives Schwachstellen- und Threat-Management
> „Betreiben Sie aktives Vulnerability Management oder Threat Intelligence?"

**Ja → Modul `vulnerability_intel` aktiv:**
- TLP 2.0 Klassifikation für Threat Intelligence (FIRST-Standard)
- MITRE ATT&CK Technik-IDs
- Threat Actor Attribution
- Indicators of Compromise (IOC)
- Confidence-Score für Intelligence-Einträge

---

### Frage 6: Bank oder Versicherung in DACH
> „Ist Ihr Unternehmen eine Bank, Versicherung oder Finanzdienstleister unter BaFin-Aufsicht?"

**Ja → Modul `marisk` aktiv:**
- MaRisk AT 9.2 Outsourcing-Klassifikation (wesentlich/nicht-wesentlich)
- Aufsichtliche Meldepflicht bei Weiterverlagerung
- Subcontractor-Chain-Dokumentation
- Exit-Strategy-Dokumentation

---

### Frage 7: Automobilindustrie-Lieferkette
> „Sind Sie Lieferant in der Automobilindustrie (VDA / TISAX-pflichtig)?"

**Ja → Modul `tisax` aktiv:**
- TISAX Assessment-Labels
- VDA ISA Anforderungsreferenzen

---

### Frage 8: Quantitative Risikoanalyse
> „Möchten Sie quantitative Risikoanalyse (in Geldwerten) durchführen?"

**Ja → Modul `quantitative_risk` aktiv:**
- FAIR-Methodik-Felder (Loss Event Frequency, Loss Magnitude)
- Quantitativer Risiko-Score

---

## Immer aktive Module (nicht deaktivierbar)

| Modul | Was es enthält |
|---|---|
| `core` | ISO 27001 Kontext, ISMS-Ziele, interessierte Parteien |
| `assets` | Asset Management, Asset-Abhängigkeiten |
| `risks` | Risikobewertung, Risikobehandlungsplan |
| `controls` | SoA, ISO 27001 Annex A Controls |
| `incidents` | Incident-Management (Basis) |
| `audits` | Interne Audits, Checklisten |
| `training` | Schulungen und Sensibilisierung |
| `reviews` | Management-Review (§9.3) |
| `authentication` | Benutzer- und Rollenverwaltung |
| `audit_logging` | Prüfprotokoll für alle Aktionen |

### Optional, aber häufig aktiviert

| Modul | Was es enthält |
|---|---|
| `bcm` | Business-Continuity-Pläne, Krisenteam, BC-Übungen (ISO 22301) |
| `compliance` | Multi-Framework-Compliance-Import, Framework-Bibliothek |
| `bsi_grundschutz` | BSI IT-Grundschutz-Bausteine und Maßnahmen |

---

## Modul-Aktivierung nachträglich ändern

**Als Administrator:**

1. Navigieren Sie zu `Admin → Tenant-Einstellungen → Module`
2. Aktivieren oder deaktivieren Sie Module per Toggle
3. Speichern → sofort wirksam (kein Neustart erforderlich)
4. Bereits eingegebene Daten in deaktivierten Feldern gehen **nicht** verloren —
   die Felder werden nur ausgeblendet.

**Technisch (direkt in Konfiguration):**

```yaml
# config/active_modules.yaml
modules:
  privacy: true
  nis2_dora: true
  ai_governance: false
  cloud_security: true
  vulnerability_intel: false
  marisk: false
  tisax: false
  quantitative_risk: false
  bcm: true
  compliance: true
  bsi_grundschutz: false
```

---

## FAQ — Audit-Vorbereitung

### "Welche Module brauche ich für ISO 27001 Zertifizierung?"

Mindestens: `core`, `assets`, `risks`, `controls`, `incidents`, `audits`,
`training`, `reviews`, `audit_logging`.

Empfohlen zusätzlich: `bcm` (Business Continuity ist Teil des ISO 27001 Scope
und ISO 22301), `compliance` (für Framework-Mapping-Nachweis).

### "Welche Module aktiviere ich für DSGVO-Compliance?"

Aktivieren Sie `privacy`. Das schaltet alle DSGVO-spezifischen Formulare frei:
Verarbeitungsverzeichnis, Datenschutzverletzungen, Einwilligungs-Management,
Betroffenenanfragen, DPIA.

### "Wir sind Bank — was brauchen wir?"

Aktivieren Sie `nis2_dora` (DORA-Pflicht ab Jan. 2025) und `marisk`
(MaRisk-Outsourcing-Dokumentation). Wenn Sie auch personenbezogene Daten
verarbeiten: `privacy`.

### "Wir nutzen AWS / Azure / GCP — brauchen wir cloud_security?"

Ja, wenn Sie ISO 27017/18 nachweisen müssen oder BSI C5-Attestierung Ihrer
Cloud-Provider dokumentieren. Das Modul fügt Referenzfelder zu den Controls hinzu.

### "Was passiert mit Daten, wenn ich ein Modul deaktiviere?"

Daten bleiben in der Datenbank erhalten. Nur die UI-Felder und Formularsektionen
werden ausgeblendet. Eine spätere Reaktivierung stellt alle Daten wieder dar.

### "Kann ich TISAX ohne `tisax`-Modul nachweisen?"

Nein — ohne aktives TISAX-Modul fehlen die VDA ISA-Referenzfelder. Aktivieren
Sie das Modul vor der Assessment-Vorbereitung.

### "Welche Felder sind auch ohne optionale Module immer vorhanden?"

Alle Kernfelder für ISO 27001: Risiken mit Likelihood/Impact/Justification,
Controls mit Effectiveness und Maturity, Incidents mit Evidence Collection,
Management Reviews mit Attendance und Outputs, Audit Findings mit Source,
Corrective Actions mit ActionType.

### "Wie weise ich dem Auditor nach, welche Module aktiv sind?"

Navigieren Sie zu `Admin → Tenant-Einstellungen → Module` — dort ist der
aktuelle Status sichtbar. Alternativ: git-Historie der `config/active_modules.yaml`
(enthält Zeitstempel der Änderungen).

---

## Modul-Reifegrad nach Compliance-Framework

| Framework | Pflicht-Module | Empfehlung |
|---|---|---|
| ISO 27001:2022 | core, assets, risks, controls, incidents, audits, training, reviews | + bcm, compliance |
| DSGVO / GDPR | privacy | + risks (Art. 35-Trigger) |
| DORA | nis2_dora | + marisk (Banken/Vers.) |
| NIS2 Art. 21 | nis2_dora | + incidents (KRITIS) |
| BSI C5 (Cloud) | cloud_security | + compliance |
| BSI IT-Grundschutz | bsi_grundschutz | + controls, assets |
| ISO 22301 (BCM) | bcm | + reviews |
| EU AI Act | ai_governance | + risks (AI risk) |
| MaRisk | marisk | + nis2_dora (DORA-Overlap) |
| TISAX | tisax | + assets, risks |
| FAIR (Quantitativ) | quantitative_risk | + risks |
