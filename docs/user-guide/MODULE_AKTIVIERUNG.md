# Modul-Aktivierung — Benutzerhandbuch

> **Zielgruppe:** Compliance Manager, CISO, ISB, DPO  
> **Sprache:** Deutsch (primär) / English where indicated  
> **Cross-references:** [MODULE_GATING_GUIDE.md](../MODULE_GATING_GUIDE.md) (Entwickler) | [FORM_AUDIT_2026-05.md](../archive/FORM_AUDIT_2026-05.md) (Auditoren)

---

## Überblick

Das ISMS-System zeigt nur die Formulare und Felder, die für Ihre aktiven Module
relevant sind. Ein reines ISO-27001-Unternehmen ohne Cloud-Services sieht keine
BSI-C5-Felder; eine Bank sieht MaRisk-Felder, eine IT-Firma ohne KI-Systeme sieht
keine EU-AI-Act-Felder.

Module gelten je Organisation. Ausgewählt werden sie im Setup-Wizard im Schritt
**Module** (`/setup/step7-modules`, mit Empfehlungen nach Branche und Größe aus
Schritt „Organisation“) und später unter **Admin → Module** (`/admin/modules`).
Eine Organisation ohne eigene Auswahl erbt die Vorgabe der Instanz
(`config/active_modules.yaml`). Abhängigkeiten werden beim Aktivieren mit
eingeschaltet.

---

## Optionale Fachmodule

### Personenbezogene Daten — `privacy`
- DSGVO Art. 30 Verarbeitungsverzeichnis (RoPA)
- Datenschutzverletzungen (Art. 33/34)
- Einwilligungs-Tracking mit Widerruf (Art. 7)
- Betroffenenanfragen mit Frist-Tracking (Art. 12/15-21)
- Datenschutz-Folgenabschätzung / DPIA (Art. 35)
- DSGVO-Subset in Risikobewertungen (Art. 35 Trigger)

---

### EU-Cyber-Resilience-Pflicht — `nis2_dora`
- DORA ICT-Incident-Klassifikation (Art. 17)
- Regulatorische Meldepflicht bei Incidents (24h/72h Deadline)
- NIS2 Art. 23 Meldung
- DORA ICT-Risikokategorisierung in Risiken

---

### KI-Systeme — `ai_governance`
- EU AI Act Risk-Klassifikation von KI-Assets (Art. 6)
- Validierung gegen verbotene KI-Praktiken (Art. 5) — System blockiert Speichern
- Konformitäts-Assessment-Status (Art. 17)
- ISO 42001 AIMS-Referenz

---

### Cloud-Provider oder Cloud-Nutzer — `cloud_security`
- ISO 27017-Referenzfelder in Controls (cloud-spezifische Sicherheitsmaßnahmen)
- ISO 27018-Felder (Cloud-Datenschutz)
- ISO 27701 PIMS-Referenz
- Shared-Responsibility-Dokumentation (Customer vs. Provider)
- BSI C5 / AIC4 Compliance-Tracking

---

### Aktives Schwachstellen- und Threat-Management — `vulnerability_intel`
- TLP 2.0 Klassifikation für Threat Intelligence (FIRST-Standard)
- MITRE ATT&CK Technik-IDs
- Threat Actor Attribution
- Indicators of Compromise (IOC)
- Confidence-Score für Intelligence-Einträge

---

### Bank oder Versicherung in DACH — `marisk`
- MaRisk AT 9.2 Outsourcing-Klassifikation (wesentlich/nicht-wesentlich)
- Aufsichtliche Meldepflicht bei Weiterverlagerung
- Subcontractor-Chain-Dokumentation
- Exit-Strategy-Dokumentation

---

### Automobilindustrie-Lieferkette — `tisax`
- TISAX Assessment-Labels
- VDA ISA Anforderungsreferenzen

---

### Quantitative Risikoanalyse — `risk_quant`
- FAIR-Methodik-Felder (Loss Event Frequency, Loss Magnitude)
- Quantitativer Risiko-Score

---

## Immer aktive Module (nicht deaktivierbar)

`core`, `authentication`, `audit_logging`, `documents`, `objectives`,
`notifications`, `workflows` (`required: true` in `config/modules.yaml`).

Alle anderen Module sind abschaltbar, auch `assets`, `risks`, `controls`,
`incidents`, `audits`, `training` und `reviews`; für ISO 27001 sollten sie aktiv
bleiben (siehe FAQ).

### Optional, aber häufig aktiviert

| Modul | Was es enthält |
|---|---|
| `bcm` | Business-Continuity-Pläne, Krisenteam, BC-Übungen (ISO 22301) |
| `compliance` | Multi-Framework-Compliance-Import, Framework-Bibliothek |
| `bsi_grundschutz` | BSI IT-Grundschutz-Bausteine und Maßnahmen |
| `ocr_processing` | Server-seitige OCR für Compliance-Zertifikate — extrahiert Felder automatisch aus hochgeladenen PDFs/Scans (pdftotext + Tesseract) |

---

## Modul `ocr_processing` — OCR für Compliance-Zertifikate

Das Modul `ocr_processing` aktiviert die server-seitige Texterkennung (OCR)
beim Upload von Compliance-Zertifikaten (ISO 27001, BSI, TISAX u.ä.).
Erkannte Felder (Ausstellende Stelle, Zertifikatsnummer, Gültigkeitszeitraum,
Zertifikatsinhaber, Norm-Referenz) werden als **Entwurf** in das
Upload-Formular vorausgefüllt — der Nutzer prüft und korrigiert die Felder
vor dem Speichern.

**Wichtig:** Das OCR-Ergebnis ist eine Arbeitshilfe, kein autoritativer
Datensatz. Die menschliche Prüfung vor dem Speichern ist Pflicht
(`extractionSource = 'ocr+confirmed'` wird erst nach Bestätigung gesetzt).
Alle Daten verbleiben auf dem Server — es werden keine externen APIs oder
Cloud-OCR-Dienste genutzt.

### Voraussetzungen

| Voraussetzung | Details |
|---|---|
| Modul aktiv | `ocr_processing` muss unter `Admin → Module` (`/admin/modules`) aktiviert sein |
| `pdftotext` | Aus dem Paket `poppler-utils` (Debian/Ubuntu) — wandelt PDF in Klartext um |
| `tesseract` | Aus dem Paket `tesseract-ocr` + Sprachpaket `tesseract-ocr-deu` (Deutsch) + `tesseract-ocr-eng` (Englisch) — Bilderkennung für eingescannte Zertifikate |
| `ghostscript` | Aus dem Paket `ghostscript` — PDF-Vorverarbeitung für Scan-Qualität |

**Docker-Installation (offizielles Image):** Alle vier Pakete sind im
offiziellen Docker-Image vorinstalliert. Sobald das Modul aktiviert ist,
steht OCR sofort zur Verfügung — kein weiterer Einrichtungsaufwand.

**Shared-Hosting / eigener Server ohne diese Binaries:** Fehlen die Binaries,
bleibt OCR automatisch deaktiviert. Das Zertifikat-Upload-Formular funktioniert
vollständig weiter — Nutzer geben die Felder manuell ein. Es erscheint kein
Fehler; nur die automatische Vorausfüllung entfällt.

### Aktivierung

1. Navigieren Sie zu `Admin → Module` (`/admin/modules`)
2. Aktivieren Sie das Toggle bei `ocr_processing`
3. Speichern — die Erkennung ist sofort aktiv für neue Zertifikat-Uploads

Ob OCR auf Ihrem Server verfügbar ist, zeigt die Zertifikat-Upload-Seite:
erscheint kein OCR-Hinweis, fehlen entweder die Binaries oder das Modul ist
nicht aktiviert.

---

## Modul-Aktivierung nachträglich ändern

**Als Administrator:**

1. Navigieren Sie zu `Admin → Module` (`/admin/modules`)
2. Aktivieren oder deaktivieren Sie Module per Toggle
3. Speichern → sofort wirksam (kein Neustart erforderlich)
4. Bereits eingegebene Daten in deaktivierten Feldern gehen **nicht** verloren —
   die Felder werden nur ausgeblendet.

**Vorgabe der Instanz** (für Organisationen ohne eigene Auswahl):

```yaml
# config/active_modules.yaml (Vorlage: active_modules.yaml.dist)
active_modules:
    - core
    - assets
    - risks
    - privacy
    - nis2_dora
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

Aktivieren Sie `nis2_dora` (DORA gilt seit 17.01.2025) und `marisk`
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

Unter `Admin → Module` (`/admin/modules`) steht der aktuelle Status je Organisation.

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
| Quantitative Risikoanalyse (SLE/ARO/ALE) | risk_quant | + risks |
