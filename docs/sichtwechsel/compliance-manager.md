# Compliance-Manager-Sicht — Framework-Portfolio + Reuse

> **Wer:** Head of GRC oder Leiter IT-Compliance, 6–12 Jahre Erfahrung, dem CISO unterstellt, leitet 2–6-köpfiges Compliance-Team.
> **Denkweise:** Effizienz + Effektivität. Data-Reuse obsessiv. Frameworks als Venn-Diagramm, nicht als Stapel.
> **Frust-Trigger:** Jede Form von Dateneingabe-Redundanz. Framework-spezifische Silos ohne Brücken. Fehlende Mappings, wo der Markt sie hat.
>
> Volle Persona-Definition: [`.claude/skills/persona-compliance-manager`](../../.claude/skills/persona-compliance-manager/)

[← Zurück zur Übersicht](README.md)

---

## Compliance-Übersicht

Aktive Frameworks im Portfolio. ISO 27001:2022, NIS2, EU-DORA, GDPR, BSI IT-Grundschutz, BSI C5:2026, TISAX, MRIS — bei diesem Tenant 25+ Frameworks.

![Compliance-Übersicht](img/compliance-manager/compliance-overview.png)

> *"Wir sind 27001-zertifiziert und jetzt NIS2-pflichtig geworden. Welche NIS2-Art.-21-Anforderungen sind durch bestehende 27001-Controls bereits erfüllt?"*

Die Antwort liegt eine Klick-Ebene tiefer im Cross-Framework-Mapping.

---

## Cross-Framework-Mapping

Welche Anforderungen verschiedener Frameworks decken sich? Welche eines Framework lassen sich durch Controls aus einem anderen erfüllen?

![Cross-Framework](img/compliance-manager/compliance-cross-framework.png)

ISO-27001-Controls mappen zu NIS2-Art.-21-Anforderungen (~70% Überlappung), DORA-ICT-Risk (~60%), TISAX (~85%). Jede Erfüllungsrelation ist sichtbar — und lässt sich pro Mapping mit Quelle/Methodik begründen.

> *"Wenn ich NIS2 aktiviere, erwarte ich dass 27001-Controls automatisch als Belege vorgeschlagen werden — nicht dass ich 150 Mappings manuell erstelle."*

---

## Mapping-Hub

Mappings als eigenständige, versionierte Artefakte. Einsehbar, erweiterbar, importierbar — auch von externen Templates.

![Mapping-Hub](img/compliance-manager/mapping-hub.png)

Die Mapping-Library lebt unter [`fixtures/library/mappings/`](../../fixtures/library/mappings/) als YAML — git-versioniert, diff-fähig, community-PR-fähig. Beispiel: [`dora_to_nis2_lex-specialis_v2.0.yaml`](../../fixtures/library/mappings/) wurde aktualisiert, weil ENISA-Guidance 2024-09 erschien — der Diff zum v1 ist nachvollziehbar.

---

## Mapping-Wizard

Cross-Mappings nicht manuell, sondern wizard-gesteuert anlegen. Vorschläge auf Basis von Norm-Wortlaut und vorhandenen Controls.

![Mapping-Wizard](img/compliance-manager/mapping-wizard.png)

Senior-Consultant-Templates importierbar — der Compliance-Manager holt Methodik extern, setzt aber intern um.

---

## Compliance-Wizard

Geführtes Onboarding eines neuen Frameworks. Branchen-Preset wählen, Reuse-Vorschau, Gap-Bericht, Aktionsliste mit FTE-Schätzung.

![Compliance-Wizard](img/compliance-manager/compliance-wizard.png)

> *"Onboarding eines neuen Frameworks darf nicht > 20 FTE-Tage kosten, wenn 70 % Überlappung besteht."*

---

## Data-Reuse-Heatmap

Welche Daten werden wie oft framework-übergreifend wiederverwendet? Wo lohnt sich Investition in einen weiteren Mapping-Layer?

![Data-Reuse-Heatmap](img/compliance-manager/data-reuse-heatmap.png)

Reuse-Statistik als KPI: "Durch Wiederverwendung wurden X FTE-Tage bei NIS2-Onboarding eingespart." — die Effektivitäts-Messung, die der CFO sehen will.

---

## Was der Compliance-Manager hier nicht findet (und vermisst)

Aus der [Persona-Definition](../../.claude/skills/persona-compliance-manager/SKILL.md):

- **Vererbungs-Visualisierung** (transitive Abdeckung als Graph: A erfüllt B erfüllt C).
- **Auto-Vorschläge** beim Framework-Onboarding (heute: Wizard schlägt vor, aber nicht aggressiv genug).
- **Bulk-Operationen auf Framework-Ebene** ("alle NIS2-relevanten Controls als Pflicht markieren").
- **API-Export** für externe BI-Tools (Reuse-Statistik in Tableau).

→ Roadmap-Items aus Compliance-Manager-Sicht.

---

[← CISO-Sicht](ciso-executive.md) · [Übersicht](README.md) · [Nächste Persona: Junior-Implementer →](implementer-junior.md)
