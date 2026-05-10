# DORA — Digital Operational Resilience Act (EU) 2022/2554

Stand: v3.5 (2026-05). Gilt fuer das Modul `nis2_dora` (Aktivierungskey in `config/modules.yaml`).

> **Hinweis:** VAIT, BAIT, KAIT und ZAIT sind seit dem 17. Januar 2025 durch DORA
> als sektorale Aufsichtsvorschriften abgeloest. Das Tool implementiert keine
> separaten VAIT/BAIT/KAIT/ZAIT-Wizards. Fuer Finanzinstitute unter BaFin-Aufsicht
> gilt DORA als lex specialis.

---

## 1. Ueberblick

DORA (Verordnung (EU) 2022/2554, gueltig ab 17. Januar 2025) staerkt die digitale
operationelle Resilienz des EU-Finanzsektors. Das Tool implementiert DORA auf zwei Ebenen:

- **Level 1:** DORA-Verordnung (Art. 1–64) — Kernanforderungen
- **Level 2:** Technische Regulierungsstandards (RTS) und Durchfuehrungsstandards (ITS)
  der ESAs (EBA, EIOPA, ESMA) — 131 granulare Anforderungen

---

## 2. Catalogue-Laden (Commands)

```bash
# DORA Level-1 Kernanforderungen
php bin/console app:load-dora-requirements

# DORA Level-1 vollstaendiger Katalog (inkl. Anhang)
php bin/console app:load-dora-full

# DORA Level-2 RTS/ITS/CIR-Katalog (131 Anforderungen)
php bin/console app:load-dora-rts-its-full

# Seed DORA ↔ ISO 27001:2022 Mappings
php bin/console app:seed-dora-iso27001-mappings

# Seed DORA Policy-Templates
php bin/console app:seed-dora-policy-templates
```

---

## 3. DORA Level-2 RTS/ITS Katalog

Datei: `src/Command/LoadDoraRtsItsFullCommand.php`

### 3.1 Identifier-Schema

| Praefx | Regelwerk | Quelle |
|---|---|---|
| `RTS-ICT-RMF-Art.X` | RTS ICT Risk Management Framework | JC 2023/86 |
| `RTS-ICT-RMF-SIMPL-Art.X` | RTS vereinfachtes ICT-RMF (Art. 16 DORA) | JC 2023/86 |
| `RTS-INC-CLASS-Art.X` | RTS Klassifizierung Hauptvorfaelle | CDR 2024/1772 |
| `RTS-INC-REPORT-Art.X` | RTS Meldeinhalte/Templates | CDR 2024/1774 |
| `ITS-INC-REPORT-Art.X` | ITS Meldetemplates | CIR (EU) 2024/2955 |
| `ITS-Register-B.XX.YY` | ITS Register of Information Templates | CIR (EU) 2024/2956 |
| `RTS-Subcontracting-Art.X` | RTS Subcontracting | CDR 2025/532 |
| `RTS-TLPT-Art.X` | RTS Threat-Led Penetration Testing | JC 2024 |
| `RTS-Oversight-Art.X` | RTS Oversight-Harmonisierung | JC 2024 |

### 3.2 Wichtige Quellen

- JC 2023/86 — Final Report RTS ICT Risk Management Framework (EBA/EIOPA/ESMA)
- CIR (EU) 2024/1772 — RTS Klassifizierung ICT-Hauptvorfaelle (Art. 18 DORA)
- CIR (EU) 2024/1774 — RTS Meldeinhalt ICT-Vorfaelle (Art. 20 DORA)
- CIR (EU) 2024/2955 — ITS Meldetemplates (Art. 20 DORA)
- CIR (EU) 2024/2956 — ITS Register of Information (Art. 28 Abs. 9 DORA)
- CDR (EU) 2025/532 — RTS Subcontracting (Art. 30 Abs. 5 DORA)

---

## 4. ICT-Incident-Felder an `Incident` (Art. 17–19 DORA)

Datei: `src/Entity/Incident.php`

Alle Felder sind hinter dem Modul `nis2_dora` gesperrt (T31.2.2).

### 4.1 Klassifizierungs-Felder (Art. 18 DORA)

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `ictIncidentClassification` | ?string | Art. 18 (Klassifizierung Major/Minor) |
| `doraClassification` | ?string | CIR 2024/1772 (Major ICT Incident) |
| `doraClientsImpacted` | ?int | Art. 18 Abs. 1 lit. a (Betroffene Kunden) |
| `doraReputationImpact` | ?string | Art. 18 Abs. 1 lit. b (Reputationsschaden) |
| `doraServiceDowntimeMinutes` | ?int | Art. 18 Abs. 1 lit. c (Ausfallzeit) |
| `doraGeographicalSpread` | ?array | Art. 18 Abs. 1 lit. d (Geografische Ausbreitung) |
| `doraDataLossOccurred` | ?bool | Art. 18 Abs. 1 lit. e (Datenverlust) |
| `doraEconomicImpactEur` | ?int | Art. 18 Abs. 1 lit. f (Wirtschaftlicher Schaden EUR) |

### 4.2 Melde-Fristen (Art. 19 DORA)

| Frist | DORA-Referenz | SLA-Entitaet |
|---|---|---|
| 4 Stunden | Art. 19 Abs. 4 lit. a (Erstmeldung) | `IncidentSlaConfig` |
| 24 Stunden | Art. 19 Abs. 4 lit. b (Zwischenmeldung) | `IncidentSlaConfig` |
| 1 Monat | Art. 19 Abs. 4 lit. c (Schlussmeldung) | `IncidentSlaConfig` |

Gegenueber NIS2: DORA Art. 19 Abs. 4 lit. a (4h) ist scharfer als NIS2 Art. 23 (24h).
Bei Ueberschneidung gilt DORA als lex specialis gemaess Art. 4 Abs. 1 NIS2-RL.

---

## 5. Workflow — Incident Response (High/Critical)

Out-of-the-Box-Workflow, generiert ueber:

```bash
php bin/console app:generate-regulatory-workflows --workflow=incident-high
```

Normative Grundlage: DORA Art. 17–19, ISO 27001:2022 Kl. 6.1/8.1.

**6 Schritte:**

| Schritt | Verantwortlich | DORA-Referenz |
|---|---|---|
| 1. CISO-Response | CISO | Art. 17 Abs. 1 |
| 2. ICT-Klassifizierung | CISO + IT | Art. 18 |
| 3. Erstmeldung Aufsicht | CISO/DPO | Art. 19 Abs. 4 lit. a (4h) |
| 4. Eindaemmung + Analyse | IT + CISO | Art. 17 Abs. 3 |
| 5. Zwischenmeldung | CISO | Art. 19 Abs. 4 lit. b (24h) |
| 6. Post-Incident-Review | CISO + Management | Art. 17 Abs. 7 + Schlussmeldung 1M |

---

## 6. Cross-Mapping DORA ↔ NIS2

Norm-Grundlage: Art. 4 Abs. 1 NIS2-RL (Richtlinie (EU) 2022/2555) — DORA als lex specialis.

Finanzunternehmen im Sinne des DORA, die gleichzeitig als wesentliche oder wichtige
Einrichtungen nach NIS2 gelten, erfuellen NIS2-Pflichten durch DORA-Konformitaet
(sofern Anforderungen mindestens gleichwertig).

| NIS2 Art. | DORA Aequivalent | Bemerkung |
|---|---|---|
| Art. 21 Abs. 2 lit. a (Policies) | Art. 5–16 (ICT-RMF) | DORA detaillierter |
| Art. 21 Abs. 2 lit. b (Incident Handling) | Art. 17–23 | DORA-Fristen schaerfer |
| Art. 21 Abs. 2 lit. c (BCM) | Art. 11–12 | DORA: RCBC + DR |
| Art. 21 Abs. 2 lit. d (Supply Chain) | Art. 28–44 | DORA: Third-Party-Risk-Framework |
| Art. 21 Abs. 2 lit. e (Security in Acquisition) | Art. 9 | Konnex |
| Art. 21 Abs. 2 lit. h (Krypto) | Art. 9 Abs. 2 | Konnex |
| Art. 23 (Meldepflicht) | Art. 19–20 | DORA-Fristen: 4h/24h/1M |

---

## 7. Cross-Mapping DORA ↔ ISO 42001 v2

DORA adressiert KI-Systeme im Kontext des ICT-Risikomanagements implizit.
ISO/IEC 42001:2023 liefert das KI-Managementsystem-Rahmenwerk.

| DORA | ISO 42001 | Thema |
|---|---|---|
| Art. 6 (ICT-Risikorahmen) | Kl. 6.1.2 (AI Risk Assessment) | KI als ICT-Asset im Risikorahmen |
| Art. 8 (ICT-Schutz) | Annex A 6.1 (AI System Impact Assessment) | Schutz KI-Inferenz-Infrastruktur |
| Art. 9 (Erkennung) | Annex A 8.4 (Monitoring of AI systems) | KI-Verhaltensmonitoring |
| Art. 28 (Third-Party) | Annex A 5.3 (AI Supply Chain) | KI-Zulieferer und Modell-Anbieter |

Implementierung: `src/Command/LoadIso42001FullCommand.php`, `src/Entity/Asset.php`
(Felder `aiAgentType`, `aiRiskLevel`, `aiActClassification`).

---

## 8. Policy-Templates (DORA)

Generiert ueber `src/Command/SeedDoraPolicyTemplatesCommand.php`.

---

## 9. Register of Information Export

DORA Art. 28 Abs. 3 lit. a verlangt ein Register der IKT-Drittdienstleister.

```
src/Controller/DoraRegisterExportController.php
src/Service/Export/DoraRegisterOfInformationExporter.php
```

Export: XLSX/CSV/PDF nach ITS-Vorlage CIR (EU) 2024/2956.

---

## 10. Compliance-Wizard

Route: `/{locale}/compliance/wizard/dora`

Datei: `src/Service/ComplianceWizard/Check/PolicyWizard/Dora/`

Durchgefuehrte Checks:

| Check-Klasse | DORA-Referenz |
|---|---|
| `DoraIctRiskFrameworkPresentCheck` | Art. 5–16 |
| `DoraThirdPartyRegisterMaintainedCheck` | Art. 28 Abs. 3 |
| `DoraValidityFromCheck` | Art. 2 (Geltungsbereich) |
| `DoraExtensionCoverageCheck` | Level-2 Abdeckung |
| `DoraTlptCadenceCheck` | Art. 26 (TLPT-Kadenz) |
| `DoraIncidentReportingDeadlinesCheck` | Art. 19 Abs. 4 |

---

## 11. Modul-Aktivierung

```yaml
# config/active_modules.yaml
nis2_dora: true
```

Controller-Pattern:

```php
if ($redirect = $this->checkModuleActive('nis2_dora')) return $redirect;
```

Twig:

```twig
{% if is_module_active('nis2_dora') %}
    {# DORA-Felder sichtbar #}
{% endif %}
```

---

## 12. Referenzen

| Norm | Artikel | Implementierung |
|---|---|---|
| DORA (EU) 2022/2554 | Art. 5–16 | ICT-RMF, ComplianceWizard |
| DORA | Art. 17–19 | `Incident::$doraClassification` usw. |
| DORA | Art. 18 | `Incident::$ictIncidentClassification` |
| DORA | Art. 19 Abs. 4 | `IncidentSlaConfig` (4h/24h/1M) |
| DORA | Art. 28 Abs. 3 | `DoraRegisterOfInformationExporter` |
| CIR (EU) 2024/1772 | Art. 18-Klassifizierung | `LoadDoraRtsItsFullCommand` |
| CIR (EU) 2024/2956 | ITS Register-Templates | `DoraRegisterExportController` |
| NIS2 (EU) 2022/2555 | Art. 4 Abs. 1 | Lex-specialis-Regel |
| ISO/IEC 42001:2023 | Annex A | `Asset::$aiRiskLevel` |
