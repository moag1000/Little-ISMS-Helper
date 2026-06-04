# Feature-Katalog — verifizierter Ist-Stand (2026-06-04)

> Erneuert die Wettbewerbs-Analyse-Roadmap vom 2026-05-09. **Jeder Eintrag
> gegen den tatsächlichen Code geprüft** (Entity + Service + Controller/Route
> + UI/Command + Tests), nicht gegen Doku-Claims. Methode: 4 parallele
> Read-only-Verifikations-Pässe über `src/`, `templates/`, `migrations/`,
> `config/`, `translations/`, `tests/`.
>
> Quelle der Wunschliste: konsolidierte Feature-Requests aus offenen
> GRC-Open-Source-Projekten (keine Produktnamen — Konzept-Invariante #8).

**Status-Legende:** **DONE** = volle Kette verdrahtet + erreichbar · **PARTIAL**
= Kern da, benannte Lücke · **STUB** = nur Skeleton/Translation-Skeleton, nicht
verdrahtet · **ABSENT** = nicht gebaut.

## Verifizierter Status F1–F41

| F# | Feature | Status | Evidenz / Lücke |
|---|---|---|---|
| F1 | OIDC/OAuth2 SSO + LDAP | **DONE** | `IdentityProvider*` (3 Entities), `src/Service/Sso/*`, SSO-Wizard 3-Step, 6 Audit-Events, Tests. LDAP bewusst auf Sprint 2 deferred; JIT-Provisioning-Call-Site nicht 100% bestätigt. |
| F2 | CSV/XLSX Bulk-Import + Delta | **PARTIAL** | `BulkImportBatch/Row`, `src/Service/Import/*`, 6 Mapper, Wizard upload→map→preview→diff. **Lücke:** `commit.html.twig` (Step 5) fehlt; Error-CSV-Download unbestätigt. |
| F3 | Notification-Rules + Webhooks + SLA | **DONE** | `src/Entity/Notification/*`, `src/Service/Notification/*`, In-App-Center, WebhookChannel HMAC + NoInternalIp-SSRF-Guard, SLA-Watcher im Cron. **Minor:** Email-Digest-Mode fehlt. |
| F4 | Evidence-Versioning + Cascade | **DONE** | `DocumentVersion` (immutable), `EvidenceCascadeInvalidationService`, `EvidenceReverificationTask`, Undo-Route, Tests. |
| F5 | OSCAL-Importer (NIST) | **STUB** | Nur leere `oscal.{de,en}.yaml`-Skeletons. Kein Parser, keine `sourceProvenance`, keine Entity. (Roadmap P3/Sprint 7+.) |
| F5b | BSI/TISAX-Library-Roundtrip | **DONE** | `BsiKompendiumImporter`, `VdaIsaImporter`+Parser+Validator, `TisaxMaturityAssessmentService`, `EnxScheduleExporter`, 7-Step-Wizard, Library-Fixtures. **Minor:** C5:2026-YAML fehlt in Fixtures. |
| F6 | REST-API Bulk-Endpoints + Token-Mgmt | **ABSENT** | API-Platform-Einzel-CRUD auf 5 Entities, aber kein Bulk-Write, keine `ApiToken`-Entity. (Roadmap P3/Sprint 8+.) |
| F7 | Field-Level-RBAC | **ABSENT** | 25+ Entity-Level-Voter, aber kein Field-Level-Deny. (Roadmap P3/Sprint 8+.) |
| F8 | Health-Check + Observability | **PARTIAL** | `HealthController` `/health` (Liveness 200). **Lücke:** kein `/readyz` (DB+Queue), kein Prometheus `/metrics`. |
| F9 | i18n FR/IT/ES/NL/PT-BR | **ABSENT** | Nur DE+EN (290 Files = 145 Domains × 2). |
| F10 | Per-Framework Scoping/Profile + Maturity | **PARTIAL** | `ComplianceRequirement.maturityCurrent/Target`, `Control.maturityLevel`, Heatmap-Macros. **Lücke:** kein typisiertes `FrameworkProfile`; `OrganizationSecurityProfile` ist generischer KV-Store. |
| F11 | FTE-Tracking-Dashboard + ROI | **DONE** | `src/Entity/Fte/*`, `FteCalculationService`, `BoardReportGenerator`, Dashboard-Controller (index/calibration/board-report), ROI-Counter-Stimulus. |
| F12 | OSCAL-Profile-Roundtrip | **ABSENT** | Nur leeres Translation-Skeleton. (≈ F5.) |
| F13 | TIA (Transfer-Impact-Assessment) | **ABSENT** | `ProcessingActivity.hasThirdCountryTransfer`+`transferSafeguards` als Vorbereitung, aber keine TIA-Entity/Workflow. |
| F14 | Audit-Findings Inline-Capture | **PARTIAL** | `AuditFindingController` + Kontext-Link aus Audit-Show. **Lücke:** nicht wirklich inline — Voll-Seiten-Form, `?audit`-Param wird nicht gelesen (toter Query-Param). |
| F15 | NC-Linking + Auto-Task-Creation | **DONE** | `AuditFinding.linkedRequirements` M2M, `AutoTaskCreator`, Dedup, Tests. |
| F16 | Risk↔Incident Bidirektional-Link | **DONE** | `RiskIncidentLink` + Service + Voter + 4 Routen + Templates + Tests. |
| F17 | Procedures-Authoring | **STUB** | Leeres `procedures.*`-Skeleton, kein `procedures`-Module-Key, keine Entity. |
| F18 | No-Code-Framework-Builder-GUI | **PARTIAL** | Standard-CRUD für Requirements/Frameworks da; aber **kein** visueller No-Code-Builder (Roadmap selbst als Konzept-Bruch markiert — sandboxed CRUD ist der Kompromiss). |
| F19 | Filter-Aware-Export | **DONE** | `FilteredExportController` (`/export/{entity}.{xlsx,csv,json}`, 8 Entities), `FilterStateService`, Tests. |
| F20 | Multi-Format-Doc-Export (MD/DOCX/PDF/HTML) | **PARTIAL** | PDF (dompdf) solide, HTML quasi (Print-Twig), XLSX/CSV/JSON für Listen. **Lücke:** **DOCX + Markdown fehlen** (kein PhpWord/Pandoc). |
| F21 | MCP-Server-Endpoint | **ABSENT** | Kein `/mcp`, nicht mal Translation-Skeleton. |
| F22 | Local-LLM / Ollama für Alva | **ABSENT** | Alva ist rein regelbasiert; keine LLM-Bindung. |
| F23 | Supplier-Questionnaire-Distribution (outbound) | **ABSENT** | Supplier-CRUD da, aber keine Fragebogen-Entity/Token/Portal/Versand. |
| F24 | EBIOS-RM-Methodik | **ABSENT** | Kein `ebios`-Treffer. (Roadmap Sprint 12+.) |
| F25 | VVT-BfDI-Export | **PARTIAL** | `VvtBfdiExporter` (XLSX/CSV/PDF) + Controller + Tests. **Lücke:** LfDI-Bundesland-Varianten (16 Keys definiert, aber kein per-Land-Format). |
| F26 | Behörden-Notification-Templates (AT/CH/BaFin) | **PARTIAL** | `AuthorityTemplate` + `AuthorityNotificationController` + Generator (DE-Bund + 16 LfDI). **Lücke:** `dsb_at`/`edob_ch`/BaFin-DORA-Key fehlen. |
| F27 | BSI 200-4 Übungs-Logbuch | **DONE** | `Bsi2004ExerciseLog` + Controller (index/new/show/edit/submit/confirm/calendar) + Templates + 102-Zeilen-Domain. |
| F28 | TISAX-ISA-Self-Assessment | **DONE** | `TisaxImportWizardController` 6-Step + Reifegrad-0-5 + `tisax_isa.de.yaml` (222 Z.) + E2E-Tests + organisation-mismatch-Warnung. |
| F29 | NIS-2 BSI-Portal-Registrierung | **DONE** | `Nis2RegistrationProfile` + Service + Controller (edit/export_json/mark-reported) + Voter + Tests. |
| F30 | DORA RoI XBRL-Export | **PARTIAL** | `DoraRegisterOfInformation` + `DoraRoiXbrlExporter` (1018 Z., echte XBRL-DOM) + Tests. **Lücke:** ESA-Namespace-URI ist Platzhalter (ESA hat finale Taxonomie noch nicht publiziert); keine Schema-Validierung. |
| F31 | DPIA National/Sektoral-Templates | **ABSENT** | Generischer DPIA-Workflow da; sektorale Template-Schicht (Healthcare/FinServ/AI-Act) nicht gebaut. |
| F32 | DPA-Generator (AVV, Art. 28) | **ABSENT** | Nur `Supplier.hasDPA`-Flag; kein Generator/Document-Type `dpa`/`dpa_template`-Domain. |
| F33 | EU-AI-Act-Klassifizierung | **PARTIAL** | `AiAgentInventoryController` + Asset-Subtype `ai_agent` (prohibited/high/limited/minimal) funktional. **Lücke:** `ai_act.de.yaml` ist leeres Skeleton; `ai_governance`-Module hat `routes: []`. |
| F34 | CRA-SBOM-Inventar | **STUB** | CRA-Framework + Wizard-Checks da; aber kein SBOM-Entity/Import/Vuln-Korrelation. `cra_sbom`-Domain leer. |
| F35 | EUCS-Cloud-Audit-Workflow | **PARTIAL** | EUCS-Compliance-Wizard-Kategorien + Mapping-YAMLs (EUCS↔ISO/C5). **Lücke:** kein dediziertes EUCS-Audit-Workflow-YAML, kein Module-Key/Domain. |
| F36 | EU-Behörden-Reporting-Hub | **DONE** | `AuthorityHubController` + `AuthorityHubService` + klammert F25/F26/F29/F30; module-gated `eu_authority_reporting`; Tests. |
| F37 | One-Command-Setup-Hardening | **DONE** | Multi-Stage-Dockerfile, compose (prod/dev), DB-Init-Scripts, `SystemRequirementsChecker`, `DeploymentWizardController` (11-Step), CI-Gate `docker compose up --wait`. **Minor:** Auto-Migrate läuft via Wizard, nicht im Container-Start. |
| F38 | Policy-Pack-Format-Adapter | **ABSENT** | Kein `policy_pack`-Doc-Type. (Backlog.) |
| F39 | ENISA-EUVD-Daily-Feed-Connector | **ABSENT** | `vulnerability_intel`-Module + `Vulnerability`-Entity da (Voraussetzung), aber kein Feed-Connector/`in_euvd`-Flag. |
| F40 | Audit-Workbook-XLSX-Generator | **DONE** | `AuditWorkbookController` (SoA/ControlImpl/Fulfillment/RiskRegister `.xlsx`) + 4 Generators + Audit-Log-Chain-of-Custody + Tests. (War nicht mal als offen getrackt.) |
| F41 | AI-Compass-Gap-Analysis | **ABSENT (by design)** | In Roadmap v5 gestrichen (parkt hinter F22). Keine Lücke. |
| F-NEU | ICT-Provider-Asset-Library + Multi-Tenant-Distribution | **ABSENT** | `Tenant.tenantType`/`IctServiceLibraryAsset`/`LibraryDistributionService` nicht gebaut. (Backlog Sprint 14+.) |

**Zähler:** DONE 15 · PARTIAL 12 · STUB 3 · ABSENT 13 (davon F41 by-design).

## Reconciliation — die offene Roadmap ist stale

`2026-05-09-feature-roadmap.md` (der überlebende „open items"-Strip) stimmt nicht mehr:

- **Als „Sprint 10-13 zukünftig" gelistet, aber tatsächlich DONE:** F5b, F27, F28 (Sprint 10/11 in der Roadmap — längst in main). F10 ist PARTIAL, nicht offen-unbegonnen.
- **Gebaut, aber gar nicht in der offenen Roadmap getrackt:** F25, F26, F29, F30, F36, F40 (der ganze EU-Behörden-Reporting-Cluster) — über den Plan hinaus implementiert.
- **Korrekt noch offen:** F13, F31, F32 (ABSENT), F33 (PARTIAL), F34 (STUB), F35 (PARTIAL), F24, F38, F39, F-NEU.

→ Der offene Sprint-10-13-Plan sollte zurückgezogen/ersetzt werden durch diesen verifizierten Stand.

## Katalog-Vollständigkeit + neu auflaufende Wünsche

Der Katalog deckt das **was 2026-05-09 erfasst wurde** sehr breit ab (siehe 130+ Entities, inkl. ThreatLedPenetrationTest, DoraDataFlow/ExitPlan/Subcontractor, PolicyAcknowledgement, RiskTreatmentPlan, ManagementReview). Aber sechs moderne GRC-Demands **fehlen im Katalog ganz** — verifiziert ABSENT (0 Entity/Service/Controller):

| Vorschlag | Feature | Warum es sich häuft | Konzept-Pass? |
|---|---|---|---|
| **F42** | **Continuous-Compliance / Integration-Connectors** — automatisches Evidence-Pull aus Cloud/IdP/MDM/Ticketing statt nur Manual+CSV (F2) | #1-Trend moderner GRC; „dauernd statt jährlich". Heute nur manuelle Evidence + Bulk-Import. | Heikel: Single-Audit-Entry + Tenant-Isolation müssen halten; read-only-Connectors, kein Free-Authoring. Machbar. |
| **F43** | **Trust-Center / Public-Posture-Page** — Compliance-Status per signiertem Link mit Kunden teilen | Sales-Enabler; zunehmend Standard-Erwartung. | Tenant-Disclosure-Constraints kritisch (wie F8). Read-only-Snapshot-Export. Passt. |
| **F44** | **Inbound-Security-Questionnaire + Answer-Library** — eingehende Kunden-/RFP-Fragebögen aus wiederverwendbarer Antwort-Bibliothek beantworten | Gegenstück zu F23 (outbound); spart real FTE → passt zur ROI-Story (F11). | Passt — Library-Pattern, Reuse-Analytics vorhanden. |
| **F45** | **Access-Review / UAR-Kampagnen** — periodische Zugriffsrezertifizierung (ISO A.5.18/A.8.2) | Audit-Pflicht, häufig gewünscht; heute nur statische Rollen, keine Kampagne. | Passt direkt — Workflow-Engine + Notification + Audit-Chain vorhanden. |
| **F46** | **Quantitative Risikobewertung (monetär / FAIR / ALE)** — neben qualitativ auch €-Exposure | `monetaryValue` wurde entfernt (#656); Boards wollen €-Zahlen. | Passt — Risk-Entity erweiterbar; optional/modul-gated. |
| **F47** | **Asset-Auto-Discovery / CMDB-Sync** — Assets aus Quellen importieren statt nur manuell | Skaliert Asset-Register; oft erster Onboarding-Schmerz. | Überschneidet F42 (Connector-Foundation). Gemeinsam bauen. |

**Beobachtung:** F42 (Connector-Foundation) ist der gemeinsame Nenner für F44/F45/F47 und der größte strategische Hebel — von „GRC-Dokumentations-Tool" zu „Continuous-Compliance-Plattform". Aber auch der größte Konzept-Risiko-Punkt (Single-Audit-Entry, Tenant-Isolation, kein Free-Authoring). Read-only-Evidence-Pull mit kuratiertem Connector-Katalog (analog Framework-Library) wäre konzept-konform.

## Empfehlung

1. **Stale Roadmap ersetzen:** `2026-05-09-feature-roadmap.md` durch diesen verifizierten Stand ablösen.
2. **Quick-Wins (PARTIAL→DONE), klein + hoher Wert:** F2 `commit.html.twig`, F8 `/readyz`+`/metrics`, F14 echtes Inline (`?audit`-Param lesen), F33 `ai_act`-Domain füllen, F26 AT/CH/BaFin-Keys, F25 LfDI-Varianten.
3. **Neue Wünsche priorisieren:** F45 (UAR, Audit-Pflicht, niedrigster Aufwand auf vorhandener Workflow-Foundation) zuerst; F44 (ROI-Story); dann F42-Foundation als strategischer Bigbet mit Konzept-Review.
4. **STUBs entscheiden:** F5/F12 (OSCAL), F17 (Procedures), F34 (SBOM) — leere Skeletons: bauen oder Skeleton entfernen (sonst „claimed but empty").
