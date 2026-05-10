# NIS2 — Richtlinie (EU) 2022/2555

Stand: v3.5 (2026-05). Gilt fuer das Modul `nis2_dora` (Aktivierungskey in `config/modules.yaml`).

---

## 1. Ueberblick

Die NIS2-Richtlinie (Richtlinie (EU) 2022/2555, gueltig ab 17. Oktober 2024) verstaerkt
die Cybersicherheitsanforderungen fuer wesentliche und wichtige Einrichtungen in der EU.
Das Tool implementiert NIS2 auf Basis der Richtlinie, der deutschen Umsetzung (NIS2UmsuCG)
sowie ENISA-Implementierungsleitfaeden.

Das Modul ist mit DORA unter einem gemeinsamen Aktivierungskey (`nis2_dora`) zusammengefasst,
da beide Rechtsakte fuer Finanzunternehmen in einem Lex-specialis-Verhaeltnis stehen
(Art. 4 Abs. 1 NIS2-RL).

---

## 2. Catalogue-Laden (Commands)

```bash
# NIS2 Basisanforderungen (70 Katalogeintraege)
php bin/console app:load-nis2-requirements

# NIS2 vollstaendiger Katalog
php bin/console app:load-nis2-full

# NIS2-UmsuCG Basisanforderungen (deutsche Umsetzung)
php bin/console app:load-nis2-umsucg-requirements

# NIS2-UmsuCG vollstaendiger Katalog
php bin/console app:load-nis2-umsucg-full

# Seed NIS2 ↔ ISO 27001:2022 Mappings
php bin/console app:seed-nis2-iso27001-mappings

# Seed NIS2 Policy-Templates (12 Pflichtrichtlinien)
php bin/console app:seed-nis2-policy-templates
```

---

## 3. NIS2-Katalog — 70 Katalogeintraege

Der NIS2-Katalog umfasst 70 Anforderungseintraege auf Basis der Richtlinie (EU) 2022/2555,
der ENISA Implementation Guidance und des BSI NIS2-UmsuCG.

Identifier-Schema: `NIS2-Art.X-AbsY` (nach Artikel- und Absatznummer).

Datei: `src/Command/LoadNis2FullCommand.php`

---

## 4. NIS2-UmsuCG — Vollmapping (DE)

Das NIS2-Umsetzungsgesetz fuer Deutschland (NIS2UmsuCG) setzt die Richtlinie (EU) 2022/2555
in deutsches Recht um. Der vollstaendige Katalog ist ueber `LoadNis2UmsuCGFullCommand` ladbar.

Datei: `src/Command/LoadNis2UmsuCGFullCommand.php`

---

## 5. NIS2-Reifegradmodell (Opt C — Baseline / Enhanced)

Das Tool implementiert ein zweistufiges Reifegradmodell fuer NIS2-Konformitaet:

| Stufe | Beschreibung | Zielgruppe |
|---|---|---|
| **Baseline** | Mindestanforderungen Art. 21 NIS2 — grundlegende Massnahmen | Wichtige Einrichtungen (Kategorie 2) |
| **Enhanced** | Erweiterter Reifegrad — fortgeschrittene Umsetzung | Wesentliche Einrichtungen (Kategorie 1), KRITIS |

Der Reifegrad steuert, welche Anforderungen im Compliance-Wizard als obligatorisch markiert
werden. Wesentliche Einrichtungen (Art. 3 Abs. 1 NIS2) erhalten einen strengeren Pruefpfad.

Compliance-Wizard-Route: `/{locale}/compliance/wizard/nis2`

---

## 6. BSI-MUS Export (Art. 23 NIS2)

Art. 23 NIS2 verpflichtet wesentliche und wichtige Einrichtungen zur Meldung erheblicher
Sicherheitsvorfaelle an die national zustaendige Behoerde (in DE: BSI).

Das Tool implementiert den BSI-MUS-Export (Meldeformat Unified Standard):

```
src/Controller/Nis2MusExportController.php
src/Service/Nis2MusExportService.php
```

### 6.1 Melde-Fristen (Art. 23 Abs. 4 NIS2)

| Meldung | Frist | NIS2-Referenz |
|---|---|---|
| Fruehwarnung | 24 Stunden | Art. 23 Abs. 4 lit. a |
| Erstmeldung | 72 Stunden | Art. 23 Abs. 4 lit. b |
| Schlussbericht | 1 Monat | Art. 23 Abs. 4 lit. c |

SLA-Verwaltung: `src/Entity/IncidentSlaConfig.php`

### 6.2 Cross-Reporting GDPR / NIS2

Datenpannen (DataBreach) mit NIS2-Relevanz koennen direkt als NIS2-MUS-Meldung exportiert
werden. Pfad: `DataBreach` → `Incident` → `Nis2MusExportService`.

---

## 7. Cross-Mappings

### 7.1 NIS2 ↔ DORA (Lex Specialis Art. 4 Abs. 1 NIS2)

Finanzunternehmen, die unter DORA fallen, erfullen NIS2-Anforderungen durch DORA-Konformitaet
soweit die DORA-Anforderungen mindestens gleichwertig sind.

| NIS2 Art. | DORA Aequivalent | Bewertung |
|---|---|---|
| Art. 21 Abs. 2 lit. a (Policies) | Art. 5–16 ICT-RMF | DORA vollstaendiger |
| Art. 21 Abs. 2 lit. b (Incident Handling) | Art. 17–23 | DORA scharfer (4h vs. 24h) |
| Art. 21 Abs. 2 lit. c (BCM) | Art. 11–12 RCBC/DR | Gleichwertig |
| Art. 21 Abs. 2 lit. d (Supply Chain) | Art. 28–44 TPR | DORA detaillierter |
| Art. 23 (Meldepflicht) | Art. 19 | DORA scharfer — Lex specialis |

### 7.2 NIS2 ↔ EU AI Act (Verordnung (EU) 2024/1689)

| NIS2 Art. | EU AI Act | Thema |
|---|---|---|
| Art. 21 Abs. 2 lit. a | Art. 9 (Risk-Management-System) | KI-Risikorahmen |
| Art. 21 Abs. 2 lit. b | Art. 17 (Technische Dokumentation) | Incident-Prozess fuer KI |
| Art. 21 Abs. 2 lit. e | Art. 13 (Transparenz) | Transparenzpflichten Hochrisiko-KI |
| Art. 21 Abs. 2 lit. j | Art. 9 Abs. 7 (Datenverwaltung) | Datensicherheit KI-Training |

Implementierung: `src/Entity/WizardSession::WIZARD_EU_AI_ACT`, `src/Entity/Asset::$aiActClassification`

### 7.3 NIS2 ↔ EU Cyber Resilience Act (CRA) (Verordnung (EU) 2024/2847)

| NIS2 Art. | CRA Art. | Thema |
|---|---|---|
| Art. 21 Abs. 2 lit. e (Security in Acquisition) | Art. 13 (Hersteller) | Sicherheit in Beschaffung |
| Art. 21 Abs. 2 lit. f (Schwachstellen) | Art. 13 Abs. 6/8 + Art. 14 | SBOM + Schwachstellenoffenlegung |
| Art. 21 Abs. 2 lit. b (Incident Handling) | Art. 14 Abs. 3 (24h Meldung) | Koordinierte Schwachstellenmeldung |
| Art. 21 Abs. 2 lit. d (Supply Chain) | Art. 13 Abs. 5 (Lieferkette) | Software-Lieferkettensicherheit |

Implementierung: `src/Entity/WizardSession::WIZARD_CRA`

### 7.4 NIS2 ↔ BSI C5:2026

| NIS2 Art. 21 | BSI C5:2026 Domane | Bemerkung |
|---|---|---|
| Abs. 2 lit. a (Policies) | OPS-01 | Betriebspolitik und Verantwortung |
| Abs. 2 lit. b (Incident Handling) | OPS-09 | Sicherheitsvorfaelle |
| Abs. 2 lit. c (BCM) | OPS-18 | Geschaeftskontinuitaet |
| Abs. 2 lit. d (Supply Chain) | PSS-01..08 | Product- und Servicequalitaet |
| Abs. 2 lit. e (Security in Acquisition) | DEV-01..10 | Entwicklung und Beschaffung |
| Abs. 2 lit. f (Schwachstellen) | OPS-14 | Schwachstellenmanagement |
| Abs. 2 lit. h (Krypto) | COS-01..09 | Kryptographie |
| Abs. 2 lit. j (HR/Schulung) | OPS-01 | Personalsicherheit |

Seed-Befehl: `src/Command/SeedC52026Iso27001MappingsCommand.php`
(C5:2026 ↔ ISO 27001:2022 als Pivot — NIS2-Aequivalenz ableitbar).

### 7.5 NIS2 ↔ ISO 27001:2022

| NIS2 Art. 21 | ISO 27001:2022 | Hinweis |
|---|---|---|
| Abs. 2 lit. a (Policies) | Kl. 5.2, Annex A 5.1 | ISMS-Policy |
| Abs. 2 lit. b (Incident Handling) | Kl. 6.1, Annex A 5.24–5.28 | Incident-Management |
| Abs. 2 lit. c (BCM) | Annex A 5.29–5.30 | BCM fuer IS |
| Abs. 2 lit. d (Supply Chain) | Annex A 5.19–5.22 | ICT-Supply-Chain |
| Abs. 2 lit. e (Security Acquisition) | Annex A 5.23 | IS Cloud-Dienste |
| Abs. 2 lit. f (Schwachstellen) | Annex A 8.8 | Schwachstellenmanagement |
| Abs. 2 lit. g (Assessment-Effektivitaet) | Kl. 9.1 | Performance-Evaluation |
| Abs. 2 lit. h (Krypto) | Annex A 8.24 | Kryptographie |
| Abs. 2 lit. i (HR-Sicherheit) | Annex A 6.1–6.5 | HR-Sicherheit |
| Abs. 2 lit. j (Schulung) | Annex A 6.3 | IS-Awareness |

Seed-Befehl: `src/Command/SeedNis2Iso27001MappingsCommand.php`

---

## 8. Policy-Templates (NIS2)

12 Pflicht-Richtlinien nach Art. 21 (Massnahmen) und Art. 23 (Meldepflicht).

Datei: `src/Command/SeedNis2PolicyTemplatesCommand.php`

| Nr. | Policy-Thema | NIS2-Referenz |
|---|---|---|
| 1 | IS-Policy / Leitlinie | Art. 21 Abs. 2 lit. a |
| 2 | Risikomanagement | Art. 21 Abs. 2 lit. a |
| 3 | Incident-Response | Art. 21 Abs. 2 lit. b |
| 4 | Business Continuity | Art. 21 Abs. 2 lit. c |
| 5 | Supply-Chain-Sicherheit | Art. 21 Abs. 2 lit. d |
| 6 | Sicherheit in Beschaffung | Art. 21 Abs. 2 lit. e |
| 7 | Schwachstellenmanagement | Art. 21 Abs. 2 lit. f |
| 8 | Effektivitaetsmessung | Art. 21 Abs. 2 lit. g |
| 9 | Kryptographie | Art. 21 Abs. 2 lit. h |
| 10 | HR-Sicherheit + Schulung | Art. 21 Abs. 2 lit. i/j |
| 11 | Zugangskontrolle | Art. 21 Abs. 2 lit. i |
| 12 | Meldeverfahren BSI/Aufsicht | Art. 23 NIS2 + NIS2UmsuCG |

---

## 9. Workflow — Incident Response (NIS2-relevant)

Art. 23 NIS2 erfordert gestufte Meldung erheblicher Vorfaelle. Der Workflow
`incident-high` deckt High/Critical-Vorfaelle ab:

```bash
php bin/console app:generate-regulatory-workflows --workflow=incident-high
```

Details: siehe `docs/DORA.md` Abschnitt 5 (gleicher Workflow, NIS2-Fristen gelten
wenn kein DORA-Scope vorliegt).

Fuer Low/Medium-Vorfaelle:

```bash
php bin/console app:generate-regulatory-workflows --workflow=incident-low
```

---

## 10. NIS2-Meldung via BSI-Notifikationskommando

```bash
# NIS2-Meldeprozess starten
php bin/console app:nis2-notification [incident-id]
```

Datei: `src/Command/Nis2NotificationCommand.php`

---

## 11. Modul-Aktivierung

```yaml
# config/active_modules.yaml
nis2_dora: true
```

Twig:

```twig
{% if is_module_active('nis2_dora') %}
    {# NIS2-Felder und Menue sichtbar #}
{% endif %}
```

Compliance-Wizard: `/{locale}/compliance/wizard/nis2`

---

## 12. Referenzen

| Norm | Artikel | Implementierung |
|---|---|---|
| NIS2 (EU) 2022/2555 | Art. 3 (Einrichtungskategorien) | Reifegrad Baseline/Enhanced |
| NIS2 | Art. 4 Abs. 1 (Lex specialis) | DORA-Vorrang fuer Finanzsektor |
| NIS2 | Art. 21 Abs. 2 (Massnahmen) | 12 Policy-Templates |
| NIS2 | Art. 23 Abs. 4 (Meldepflicht) | BSI-MUS-Export, `IncidentSlaConfig` |
| NIS2UmsuCG | vollstaendig | `LoadNis2UmsuCGFullCommand` |
| ENISA Implementation Guidance | — | Identifier-Schema, Anforderungsformulierung |
| BSI C5:2026 | — | Cross-Mapping via `SeedC52026Iso27001MappingsCommand` |
| ISO 27001:2022 | Annex A | `SeedNis2Iso27001MappingsCommand` |
| EU AI Act (EU) 2024/1689 | Art. 9 | `Asset::$aiActClassification` |
| EU CRA (EU) 2024/2847 | Art. 13–14 | `WizardSession::WIZARD_CRA` |
