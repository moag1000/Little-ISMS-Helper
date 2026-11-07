# BSI — IT-Grundschutz, Grundschutz++ und C5

Stand: v3.5 (2026-05), Grundschutz++ ergänzt 2026-10. Betrifft die Module `bsi_grundschutz` und `bsi_c5` (`config/modules.yaml`).

> **MRIS-Hinweis:** MRIS (Mythos-Resistant Information Security, Custom-Framework v1.5)
> ist ein internes Bewertungsrahmenwerk des Tools und hat KEINEN Bezug zu MaRisk
> (Mindestanforderungen an das Risikomanagement der Banken, BaFin). Beide Abkuerzungen
> werden im Tool streng unterschieden.

---

## 1. Ueberblick

Das Tool implementiert drei BSI-Produkte:

| Produkt | Version | Scope |
|---|---|---|
| **BSI IT-Grundschutz-Kompendium** | Edition 2023 | 10 Schichten, 117 Bausteine, ~360 Anforderungen |
| **BSI Grundschutz++ (BSI-Standard 300-1)** | Anwenderkatalog 2026-10 | 1000 Anforderungen (Methodik, Risikomanagement, Stand-der-Technik-Kernel), siehe § 14 |
| **BSI C5 Cloud Computing Compliance Criteria Catalogue** | 2020 + 2026 | Cloud-Sicherheit, 17 Domanen |

---

## 2. BSI IT-Grundschutz-Kompendium 2023

### 2.1 Canonical Catalogue Tree — 10 Schichten, 117 Bausteine

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

### 2.3 IND-Schicht — Erweiterung auf 117 Bausteine

Mit v3.5 wurde die Coverage von 42 auf 106 Bausteine erweitert. Mit der
Praktiker-Audit-Erweiterung 2026-05 wurde die Coverage auf 117 Bausteine
ausgebaut (CON.4/CON.5, OPS.1.1.7/1.2.1/1.2.3/3.1/3.2, DER.2.3, APP.3.3,
SYS.3.3, INF.11). Die IND-Schicht (Industrielle IT) ist vollstaendig abgebildet:

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

Dokumentation: `docs/archive/MRIS_INTEGRATION_PLAN.md`, `docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md`

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

## 14. Grundschutz++ (BSI-Standard 300-1)

Der BSI-Standard 300-1 „Methodik Grundschutz++" (veröffentlicht 2026-10-26) löst das
IT-Grundschutz-Kompendium ab: prozessorientiert, ab 2027 zertifizierbar, kompatibel zu
ISO/IEC 27001. Die Anforderungen stehen im **Anwenderkatalog Grundschutz++**, den das
BSI als OSCAL in der [Stand-der-Technik-Bibliothek](https://github.com/BSI-Bund/Stand-der-Technik-Bibliothek)
veröffentlicht (CC BY-SA 4.0).

| Was | Wo |
|---|---|
| Framework-Code | `BSI-GSPP` (bedingt anwendbar, Admin → Compliance → Frameworks) |
| Loader | `php bin/console app:load-bsi-grundschutz-plusplus [-u]` (`LoadBsiGrundschutzPlusPlusCommand`) |
| Katalog | `fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json` |
| Generator | `python3 scripts/library/build_bsi_grundschutz_plusplus.py <Klon der Stand-der-Technik-Bibliothek>` |

Der Generator erzeugt Katalog **und** Mappings aus einem festen Commit der BSI-Bibliothek;
die erzeugten Dateien nicht von Hand ändern, sondern neu generieren.

**Aufbau.** 95 Methodik-Anforderungen (Praktik GC = Governance und Compliance usw.),
4 Anforderungen zum Risikomanagement, 901 Kernel-Anforderungen. Jede verschachtelte
Anforderung hat ein eigenes Modalverb und zählt deshalb als eigene Zeile im
Erfüllungsgrad (keine `parentRequirement`; der OSCAL-Parent steht in
`dataSourceMapping.gspp.parent`).

**Stufen.** `absicherungsStufe` folgt dem Sicherheitsniveau: `basis` = MUSS auf Niveau
„normal (Stand der Technik)", `standard` = SOLLTE/KANN auf Niveau normal, `hoch` =
Niveau „erhöht". Priorität: MUSS mit Aufwandsstufe 0 → critical, MUSS → high,
SOLLTE → medium, sonst low.

**Datenwiederverwendung.** Die BSI-Zielobjektkategorien werden auf die Asset-Typen der
App abgebildet (`dataSourceMapping.asset_types`: IT-Systeme/Netze → Hardware,
Anwendungen → Software, Informationen → Information, Einkäufe → Service,
Mitarbeitende → Personnel, Standorte → Physical). Die Praktiken DET/REA setzen
`incident_management`, NOT setzt `bcm_required`, PERF setzt `audit_evidence`. Damit
greift die Data-Reuse-Analyse (`ComplianceMappingService::getDataReuseAnalysis`) für
GS++ genauso wie für die anderen Frameworks.

**Mappings** (`fixtures/library/mappings/`, Import mit `app:mapping:library:import`):

| Datei | Quelle | Paare |
|---|---|---|
| `iso27001-2022_to_bsi-gspp_official_v1.yaml` + Gegenrichtung | amtliches BSI-Mapping Annex A → GS++ (1.0.1-qa), alle 93 Controls | 362 |
| `iso27001-2022-clauses_to_bsi-gspp_v1.yaml` + Gegenrichtung | Expertenmapping Klauseln 4–10 → GS++-Methodik | 28 / 81 |
| `bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml` | BSI-Mapping Kompendium 2023 → GS++ (beim BSI noch **Entwurf**), von Teilanforderungen auf Anforderungen verdichtet | 1026 |
| `bsi-it-grundschutz_to_bsi-gspp_derived_v1.yaml` | Ergänzung für die Kompendium-Anforderungen ohne BSI-Mapping, zwei unabhängige Reviews plus Obergutachten bei Uneinigkeit (siehe unten) | 1012 |
| `{nis2-art21,bsi-c5-2020,bsi-c5-2026,dora,tisax}_to_bsi-gspp_derived_v1.yaml` + Gegenrichtung | über ISO 27001 abgeleitet (siehe unten) | 159 / 928 / 559 / 176 / 265 |

**Über ISO abgeleitete Mappings.** Für NIS2, C5, DORA und TISAX gibt es kein amtliches
Mapping auf Grundschutz++. `python3 scripts/library/derive_gspp_pivot_mappings.py` verkettet
das gepflegte Mapping des Frameworks auf ISO 27001 mit dem amtlichen BSI-Mapping
ISO 27001 → GS++. Glieder mit `related` oder `low` entfallen, die Konfidenz ist höchstens
`medium`, die Begründung nennt die ISO-Controls des Pfads. Die Provenienz
`derived_iso27001_pivot` stuft die Paare als heuristisch ein: Die Vererbung schlägt sie zur
Prüfung vor und übernimmt sie nicht ungeprüft. Nach Änderungen an einem der Eingangs-Mappings
das Skript erneut laufen lassen.

**Ergänzung Kompendium → GS++.** Das BSI-Mapping deckt 510 der 1834 Kompendium-Anforderungen ab
(Stand der BSI-Bibliothek geprüft am 2026-11-07, Commit `c3f3785`: Mapping unverändert seit 2026-07-21).
Für die übrigen 1324 schlägt `python3 scripts/library/derive_itgs_gspp_review.py candidates <dir>` je
Anforderung bis zu zehn GS++-Kandidaten vor: Ziele, die das BSI anderen Anforderungen desselben Bausteins
gibt, der amtliche Pfad über ISO 27001 (BSI-CRT und BSI-Mapping ISO → GS++) und Textähnlichkeit. Zwei
unabhängige Reviews (BSI-Grundschutz-Berater, externer Auditor) wählen 0–3 Ziele oder halten fest, dass
GS++ keine Entsprechung hat; `assemble` legt beide in
`panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json` ab. Wo sich die beiden nicht einig sind
(246 Anforderungen), schreibt `disputes <dir>` die Fälle mit beiden Reviews heraus, und ein drittes Review
(Obergutachten, ISMS-Zertifizierungsauditor) entscheidet: Es darf nur Ziele bestätigen, die A oder B gewählt
haben, oder festhalten, dass GS++ keine Entsprechung hat (dann mit Begründung). `adjudicate <dir> <datum>`
prüft diese Regeln und trägt das Obergutachten als Review C in die Fixture ein. `build` übernimmt Paare, die
beide Reviews gewählt oder das Obergutachten bestätigt hat; die Begründung im Mapping nennt, welcher Weg es war.
Provenienz `derived_expert_review` = heuristische Stufe: Die Vererbungs-Queue schlägt die Paare zur Prüfung
vor, nichts gilt ungeprüft als erfüllt. Sobald das BSI eine Anforderung selbst mappt, fällt sie beim nächsten
Lauf aus der Ergänzung. `stats` zeigt den Stand:

| Kompendium-Anforderungen | Anzahl |
|---|---|
| mit BSI-Mapping | 510 (27,8 %) |
| Ergänzung, zur Prüfung (1012 Paare; 311 davon aus dem Obergutachten) | 839 |
| ohne Entsprechung in GS++ | 485 |
| offen | 0 |
| **mit GS++-Ziel** | **1349 (73,6 %)** |

„Ohne Entsprechung“ betrifft vor allem produktspezifische Anforderungen (z. B. Samba, z/OS, DNS-Produktauswahl,
WLAN-Standards), die der Anwenderkatalog nicht als eigene Pflicht führt. Die Paare aus dem Obergutachten sind
überwiegend `partial_overlap`: Produktspezifische Kompendium-Anforderungen (SAP, Exchange, Zeitsynchronisation
einzelner Systeme) treffen in GS++ meist nur eine allgemeine Pflicht wie Grundkonfiguration (KONF.10.1) oder
autorisierte Zeitquellen (KONF.4.5). Sie belegen diese Pflicht teilweise; die Prüfung in der Vererbungs-Queue
entscheidet, ob der Nachweis reicht.

Nach einem Update holt der Import der Datei `bsi-it-grundschutz_to_bsi-gspp_derived_v1.yaml` die neuen
Paare nach (vorhandene Paare werden aktualisiert, nicht verdoppelt); erst danach zeigen Mapping-Hub und KPI den
neuen Stand.

**Im Tool.** Der Mapping-Hub (Übersicht) zeigt die Karte „Kompendium → Grundschutz++“ mit BSI-Mapping,
Ergänzung und „ohne Entsprechung“ (die Zeile „Offen“ erscheint nur, solange etwas offen ist), dazu „Vorschläge prüfen“ (Vererbungs-Queue `BSI-GSPP`). Der
`KpiCatalog` liefert im Abschnitt `bsi_gspp` (Modul `bsi_grundschutz`) zwei Kennzahlen:
`bsi_gspp_transfer` = Anteil der erfüllten Kompendium-Anforderungen der Organisation mit GS++-Ziel,
`bsi_gspp_mapping_coverage` = Anteil aller Kompendium-Anforderungen mit GS++-Ziel
(`App\Service\Bsi\KompendiumGsppCoverage`). Import wie die anderen Mappings über
`app:mapping:library:import` bzw. die Mapping-Bibliothek in der Oberfläche.

Ziel-IDs, die das BSI seit Erstellung eines Mappings umnummeriert hat, löst der
Generator über die stabile OSCAL-UUID auf. Wer ISO 27001 umgesetzt hat, sieht über die
Vererbungs-Queue, welche GS++-Anforderungen dadurch schon belegt sind; Kompendium-Anwender
übertragen ihren Stand über das Kompendium-Mapping.

**ISO→BSI-Gap-Ansicht.** `/compliance/cross-gap?target=gspp` zeigt die Gap-Analyse
gegen Grundschutz++ statt gegen das Kompendium (Umschalter oben auf der Seite, sobald
beide Kataloge geladen sind).

**Compliance-Wizard** (`/compliance-wizard/bsi_gspp`). 14 Prüfbereiche entlang der Praktiken
des Anwenderkatalogs. Die Methodik-Praktiken (GC, STM, RISK, UMS, PERF, VRB) werden aus den
ISMS-Daten bewertet: Kontext, Assets, Risiken, Behandlungspläne, Audits und Managementbewertung.
Die Kernel-Praktiken (ASST, BER, ARCH, KONF, DEV, TEST, PERS, SENS, BES, DLS, DET, REA, NOT, GEB)
zählen den SoA-Stand der ISO-Controls, die das amtliche BSI-Mapping der jeweiligen Praktik
zuordnet. Wer ISO 27001 betreibt, sieht seinen GS++-Stand also ohne neue Eingaben. Den
Erfüllungsgrad je Anforderung zeigt der Wizard getrennt davon als Katalogabdeckung von `BSI-GSPP`.

**Policy-Wizard** (Standard „BSI Grundschutz++“). Jede der 25 ISO-27001-Richtlinien erhält
einen Abschnitt „Ergänzung Grundschutz++“ mit den Anforderungen, die das BSI ihren
Annex-A-Controls zuordnet. Die Tabelle nennt ID, Titel und Stufe. Die Leitlinie erhält
zusätzlich die Anforderungen zu den ISO-Klauseln 4.3, 5.1–5.3 und 6.2 sowie den Verweis auf
die Methodik nach 300-1. Der Compliance-Wizard-Check `gspp_policy_coverage` prüft, ob alle
diese Richtlinien freigegeben oder veröffentlicht sind und mit Grundschutz++ erzeugt wurden
(Tag `gspp-extension:applied`).

Datengrundlage beider Wizards ist `reuse_map.json` im Katalogordner, die Abschnittstexte
stehen in `translations/policy_gspp.{de,en}.yaml`. Beide erzeugt
`python3 scripts/library/build_gspp_policy_sections.py` aus Katalog und Mappings.
`GsppSectionCatalogueTest` schlägt fehl, sobald sie vom BSI-Mapping abweichen. Nach einem
Katalog- oder Mapping-Update das Skript erneut laufen lassen.

Die Ergänzungsabschnitte aller anderen Standards (IT-Grundschutz, C5, NIS2, TISAX, SOC 2,
ISO 27701, BCM) laufen seit dieser Änderung über denselben Weg
(`SectionExtensionRegistry::appendBodyExtensions`). Vorher flossen sie nur in die
Abdeckungsberechnung ein, im erzeugten Dokument fehlten sie.

---

## 15. Referenzen

| Norm | Version | Implementierung |
|---|---|---|
| BSI IT-Grundschutz-Kompendium | Edition 2023 | `LoadBsiItGrundschutzCatalogueCommand` |
| BSI-Standard 300-1 (Grundschutz++) | Anwenderkatalog 2026-10 | `LoadBsiGrundschutzPlusPlusCommand`, Compliance-Wizard `bsi_gspp`, `GsppSectionCatalogue` (§ 14) |
| BSI-Standard 200-1 | — | ISMS-Konzept (`BsiIsmsConceptPresentCheck`) |
| BSI-Standard 200-2 | Kap. 3.6 | `AssetDependencyService` (Schutzbedarfsvererbung) |
| BSI-Standard 200-3 | — | Risikoanalyse (Schutzbedarfsfelder) |
| BSI C5:2020 | — | `LoadC52020FullCatalogueCommand` |
| BSI C5:2026 | — | `LoadC52026FullCatalogueCommand`, `SeedC52026Iso27001MappingsCommand` |
| ISO 27001:2022 | Annex A | Gemeinsamer Pivot fuer C5-und-NIS2-Cross-Mappings |
| NIS2 (EU) 2022/2555 | Art. 21 | Cross-Mapping via ISO-Pivot |
