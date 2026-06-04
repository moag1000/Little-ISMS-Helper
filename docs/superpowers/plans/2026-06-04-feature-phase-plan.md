# Feature-Phasenplan v2 (2026-06-04) — alles außer F42

> Sequenzierung aller offenen / PARTIAL / STUB / neuen Features aus dem
> [verifizierten Katalog-Stand](2026-06-04-feature-catalog-verified-status.md),
> **ohne F42** (Continuous-Compliance-Connector-Foundation — separater
> Strategie-Entscheid wegen Konzept-Risiko).
>
> **v2:** überarbeitet nach Review durch 4 Personas — Senior-Consultant,
> Compliance-Manager, Junior-Implementer, UX-Specialist. Änderungen ggü. v1
> sind mit `[Persona]` markiert.

Aufwand relativ (Solo-Dev): S = Tage · M = ~1 Woche · L = 2-3 Wochen · XL = 4+.
**F47 (Asset-Auto-Discovery) hängt an F42 → deferred.**

---

## Zwei Spuren — explizit getrennt `[Compliance-Manager]`

Der Kernmangel von v1: nur eine „Wert × 1/Aufwand"-Logik. Aber manche Items
haben **externe gesetzliche Fristen / Auditor-Erwartungen, die die
Wert/Aufwand-Logik überschreiben**. Diese laufen auf der **Deadline-Spur** und
werden vorgezogen, unabhängig vom Aufwand.

### Deadline-Spur (Pflicht — überschreibt Phasenlogik)

| F# | Pflichtgrund | Frist / Status | → Phase |
|---|---|---|---|
| F13 | TIA — Schrems-II (EuGH C-311/18), DSGVO Art. 44-49, EDSA 01/2020 | **offener Verstoß seit 2021** | **0** |
| F45 | UAR — ISO A.5.18 / A.8.2, NIS2 Art. 21(2)(e), BSI ORP.4 | Erst-Zertifizierungs-Blocker | **0** (MVP) |
| F30 | DORA RoI — ESA-Taxonomie **publiziert** (EBA/EIOPA Joint RoI v1.0, 2025) | aktiv nachziehen, nicht „tracken" | **0** |
| F32 | DPA/AVV — DSGVO Art. 28(3) (schriftlich VOR Verarbeitung) | laufender Bedarf | **1** |
| F31 | Sektorale DPIA — DSGVO Art. 35 + DSK-Muss-Liste (Healthcare/FinServ) | Pflicht bei Hochrisiko | **2** |
| F33 | EU-AI-Act Hochrisiko (Annex III) | **ab 2. Aug 2026** | **0** (Domain+Route), Workflow **2** |
| F-NEU | DORA Art. 28(2) ICT-Provider-Register, Del-VO (EU) 2024/1505 | **in Kraft seit Jan 2025** | **3** (von P5 vorgezogen) |
| F7 | Field-RBAC — ISO A.5.18 (least privilege), A.8.15 | spätestens nach F46 €-Felder | **2/3** (von P4 vorgezogen) |

### Wert-Spur (alles übrige) — nach Wert × 1/Aufwand × Foundation-vorhanden.

---

## Phase 0 — Vertrauen + Pflicht-Quickwins (~2 Wochen)

Ziel: kein „claimed-but-empty" mehr **und** die billigsten Pflicht-Items
abräumen. Reihenfolge innerhalb der Phase nach UX-Schmerz + Haftung.

**0a — Leere STUBs SOFORT entfernen `[Consultant + Junior]`** (nicht „behalten falls Phase 3"):
- Leere Translation-Skeletons + tote Menü-Einträge = aktive Demo-Haftung + schlimmster Junior-Frust („Tool kaputt?"). **F17 Procedures-Skeleton löschen** (Doc-Modul deckt's). **F5/F12 OSCAL** + **F34 SBOM**: leeres YAML weg ODER durch echten Platzhalter-Controller (HTTP 501 + Roadmap-Hinweis) ersetzen — kein leerer Nav-Link.

**0b — UX-Debt zuerst `[UX + Junior]`:**
- **F14 Findings echt inline** — `?audit`-Param in `AuditFindingController::new()` verdrahten + Voll-Seite → `fa-modal--wizard` (2-Step: Titel+Severity → Requirement-Picker) aus Audit-Show-`fa-action-bar`. **Erste Phase-0-Lieferung** (~½ Tag).
- **F2 commit.html.twig** — `fa-stepper` Step 5 + `fa-diff-row`-Summary + `fa-modal.confirm`. Keine neuen Komponenten. (~Stunden statt Tag.)

**0c — Pflicht-Quickwins (Deadline-Spur):**
- **F13 TIA** (M) — `TransferImpactAssessment` als 2. `OneToOne` auf `ProcessingActivity`; **Tooltip + Beispiel pflicht** (Junior: „was ist TIA?" → „prüft ob z.B. US-Cloud DSGVO-konform").
- **F45 UAR-MVP** (S) — Kampagne-Trigger + per-User-Status + Audit-Log-Evidence. **Reuse `TrainingParticipation`/`PolicyAcknowledgement`-Kampagnen-Pattern + `SlaDeadlineMonitor`** `[UX]` — kein dritter Scaffold.
- **F30** (S) — ESA-Namespace-URI setzen + Schema-Val (Taxonomie ist publiziert).
- **F33 Route+Domain** (S) — `ai_act.{de,en}.yaml` füllen + `ai_governance`-Route registrieren (broken-routing vor Formatting-Polish `[UX]`).

**0d — Korrektheits-Quickwins (Wert-Spur):**
- **F8** (S) `/readyz` (DB+Queue) + `/metrics`. **F26** (S) `dsb_at`/`edob_ch`/BaFin-Key. **F25** (M) LfDI-Varianten. **F3** (S) Email-Digest.

**Querschnitt-Mandat `[Junior]`:** jedes fertige Phase-0-Feature braucht
ordentlichen Leer-Zustand mit CTA + Tooltip mit Normzitat — nicht nur technisch
funktionieren.

**Akzeptanz:** keine leeren Domains/Nav-Links; F14 inline; F2 commit; TIA +
UAR-MVP + F30 + F33 als Pflicht erledigt; alle PARTIALs der Phase DONE.

---

## Phase 1 — High-Value-Wünsche auf Foundation (~3 Wochen)

Reuse-Mandate sind verbindlich (sonst N bespoke Screens `[UX]`).

| # | Feature | Aufwand | Reuse-Mandat |
|---|---|---|---|
| **F45** | UAR — volle Kampagnen-UX | M | `fa-table` + `_bulk_action_bar` (`status_change`,`approve`) + `_isms_approval_stages`; Voter analog Training |
| **F32** | DPA-Generator (AVV) — **vorgezogen vor F13-Rest** `[Consultant]` | M | `AuthorityTemplate`-Substitution + `Document(type=dpa)`; **Variable-Editor = Builder, kein textarea** `[UX/CLAUDE.md]` |
| **F44** | Inbound-Questionnaire + Answer-Library | M | **`AuthorityTemplate` + `DocumentReuseAnalyticsService` → speist `FteRecorderService`** (eine ROI-Story F11↔F44, kein Parallel-Store) `[UX]` |
| **F46** | Quantitative Risk (€/FAIR/ALE) | M+ `[Consultant: nicht S]` | €-Anzeige via `_fa_feature_card`+Legend, **kein color-only** (WCAG 1.4.1) `[UX]`; Board-Report (F11) + Import (F2) müssen €-Feld mappen — explizites Min-Delivery `[Consultant]`; Tooltip+Rechenbeispiel `[Junior]` |

**Akzeptanz:** UAR-Zyklus liefert auditierbare Recert-Evidence; AVV generierbar
aus VVT; Answer-Library beantwortet Fragebogen aus Reuse (FTE-Counter zählt);
Risk zeigt €-Exposure im Board-Report (nicht nur Feld).

---

## Phase 2 — Privacy-Cluster + Sales-Closer (~4 Wochen)

GDPR-Block + der stärkste Vertriebs-Trigger, vorgezogen `[Consultant]`.

| # | Feature | Aufwand | Notiz |
|---|---|---|---|
| **F31** | Sektorale DPIA-Templates | M | Healthcare §22 BDSG / FinServ-DORA / AI-Act-Annex-III; Library-Pattern |
| **F43** | **Trust-Center / Public-Posture-Page — von P4 vorgezogen** `[Consultant]` | M | Deal-Closer (KRITIS-Lieferkette). **Braucht eigenes `base_public.html.twig`** (kein Session/Mega-Menü → sonst broken Nav) `[UX]`; tenant-disclosure-constraints wie F8 |
| **F7** | Field-Level-RBAC — von P4 vorgezogen `[Compliance]` | L | A.5.18/A.8.15; dringend sobald F46 €-Felder + FTE-Gehaltsdaten existieren; Minimal-Voter für neue sensible Felder |
| — | **Shared UI-Shell** `[UX]` | — | F13/F31/F32 als `fa-tabs` IN `ProcessingActivity`-Show, nicht 3 bespoke Voll-Seiten |

**Akzeptanz:** sektorale DPIA wählbar; Posture per signiertem Link teilbar;
sensible Felder field-level deny-fähig + A.8.15-Log; GDPR-Kette in einer Ansicht.

---

## Phase 3 — Interop / Export / DORA-Completion (~5 Wochen)

| # | Feature | Aufwand | Notiz |
|---|---|---|---|
| **F-NEU** | ICT-Provider-Library + Distribution — **von P5 vorgezogen** `[Consultant+Compliance]` | XL | DORA Art. 28 in Kraft; kuratierte Library analog Framework-Lib |
| **F6 (MVP)** | **Read-Only-API + Token** — Teil-MVP vorgezogen `[Consultant]` | M | GET-Paginierung auf 5 Kern-Ressourcen + `ApiToken`; Bulk-Write → Phase 4 |
| **F20** | DOCX + Markdown-Export | M | PhpWord (DOCX); MD-Safe-Subset existiert |
| **F35** | EUCS dediziertes Audit-Workflow | M | Workflow-YAML + Module-Key + Domain |
| **F39** | ENISA-EUVD-Feed-Connector | M | Cron + `in_euvd`-Flag (`vulnerability_intel` da) |
| **F38** | Policy-Pack-Format-Adapter | S | `policy_pack`-Doc-Type |
| **F5/F12** | OSCAL Import+Roundtrip *(falls Phase-0 „bauen")* | L | NIST-JSON-Parser + `sourceProvenance`; Upload-Hint „nur NIST-OSCAL .json" `[Junior]` |
| **F34** | CRA-SBOM-Inventar *(falls „bauen")* | L | SBOM-Entity + Vuln-Korrelation; Upload-Hint pflicht `[Junior]` |

---

## Phase 4 — Platform / B2B (~4-5 Wochen)

| # | Feature | Aufwand | Notiz |
|---|---|---|---|
| **F6 (full)** | Bulk-Write + Scoping-Granularität | M | auf MVP aus P3; Single-Audit-Entry |
| **F23** | Supplier-Questionnaire-Distribution (outbound) | L | Token-Link + externes Portal; Gegenstück zu F44 |

---

## Phase 5 — Forward / AI / i18n (niedrigste Prio)

| # | Feature | Aufwand | Notiz |
|---|---|---|---|
| **F24** | EBIOS-RM | **XL** `[Consultant: nicht L]` | anderes Risk-Modell → Workshop+PoC nötig; Slip-Risiko; Feature-Einstieg „für wen" `[Junior]` |
| **F9** | i18n FR/IT/ES/NL/PT-BR | L | FR koppelt an F24 (FR-Markt) |
| **F21** | MCP-Server | M | LLM-Catalog-Queries |
| **F22** | Local-LLM / Ollama | L | auf Alva-Foundation |

---

## Deferred (an F42 gekoppelt)

- **F47 Asset-Auto-Discovery** — braucht Connector-Foundation (F42). Ohne F42 nur manueller CSV/API-Import (≈ F2-Erweiterung), keine echte Discovery. Wartet auf F42-Entscheid.

---

## Querschnitt-Mandate (gelten in jeder Phase)

**Reuse-Map `[UX]` — verbindlich, spart N bespoke Screens:**
- F45 UAR = `TrainingParticipation` + `PolicyAcknowledgement`-Kampagnen-Pattern + `SlaDeadlineMonitor` + `fa-table`/`fa-bulk-bar`/`approval-stages`.
- F44 Answer-Library = `AuthorityTemplate` + `DocumentReuseAnalyticsService` → `FteRecorderService`.
- F13/F31/F32 = `fa-tabs` in `ProcessingActivity`-Show (gemeinsame Shell).
- F2-commit = `fa-stepper` + `fa-diff-row` + `fa-modal.confirm`.
- F14 = `fa-modal--wizard` aus `fa-action-bar` (nicht Voll-Seite).

**Erklärungs-UX `[Junior]` — pflicht bei Experten-Features (TIA/FAIR-ALE/OSCAL/EBIOS/SBOM):**
Jedes braucht zum Launch: Normzitat-Tooltip + konkretes Beispiel + sinnvollen
Platzhalter-Text + (wo möglich) 9001-Analogie. Sonst: leeres Pflichtfeld mit
Fachbegriff → Operator lässt es links liegen.

**Konzept-Invarianten überall:** Tenant-Isolation · kuratierte Library (kein
Free-Authoring) · HMAC-Audit-Chain Single-Entry-Point · Module-Gating · Aurora
v4 (kein raw `<table>`/textarea/color-only) · Symfony 7.4 LTS · keine
Produktnamen.

---

## Was sich ggü. v1 geändert hat (Persona-Synthese)

1. **Deadline-Spur eingeführt** `[Compliance]` — gesetzliche Fristen überschreiben Wert/Aufwand.
2. **F13 TIA + F45 UAR-MVP + F30 + F33 → Phase 0** (Pflicht/offene Verstöße, Foundation da).
3. **Leere STUBs sofort löschen** statt „behalten" `[Consultant+Junior]` — aktive Haftung + Frust.
4. **F32 DPA vor F13-Rest, F43 Trust-Center P4→P2, F-NEU P5→P3, F7 P4→P2/3, F6-Read-Only-MVP→P3** — Reorder nach Deadline + Sales.
5. **Reuse-Map verbindlich** `[UX]` — konkrete Entity-/Macro-Pointer pro Feature.
6. **Erklärungs-UX-Mandat** `[Junior]` für alle Experten-Features.
7. **Aufwand-Korrekturen** `[Consultant]`: F46 S→M+, F24 L→XL.
8. **F14 = erste Phase-0-Lieferung als Modal-Wizard** `[UX]`.
