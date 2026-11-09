# Compliance-Wizard — Benutzerhandbuch

Der Compliance-Wizard (`/de/compliance-wizard`, ab `ROLE_AUDITOR`) prüft je Framework, was die Daten der
Organisation schon belegen: Controls, Register, Leitungsentscheidungen, Schulungen, Fristen, Meldungen und
weitere Datensätze. Jede Prüfung nennt ihre Klausel oder ihren Artikel; dieselben Prüfungen speisen den
Erfüllungsgrad der Anforderungen, siehe [ERFUELLUNG_AUS_DATEN.md](ERFUELLUNG_AUS_DATEN.md).

## Verfügbare Wizards

ISO 27001:2022, NIS2, DORA, TISAX, DSGVO, ISO 22301, ISO 27701, ISO 27017, ISO 27018, ISO 42001,
BSI IT-Grundschutz (Basis-, Standard- und Kern-Absicherung), BSI Grundschutz++ (Standard 300-1),
BSI C5:2020, BSI C5:2026, NIST CSF 2.0, KRITIS / NIS2-DE-Umsetzung, PCI-DSS 4.0.1, SOC 2, EU AI Act,
ENISA EUCS, EU Cyber Resilience Act — 23 Wizards.

Ein Wizard erscheint nur, wenn seine Module aktiv sind (meist `controls`; NIS2 zusätzlich `incidents`,
DORA `bcm` und `incidents`, TISAX `assets`, ISO 22301 `bcm`). Die Übersicht nennt bei nicht verfügbaren
Wizards den Grund.

## Ablauf

1. **Start** (`/compliance-wizard/{wizard}`): Überblick, Hinweis auf passende Mapping-Bibliotheken
   („Datenübernahme“; Import ab `ROLE_MANAGER`).
2. **Bewertung** (`/{wizard}/assess`) und je Kategorie (`/{wizard}/category/{category}`): Ergebnis jeder
   Prüfung mit Link auf die fehlenden Daten.
3. **Manuelle Prüfungen** (Klauseln ohne Datensatz, z. B. ISO 27001 5.1): `ROLE_MANAGER` bestätigt sie;
   bestätigt zählen sie nicht mehr als Lücke.
4. **Gap-Report** (`/{wizard}/gap-report`, sortierbar nach Aufwand oder Quick Wins) mit FTE-Schätzung je
   Anforderung; eine eigene Schätzung braucht `ROLE_MANAGER`, eine Begründung (mind. 20 Zeichen) und landet im
   Audit-Log.
5. **PDF-Export** (`/{wizard}/export/pdf`, `ROLE_MANAGER`).

Das Ergebnis zeigt den Wizard-Score und die Katalogabdeckung („X von Y Anforderungen erfüllt“, aus den
Erfüllungen der Organisation).

## Stichtage, Verlauf und Vergleich

- **Snapshot** (`ROLE_MANAGER`): speichert Abdeckung, Lücken und Kennzahlen als `WizardSession` der
  Organisation (je Wizard ein eigener Verlauf).
- **Verlauf** (`/{wizard}/history`): alle Snapshots mit Trend.
- **Diff** (`/{wizard}/diff/{fromId}/{toId}`): zwei Snapshots nebeneinander; beide müssen zur eigenen
  Organisation gehören.
- **Framework-Vergleich** (`/compliance-wizard/compare`): mehrere Frameworks nebeneinander, als PDF
  (`/compare/export/pdf`, `ROLE_MANAGER`, höchstens 10 Frameworks).

## Cross-Framework-Mapping-Hub

Unter `/de/compliance/mapping/hub` (Menü: Compliance → Mapping-Hub) liegen alle Mapping-Werkzeuge hinter einer
Tab-Leiste:

| Tab | Inhalt |
|---|---|
| Übersicht | Kennzahlen, Einstiege, Data-Reuse-Heatmap, Mapping-Onboarding |
| Mappings | Liste, Detail, Anlegen/Bearbeiten, Wizard, Seed-Pakete, Import, Seed-Review |
| Qualität | Review-Queue, Kennzahlen, Lücken |
| Vergleich | Framework-Vergleich, Cross-Framework, Transitiv, Abdeckung (ab Auditor) |

Mappings durchlaufen `draft → review → approved → published` (`deprecated`, wiederherstellbar). Manager reichen
ein oder schicken zurück; freigeben, veröffentlichen und zurückziehen braucht `ROLE_ADMIN`. Globale Mappings
gelten für alle Organisationen der Instanz: mit mehr als einer Organisation ändert sie nur `ROLE_SUPER_ADMIN`.
Ein freigegebenes Mapping überträgt die Erfüllung einer Anforderung auf die gemappte (Quelle „Mapping“).

## Module

Wizards aktivieren keine Module. Module schaltet der Admin unter Admin → Module, siehe
[MODULE_AKTIVIERUNG.md](MODULE_AKTIVIERUNG.md).

## Verwandte Dokumente

- `docs/user-guide/ERFUELLUNG_AUS_DATEN.md` — welche Daten welche Klausel belegen
- `docs/user-guide/PERSONA_DASHBOARDS.md` — Rollenspezifische Dashboards
- `docs/MODULE_GATING_GUIDE.md` — Entwickler-Referenz für Module-Gating
- `docs/architecture/CROSS_FRAMEWORK_MAPPINGS.md` — Mapping-Architektur
