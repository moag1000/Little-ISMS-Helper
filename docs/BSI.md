# BSI — IT-Grundschutz und C5

Stand: v3.5 (2026-05). Betrifft die Module `bsi_grundschutz` und `bsi_c5` (`config/modules.yaml`).

> **MRIS-Hinweis:** MRIS (Mythos-Resistant Information Security, Custom-Framework v1.5)
> ist ein internes Bewertungsrahmenwerk des Tools und hat KEINEN Bezug zu MaRisk
> (Mindestanforderungen an das Risikomanagement der Banken, BaFin). Beide Abkuerzungen
> werden im Tool streng unterschieden.

---

## 1. Ueberblick

Das Tool implementiert zwei BSI-Produkte:

| Produkt | Version | Scope |
|---|---|---|
| **BSI IT-Grundschutz-Kompendium** | Edition 2023 | 10 Schichten, 106 Bausteine, ~324 Anforderungen |
| **BSI C5 Cloud Computing Compliance Criteria Catalogue** | 2020 + 2026 | Cloud-Sicherheit, 17 Domanen |

---

## 2. BSI IT-Grundschutz-Kompendium 2023

### 2.1 Canonical Catalogue Tree — 10 Schichten, 106 Bausteine

Ladebefehl (einheitlicher Command, ersetzt alle frueheren einzelnen Loader):

```bash
php bin/console app:load-bsi-it-grundschutz-catalogue [--layer=SCHICHT]
```

Datei: `src/Command/LoadBsiItGrundschutzCatalogueCommand.php`

Die 5 alten Loader-Kommandos sind deprecated. Der neue Single-Loader laedt alle 10
Schichten sequenziell oder eine einzelne Schicht via `--layer`-Option.

**10 Schichten (LAYERS) — Canonical-Identifiers:**

| Schicht | Name | Beispiel-Bausteine |
|---|---|---|
| `ISMS` | Sicherheitsmanagement | ISMS.1 |
| `ORP` | Organisation und Personal | ORP.1, ORP.2, ORP.3, ORP.4, ORP.5 |
| `CON` | Konzepte und Vorgehensweisen | CON.1..CON.11 |
| `OPS` | Betrieb | OPS.1.1..OPS.2.2, OPS.3 |
| `DER` | Detektion und Reaktion | DER.1..DER.4 |
| `APP` | Anwendungen | APP.1.1..APP.7.1 |
| `SYS` | IT-Systeme | SYS.1.1..SYS.4.5 |
| `IND` | Industrielle IT | IND.1..IND.3.2 |
| `NET` | Netze und Kommunikation | NET.1.1..NET.4.3 |
| `INF` | Infrastruktur | INF.1..INF.14 |

### 2.2 Anforderungsstufen

Jeder Baustein gliedert sich in drei Stufen:

| Stufe | Kuerzel | Beschreibung |
|---|---|---|
| Basisabsicherung | B | Mindestanforderungen (MUSS) |
| Standardabsicherung | S | Standardanforderungen (SOLL) |
| Erhoehter Schutzbedarf | H | Anforderungen bei erhoehtem Schutzbedarf (SOLLTE) |

### 2.3 IND-Schicht — Erweiterung auf 106 Bausteine

Mit v3.5 wurde die Coverage von 42 auf 106 Bausteine erweitert. Die IND-Schicht
(Industrielle IT) ist vollstaendig abgebildet:

| Baustein | Titel |
|---|---|
| IND.1 | Prozessleit- und Automatisierungstechnik |
| IND.2.1 | Allgemeine ICS-Komponente |
| IND.2.2 | Speicherprogrammierbare Steuerung (SPS) |
| IND.2.3 | Sensoren und Aktoren |
| IND.2.4 | Maschinen |
| IND.2.7 | Safety Instrumented Systems |
| IND.3.2 | Fernwartung im industriellen Umfeld |

---

## 3. BSI Grundschutz-Wizard

Der Grundschutz-Wizard unterstuetzt zwei Varianten:

| Variante | Befehl | Anwendungsfall |
|---|---|---|
| Kern-Absicherung | `app:load-bsi-it-grundschutz-requirements --variant=kern` | Schnelleinstieg |
| Standard-Variante | `app:load-bsi-it-grundschutz-requirements --variant=standard` | Vollstaendige Umsetzung |

Wizard-Route: `/{locale}/compliance/wizard/bsi_grundschutz_kern` bzw.
`/{locale}/compliance/wizard/bsi_grundschutz_standard`

Dateien:
```
src/Command/LoadBsiItGrundschutzRequirementsCommand.php
src/Command/LoadBsiGrundschutzVariantsCommand.php
src/Service/BsiGrundschutzCheckService.php
```

---

## 4. BSI Grundschutz — Policy-Templates

8 Policy-Koerper (policy bodies) sind in v3.5 verfasst und seeded.

Ladebefehl: `php bin/console app:seed-bsi-policy-templates`

Datei: `src/Command/SeedBsiPolicyTemplatesCommand.php`

| Key | Thema |
|---|---|
| `bsi.awareness_policy` | ORP.3 — Sicherheitssensibilisierung und Schulung |
| `bsi.iam` | ORP.4 — Identitaets- und Berechtigungsmanagement |
| `bsi.deletion_policy` | CON.6 — Loeschen und Vernichten |
| `bsi.foreign_travel_policy` | CON.7 — Informationssicherheit auf Reisen |
| `bsi.cloud_usage_policy` | OPS.2.2 — Cloud-Nutzung |
| `bsi.detection_policy` | DER.1 — Detektion von sicherheitsrelevanten Ereignissen |
| `bsi.incident_response` | DER.2.1 — Behandlung von Sicherheitsvorfaellen |
| `bsi.emergency_management` | DER.4 — BCM — Notfallmanagement |

---

## 5. BSI C5:2020 — Cloud Computing Compliance Criteria Catalogue

Ladebefehl:

```bash
php bin/console app:load-c5-2020-full-catalogue
```

Datei: `src/Command/LoadC52020FullCatalogueCommand.php`

Der C5:2020-Katalog deckt 17 Domanen ab. Er bildet die Grundlage fuer
Cloud-Provider-Attestierungen gemaess BSI C5-Pruefschema.

---

## 6. BSI C5:2026 — Erweiterter Katalog

Ladebefehl:

```bash
php bin/console app:load-c5-2026-full-catalogue
```

Datei: `src/Command/LoadC52026FullCatalogueCommand.php`

C5:2026 erweitert C5:2020 insbesondere um:
- KI/ML-Sicherheit (Confidential Computing, Container-Security)
- Software Supply Chain Security (SCS)
- Aktualisierte Kryptographie-Anforderungen

### 6.1 Cross-Mapping C5:2020 ↔ C5:2026

Als Pilotimplementierung unterstuetzt das Tool ein direktes Cross-Mapping zwischen
C5:2020 und C5:2026, um Migrationsluecken sichtbar zu machen.

Datei: `src/Command/LoadC52026RequirementsCommand.php`

---

## 7. Cross-Mappings

### 7.1 BSI C5:2026 ↔ ISO 27001:2022

Seed-Befehl: `php bin/console app:seed-c5-2026-iso27001-mappings`

Datei: `src/Command/SeedC52026Iso27001MappingsCommand.php`

C5:2026 ist weitgehend cloud-nativ. Mappings referenzieren ISO/IEC 27001:2022 Annex A
direkt (C5-Controls die bereits ISO-Referenzen tragen) sowie thematisch verwandte Controls.

Beispiele:

| C5:2026 | ISO 27001:2022 Annex A | Bemerkung |
|---|---|---|
| OPS-09 (Sicherheitsvorfaelle) | A.5.24–A.5.28 | Incident-Management |
| COS-01..09 (Kryptographie) | A.8.24 | Kryptographiepolitik |
| SCS-01..06 (Supply Chain) | A.5.19–A.5.22 | ICT-Lieferkettenmanagement |
| DEV-01..10 (Entwicklung) | A.8.25–A.8.32 | Sichere Entwicklung |

### 7.2 BSI C5:2026 ↔ NIS2 Art. 21

| C5:2026 Domane | NIS2 Art. 21 Abs. 2 | Thema |
|---|---|---|
| OPS-01 | lit. a | Policies und Verantwortung |
| OPS-09 | lit. b | Incident Handling |
| OPS-18 | lit. c | BCM |
| PSS-01..08 | lit. d | Supply Chain |
| DEV-01..10 | lit. e | Security in Acquisition |
| OPS-14 | lit. f | Schwachstellenmanagement |
| COS-01..09 | lit. h | Kryptographie |

Cross-Mapping ableitbar aus: `SeedC52026Iso27001MappingsCommand` + `SeedNis2Iso27001MappingsCommand` (gemeinsamer ISO-27001-Pivot).

---

## 8. 15 zusaetzliche Bausteine — Policy-Templates (APP/SYS/NET/INF/IND)

Seit v3.5 sind 15 weitere Bausteine aus den Schichten APP, SYS, NET, INF und IND
mit Policy-Templates hinterlegt.

Ladebefehl: `php bin/console app:seed-bsi-extra-bausteine`

Datei: `src/Command/SeedBsiExtraBausteineCommand.php`

---

## 9. Schutzbedarfsvererbung (BSI 200-2 Kap. 3.6)

Das Tool implementiert das Maximumprinzip der Schutzbedarfsvererbung gemaess
BSI-Standard 200-2, Kapitel 3.6.

Abhaengigkeitsbeziehungen: `Asset.dependsOn` (ManyToMany, inverse: `Asset.dependents`)

Service: `src/Service/AssetDependencyService.php`

Aenderungen am Schutzbedarf eines Assets propagieren automatisch auf abhaengige Assets,
wenn der neue Schutzbedarf hoeher ist. Dies triggert auch eine DPIA-Pruefung
(Art. 35 DSGVO) wenn der Asset personenbezogene Daten verarbeitet.

---

## 10. Compliance-Wizard Checks (BSI)

Dateiverzeichnis: `src/Service/ComplianceWizard/Check/PolicyWizard/Bsi/`

| Check-Klasse | Pruefung |
|---|---|
| `BsiTierConsistencyCheck` | Konsistenz der Absicherungsstufe (Kern/Standard/Erhoehter Schutzbedarf) |
| `BsiBaselineCoverageCheck` | Abdeckung aller B-Anforderungen des gewaehlten Profils |
| `BsiSchutzbedarfMethodPresentCheck` | Schutzbedarfsfeststellung dokumentiert |
| `BsiIsmsConceptPresentCheck` | ISMS-Konzept vorhanden |
| `BsiKritisFlagDocumentedCheck` | KRITIS-Kennzeichnung dokumentiert |
| `BsiTopLevelLeitliniePresentCheck` | Leitlinie zur Informationssicherheit vorhanden |

---

## 11. MRIS — Mythos-Resistant Information Security (Custom-Framework v1.5)

MRIS ist ein internes Bewertungsrahmenwerk des Tools mit 13 Anforderungen.

**MRIS ist nicht MaRisk.** MaRisk (Mindestanforderungen an das Risikomanagement,
BaFin-RS 09/2017 i.d.F. 2023) ist ein eigenstaendiges BaFin-Rundschreiben fuer
das Bankrisikomanagement. Das Tool implementiert MRIS als ergaenzendes internes
Framework — keine MaRisk-Wizards.

Dokumentation: `docs/MRIS_INTEGRATION_PLAN.md`, `docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md`

Routen: `/dev/mris/*` (intern, nicht im Kundenmenue)

---

## 12. Modul-Aktivierung

```yaml
# config/active_modules.yaml
bsi_grundschutz: true
bsi_c5: true
```

Twig:

```twig
{% if is_module_active('bsi_grundschutz') %}
    {# BSI-Grundschutz-Bausteine sichtbar #}
{% endif %}
```

Compliance-Wizard-Routen:

- `/{locale}/compliance/wizard/bsi_grundschutz`
- `/{locale}/compliance/wizard/bsi_grundschutz_kern`
- `/{locale}/compliance/wizard/bsi_grundschutz_standard`
- `/{locale}/compliance/wizard/bsi_c5`
- `/{locale}/compliance/wizard/bsi_c5_2026`

---

## 13. Import BSI-Profile (XML)

```bash
php bin/console app:import-bsi-kompendium-xml [file.xml]
```

Dateien:
```
src/Command/ImportBsiKompendiumXmlCommand.php
src/Service/Import/BsiKompendiumXmlImporter.php
src/Service/Import/BsiProfileXmlImporter.php
```

GST-Importformat (GSTool-Export) wird ueber `docs/features/GSTOOL_IMPORT.md` dokumentiert.

---

## 14. Referenzen

| Norm | Version | Implementierung |
|---|---|---|
| BSI IT-Grundschutz-Kompendium | Edition 2023 | `LoadBsiItGrundschutzCatalogueCommand` |
| BSI-Standard 200-1 | — | ISMS-Konzept (`BsiIsmsConceptPresentCheck`) |
| BSI-Standard 200-2 | Kap. 3.6 | `AssetDependencyService` (Schutzbedarfsvererbung) |
| BSI-Standard 200-3 | — | Risikoanalyse (Schutzbedarfsfelder) |
| BSI C5:2020 | — | `LoadC52020FullCatalogueCommand` |
| BSI C5:2026 | — | `LoadC52026FullCatalogueCommand`, `SeedC52026Iso27001MappingsCommand` |
| ISO 27001:2022 | Annex A | Gemeinsamer Pivot fuer C5-und-NIS2-Cross-Mappings |
| NIS2 (EU) 2022/2555 | Art. 21 | Cross-Mapping via ISO-Pivot |
