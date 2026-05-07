# ISB-Practitioner Review — Policy-Wizard Plan

> Review von `05-architecture.md` aus Sicht eines operativen ISB
> (Informationssicherheitsbeauftragter, deutscher Mittelstand,
> 8 Jahre im Job). Mix DE/EN bewusst. Bezugnahmen auf BSI-Input
> (`02-bsi-input.md`) wo es operativ relevant ist.

---

## My profile

KMU IT-Dienstleister ~280 MA, KRITIS-Zulieferer Energiesektor (BSIG §8a
indirekt ueber Hauptkunden), drei Toechter (D/AT/CH, 12-40 MA), ISO
27001:2022 seit 2022, BSI-Grundschutz Standard-Absicherung als
KRITIS-Mapping. Heute Inbox: zwei Findings vom Ueberwachungsaudit
letzten Donnerstag (A.5.15 Access-Tailoring zu generisch, A.8.13
RPO-Tier unklar), eine NIS2-Self-Assessment-Anfrage vom Hauptkunden,
und Konzern-CISO der Holding kuendigt an: Crypto-Mindestschluessel
128→256 Bit, alle drei Toechter, bis Q3.

## Daily-driver verdict

**Quartalsweise mit Ausreissern.** Realistische Frequenz: 4x/Jahr
geplant + 2-3x ad-hoc bei Konzern-Pushdown oder Finding. Caching-
Konsequenz: Step-Eingaben muessen ueber WizardRun-Grenzen hinweg
persistieren — `TenantPolicySetting` (§4.1) impliziert das, aber §6
macht nicht explizit, dass Step 2-5 aus TenantPolicySetting
vorgefuellt sind. Bitte explizit. Sonst tippe ich "DPO ist noch immer
Frau Mueller, RPO=4h" zum 4. Mal.

## Workflow walkthrough

### 1. Regulator-Finding mid-year (3 Policies anpassen)

Auditor: A.5.15 Tailoring zu generisch, A.8.13 RPO-Tier unklar, A.8.7
Malware-Policy fehlt EDR-Erwaehnung. Drei Policies approved+immutable.

- Hilft: §10 Re-Generation — Setting aendern, ReGenerationDetector
  erkennt Hash-Drift, erzeugt Drafts mit supersedes-Link. §11.1
  Tailoring-Pflicht zwingt mich bei A.5.15.
- Hindert: §6 hat keinen "Targeted Re-Run" ("nur diese 3 Templates
  neu"). 7-Step-Wizard fuer 3-Policy-Update ist Time-Waste.
- Audit-Trail: §9.6 zeigt Verlauf, aber WizardRun (§4.1) fehlt
  optionales `triggeredByFindingRef` — bitte ergaenzen.

**Score: 6/10.** Versionierung solid, Targeted-Re-Run + Finding-Link
fehlen.

### 2. Konzern-CISO pushes Baseline (Crypto 128→256) → 4 Toechter

- Hilft: §7.3 `Crypto min-key: parent_min, broader_only` blockt
  Tochter unter 256. §7.2 mirror-to-norm-inheritance + §8.4 Cascade.
- Hindert: §10 Re-Generation triggert nur bei naechstem manuellem
  Tochter-Wizard-Run. Konzern-Defaults-Wizard (§7.3 Schluss) setzt
  Settings, triggert aber NICHT die Tochter-Re-Runs. Ich muss
  4 Tochter-ISBs einzeln anstossen. **Haerteste Luecke.**

**Score: 4/10.** Push-down-Trigger fehlt komplett.

### 3. M&A: 12-MA-Tochter eingliedern

- Neuer Tenant, Konzern hat ISO+BSI-Deltas pre-loaded. §6 Step 1
  Inheritance-Preview, §3 Coverage 25-35 Docs, 30-Min-Goal realistisch.
- §12 Microenterprise-Fork existiert NUR fuer DORA. BSI-Input §3.2
  Basis-Absicherung waere passend, aber §3-Coverage-Matrix
  differenziert nicht nach `bsi_methodology`. §6 Step 4 erfragt's,
  aber Auswirkung auf Doc-Generation nirgends dokumentiert.

**Score: 7/10.** BSI-Basis/Standard/Kern-Filtering muss in §3 + §6
Step 4 expliziter werden.

### 4. Quartal Internal-Audit-Vorbereitung

- §8.1 DocumentControlLink + §8.5 Tagging + §8.2 SoA-
  Justification-Snapshot + §9.6 Approval-Trail decken Filter
  "alle A.5.15-Policies approved Stand 30.06.2026" perfekt ab.
- Fehlt: zusammenstellbares **Audit-Pack** (PDF/ZIP mit Policies+SoA+
  Trails fuer Stichtag X). §15 Open Risks erwaehnt es nicht.

**Score: 7/10.** Daten da, UI-Aggregation fehlt.

### 5. Q4 Review-Window: 18 Docs faellig

- §9.4 Fast-Path + §9.5 Multi-Doc-Batch — **Killer-Feature**. 14 von
  18 mit "no change" abquittieren, 4 in Edit-Workflow.
- Sorge: §9.6 Trail "CISO confirmed — no change" ohne Comment ist
  duenn beim Auditor. Bitte optional-mit-Hint Comment-Feld
  ("Worauf geprueft? TR-02102? NIS2?"). Empty = subtiler Auditor-Hint.

**Score: 9/10.** Fast-Path ist gold; Comment-Detail fehlt.

### 6. ISO 27001 Rezertifizierungsaudit, 6 Wochen Anlauf

- §11.1 Tailoring-Felder + §11.2 Substitution-Footer-Comments
  decken Templated-Feel-Risk gut.
- Fehlt: §12 deckt nicht "Konzern-Hauptaudit Stichtag, mein
  Tochter-Audit 4 Wo spaeter — auf welche Policy-Version war
  Konzern gepinnt?". Pinning-Snapshot-Mechanik fehlt.

**Score: 7/10.** Solide; Pinning fehlt.

## German-specific concerns

- **BSI Basis/Standard/Kern coverage** (BSI-Input §3.2, Tabelle):
  Im Architektur-Plan §6 Step 4 wird `bsi_methodology` erfragt, aber
  §3 Coverage-Matrix differenziert NICHT nach Basis vs Standard vs
  Kern. Das ist gefaehrlich — eine "Basis"-Tochter darf KEINE
  Standard-Anforderungen-Sektionen im generierten Doc haben (BSI-
  Input §7.1). Bitte §3 erweitern: "BSI-Standard 28 Docs (Basis-Filter
  ergibt ~28 Docs mit reduziertem Anforderungs-Set; Kern-Filter
  ergaenzt erweitere Risikoanalyse-Sektionen)".
- **BSIG §8a 2-Jahres-Audit (KRITIS)**: §12 Sector-Overlay sagt
  "wizard sets reviewIntervalMonths=12 (defensive default ≤ legal max
  of 24)". Korrekt fuer KRITIS-Tenant. Aber: §8a verlangt Audit-
  PRUEFUNG alle 2 Jahre, nicht nur Policy-Review. Wizard sollte
  zusaetzlich einen `bsig_8a_audit_due` Alva-Hint generieren (siehe
  BSI-Input §8.8). Heute steht das nicht drin. Empfehle einen
  expliziten KRITIS-Hint in §11/§12.
- **Betriebsrat / Works-Council** (§9.1 Schluss + §9.3
  `worksCouncilGate`): "DE-specific: works-council consultation gate
  for HR / Logging / Physical-Security policies before
  top_mgmt_signoff can fire. Configurable per tenant; default ON in
  DE locale." — **Das ist genau richtig**, aber zu unkonkret. Welche
  Templates triggern den BR-Gate genau? `ORP.2 Personalrichtlinie`,
  `OPS.1.1.5 Protokollierungsrichtlinie`, `OPS.1.2.4 Telearbeit`
  sind die offensichtlichen (BSI-Input Anhang A Pos. 5, 19, 21).
  Bitte explizite Template-Liste im Code (PolicyTemplate-Flag
  `triggers_works_council_review: bool`). Sonst hat ein Tochter-ISB
  hinterher Aerger mit dem BR und sucht den Bug im Wizard.
- **DSGVO-as-Section** (DPO §0 Decision-Matrix): Aus Auditor-Sicht
  in DE: DSGVO-Auditor (BfDI / Landes-DSB) WILL eine standalone
  Datenschutzleitlinie sehen, kein Section-In-ISO-Top-Level. Das
  Matrix §0 Pos. 2.1 hat "section in ISO Top-Level Policy — fallback
  to standalone if Konzern-CISO insists". Mein Take: in DE ist der
  Fallback eher der Default. BSI-Input §2.3.2 sagt explizit
  "Verweis auf bestehende Datenschutzleitlinie" als Cross-Ref.
  Bitte den Tenant-Setting `gdpr_top_level_as_standalone` mit DE-
  Locale-Default `true` versehen. Sonst diskutiere ich mit dem
  Konzern-DSB stundenlang.
- **Translations DE/EN — Source of Truth**: §8.7 Translation strategy
  + §15 Translation authoring effort: ~7000 Keys. Fuer BSI-Templates
  MUSS DE die Source-of-Truth sein (BSI-Standards sind DE-only-
  authoritativ; EN ist Uebersetzung). Architektur sagt nichts
  dazu — wer reviewt die EN-Uebersetzung der BSI-Bausteine? Das ist
  ein heikler Punkt fuer Konzerne mit AT/CH/NL-Toechter. Empfehle
  im Code-Layer: BSI-Templates mit `source_language: 'de'` Flag,
  ISO-Templates mit `source_language: 'en'` — Linter warnt bei
  Mismatch.

## Bulk-approval ergonomics (§9.2)

- **Wird die GF wirklich klicken?** In meinem Mittelstand: ja, wenn
  ich's gut aufbereite. Aber: GF sagt "ich approve nicht 25 auf
  einmal, ich will je Policy einen Satz Begruendung sehen". §9.2
  hat "shared rationale textarea" — das ist zu wenig. Der GF
  unterschreibt nicht 25 Policies mit "passt schon" als Sammel-
  Begruendung, wenn der Auditor das mal hochzieht.
- **Per-row Reject-UX**: §9.2 "Per-row checkbox + Approve selected +
  Reject selected". Was wenn GF 24 approved, 1 rejected? Was passiert
  mit Doc #25? Vermutung: bleibt in `top_mgmt_signoff`, geht zurueck
  an wen? An mich (ISB / Wizard-Initiator) oder an Doc-Owner? §9.1
  "Rejection sends Document back to draft and notifies the
  wizard-initiator user" — also bei Bulk-Reject lande ich (nicht
  der Doc-Owner) als Notifizierter. Das ist falsch fuer Topic-
  Policies wo der Doc-Owner ein Fachverantwortlicher ist
  (z.B. IT-Operations-Lead fuer Patch-Policy). Bitte: notify-target
  = Document.owner, nicht WizardRun.startedByUser_id.
- **Audit-Log bei Bulk-Approve**: §9.2 "ONE audit-log group entry
  per batch". Fuer mich als ISB bei einem Audit-Defense reicht
  das **nicht**. Ich brauche per Doc den Eintrag "Approved by GF
  Carla G. on 2026-09-30 09:11 as part of batch B-2026-09-30-001"
  — heute klingt §9.2 nach NUR einem Gruppen-Log, nicht zusaetzlich
  per-Doc. Bitte explizit: BEIDE Log-Layer (per-Doc-State-Transition
  + Batch-Group-Reference). Auditor will den per-Doc-Trail, nicht
  ein Sammelposting durchsuchen.

## Re-run / re-generation ergonomics (§10)

- **Mein "what-changed-for-me" View**: Konzern-CISO hat per §7.2
  Master gepusht. §10 erkennt Hash-Aenderung beim NAECHSTEN
  Wizard-Run. Fehlt: ein Dashboard-Widget "Konzern hat 3 Settings
  geaendert seit deinem letzten Wizard-Run; betroffen sind X
  Policies; re-run empfohlen". Heute klicke ich erst Wizard-Run
  Step 1 → sehe das Inheritance-Preview erst spaet.
- **Hash-compare Drift-Detection in UI**: §10 erwaehnt Hash-Compute,
  aber kein UI-Surfacing. Mein Wunsch: im Document-Listings ein
  `settings-drift` Badge ("Settings die diese Policy steuern
  haben sich geaendert seit Erzeugung"). Klick → "re-run wizard for
  this template". Treibt Targeted-Re-Run-Modus (siehe Workflow #1).
- **History-Bloat im Document-Modul**: §10 + §15 Open Risks deuten
  immutable forever an. Bei mir kommen ~30 Policies/Jahr in neuer
  Version dazu (3 Frameworks + Re-Generation bei Findings). Nach 5
  Jahren: 150+ archivierte Versionen. Document-Listing wird unbrauchbar.
  Bitte default-Filter "current version only" + Toggle "show all
  versions". Optional: archivierungs-Strategie nach 7 Jahren
  (BDSG §35 Loeschpflicht — aber Audit-Aufbewahrungsfrist ist
  10J in DE fuer GoBD/HGB... das ist eine eigene Diskussion).
- **Diff UX (§15 Risk)**: Mein Minimum: **Section-level + Variable-
  level**. Charakter-Diff brauche ich NICHT. Wenn ich sehe "Section
  3.2 'Schluessellaengen' geaendert; Variable
  `crypto_min_key_length: 128 → 256`" reicht fuer Approval-
  Discussion mit GF. Sentence-Level waere nice-to-have, nicht must.
  Empfehle: Sprint W6 liefert Section + Variable-Diff; Sentence-
  Diff in V2.

## What I love

- **§9.4 Review-without-changes Fast-Path** — siehe Workflow #5.
  CISO-only-Quittung ohne GF-Eskalation rettet mir Quartalsweise
  Tage.
- **§8 SoA-bidirektional im DB-Transaction** — heute fuehre ich eine
  Excel-Liste "Welche Policy deckt welchen Annex-Control"; das
  faellt weg. §8.1 + §8.2 + §8.3 zusammen ist endlich ein "evidence-
  driven SoA" statt Mapping-Buchhaltung.
- **§8.5 Tagging-Schema** mit `wizard-run:<id>` + `dora-validity:
  2025-01-17`. Lasst mich Audit-Filter "alle DORA-relevanten Policies
  approved zum Stichtag X" baublich.
- **§11.1 3 Pflicht-Tailoring-Felder** — direkt aus BSI-Input §7.2
  uebernommen. Verhindert das Templated-Feel-Finding, das ich
  letzten Donnerstag bekommen habe.
- **§9.5 Multi-document review batch** — analog zum Bulk-Approval,
  aber fuer mich (CISO/ISB), nicht fuer GF. 18-Docs-Quartalsstress
  wird managebar.
- **§4.1 `inheritedFromTenant_id` + `overrideMode` als First-Class
  Felder** — Konzern-Inheritance wird im Datenmodell sichtbar,
  nicht in einer 50-seitigen-Konzern-Politik-PDF versteckt.

## What worries me

1. **Kein Konzern-Push-Trigger fuer Tochter-Re-Run (§7.3 Schluss).**
   Konzern-CISO setzt neuen Baseline → Toechter erfahren das nur
   beim naechsten manuellen Re-Run. Brauche `bin/console app:
   trigger-tenant-rerun --tenant=X --templates=Y` oder UI-Button
   "Push to subsidiaries". Ohne das ist §7 Inheritance praktisch wertlos.
2. **Fast-Path-Comment fehlt (§9.4).** "Confirm review — no change"
   ohne Begruendungsfeld ist auditor-tauglich nur fuer Trivialfaelle.
   Bitte optionales (mit DE-Locale-Default required) Comment-Field.
3. **Bulk-Approve Audit-Log nicht granular genug (§9.2).** Per-Doc-
   Log-Eintrag zusaetzlich zum Batch-Reference muss garantiert sein.
4. **Targeted Re-Run-Modus fehlt (§6/§10).** 7-Step-Wizard fuer
   3-Policy-Update ist Time-Waste. UX braucht "Re-run only for
   templates: [...]"-Selektion.
5. **History-Bloat (§10).** Default-Filter "current only" muss kommen,
   sonst wird Document-Listing nach 3 Jahren unbenutzbar.
6. **Microenterprise-Mode nur fuer DORA (§12).** BSI-Basis-Fork
   muss aequivalent integriert sein, sonst kriegen 12-MA-Toechter
   das volle Standard-28-Docs-Set serviert.
7. **§9.1 Rejection-Notify-Target falsch.** Bei Topic-Policies muss
   der Document.owner notifiziert werden, nicht WizardRun-Initiator.
8. **§12 KRITIS-Hint fehlt fuer §8a-Audit-Cadence.** `is_kritis=Y`
   sollte automatisch einen Alva-Hint "Naechster §8a-Audit faellig
   YYYY-MM" generieren.

## What's missing for me

1. **Pinning specific Policy-Versions zu Framework-Audit.** Use-Case:
   "Diese 24 Policies in Version v2 sind die Basis fuer das
   ISO-27001-Rezertifizierungsaudit am 2026-11-15." Bin gefragt vom
   Auditor "welche Version war zum Stichtag gueltig" — heute
   beantwortbar, aber muehsam. Vorschlag: neue Entity
   `PolicyAuditSnapshot { tenant, framework, audit_date,
   pinned_documents[] }`. Sprint W6/W7.
2. **PDF-Export pro Policy mit Tenant-Letterhead/CI**. Auditor will
   PDF, ich muss in Document-Modul gehen, kopieren in Word, mit
   Briefkopf versehen. Sprint W3+ implementiert PDF-Generator mit
   `tenant.brand_logo` + `tenant.brand_color`. BSI-Input §8.4
   referenziert Tagging — aber kein Export.
3. **Risk → Policy Backlinking**. Risk-Owner schliesst ein Risiko
   per Mitigation "siehe Crypto-Policy". Heute: freier Text. Mit
   Wizard: Risk.mitigatedBy → Document-FK direkt. Macht den Roll-up
   "welche Policies decken welche Risiken" trivial.
4. **Notification "Konzern-CISO approved new master, re-run
   subsidiary wizard"**. Siehe Worry #1 — aber zusaetzlich: Email +
   Notification-Bell beim Tochter-ISB.
5. **Print-friendly PDF/Markdown-View pro Policy**. Bei Awareness-
   Schulungen drucke ich Auszuege; heute mit hand-format. Wizard
   sollte einen "Print View" + "Export to MD/PDF" liefern. ORP.3
   Awareness-Richtlinie wird sonst nicht gelebt.
6. **Policy-of-the-Quarter Dashboard-Widget**. Pick zufaellig 1
   approved Policy pro Quartal, schicke an alle MA mit
   "Quartals-Lese-Tipp". Trivial einzubauen, riesiger Awareness-
   Effekt. Knuepft an existierende `notifications` Domain an.

## Sprint priority (operational view)

§13's 6 Sprints, neu sortiert nach "was macht meinen Dienstag-
Morgen einfacher":

1. **W1 Domain** — bleibt erste Prio. Ohne Datenmodell nichts.
2. **W2 Wizard-Core (ISO 27001 only)** — bleibt zweite Prio.
   Zertifizierte ISO-Tenants sind die groesste Zielgruppe.
3. **W3 Document Generation + SoA Link + (NEU)
   Targeted-Re-Run-Modus + Fast-Path-Comment-Field + per-Doc
   Audit-Log-Layer** — die drei Pain-Punkte aus Worry #2/#3/#4 sind
   billig nachzuruesten und retten den Auditor-Use-Case.
4. **W4 BSI Grundschutz** — vorziehen, weil DE-Mittelstand groesser
   als reine ISO-Group. Plus BSI-Basis-Fork (Worry #6).
5. **W5 DORA + BCM** — ja, aber nach W6, weil Compliance-Cadence-
   Push-Down (siehe naechster Punkt) wichtiger ist.
6. **W6 Polish + Konzern-Defaults + Push-Down-Trigger + Diff-UI +
   Settings-Drift-Badge + History-Filter** — die Konzern-Bedienung
   muss frueh kommen, sonst ist W4-W5 ausserhalb meines Setups
   nutzlos.
7. **(NEU) W7 Audit-Pack-Export + PDF-Letterhead + Risk-Backlink** —
   Audit-Cycle-Ergaenzungen. Macht ISB-Tag im Audit-Quartal um 50%
   leichter.

Fazit: Sprint-Reihenfolge eher 1, 2, 3, 4, 6, 5, 7 (BSI + Konzern-
Bedienung VOR DORA).

## Konzern-Tochter override-mode interpretation (§7.3)

Matrix §7.3:

| Setting | Konzern level | Subsidiary override |
|---|---|---|
| Risk appetite tier | parent_max | stricter_only |
| Backup RPO | parent_min | stricter_only |
| Review interval months | parent_max | stricter_only |
| Cryptography min-key | parent_min | broader_only |
| Crisis-team size | — | free |
| Approval-chain top-mgmt | parent_value | forbidden_to_override |

`stricter_only` Semantik passt fuer Risk Appetite + Backup RPO +
Review Interval. Crypto `broader_only` ist begrifflich verwirrend
("broader" = laengere Schluessel = strenger). Bitte umbenennen zu
`stronger_only` oder allgemein `more_secure_only`. Sonst diskutiere
ich monatlich mit Tochter-ISB "warum heisst das broader, wir wollen
doch strenger nicht weiter".

**Praxisfaelle wo's bricht:**

1. **Risk-Appetite-Tier**: Konzern hat appetite=3 (mittel). Tochter
   ist Pen-Test-Boutique mit appetite=4 (hoeher OK in dem
   Geschaeftsmodell). `stricter_only` blockt das. Realitaet: KMU-
   Toechter haben oft hoehere Risikobereitschaft als Konzern.
   `stricter_only` ist zu streng — `free_with_justification` mit
   Audit-Trail-Pflicht waere realistischer.
2. **Backup-RPO**: Konzern setzt 4h. Tochter mit reinem Test-System
   sagt 24h reicht. `stricter_only` blockt — die Tochter muss
   technisch-unsinnige 4h fahren. **Realitaet**: per-System-RPO
   gehoert in Asset-Service nicht in Tenant-Setting. Override-
   Matrix denkt zu pauschal.
3. **Review-Interval**: Konzern 12 Monate, Tochter (KRITIS) muss
   alle 6 Monate (§8a). `stricter_only` (kuerzer = strenger) klappt
   — gut.
4. **Crypto-min-key**: Konzern 256 fuer alle. Tochter mit Legacy-
   IoT-Geraeten kann nur 128 fahren. `broader_only` blockt — die
   Tochter ist gezwungen Geraete zu tauschen. **Realitaet**:
   Compensating-Controls + Risk-Acceptance-Workflow sollten erlaubt
   sein. Hard-Block ist auditierbar, aber nicht tauglich.
5. **Approval-Chain top-mgmt `forbidden_to_override`**: ISO Cl. 5.2
   ist klar — top-mgmt-signoff fuer Top-Level-Policy ist Pflicht.
   Aber `forbidden_to_override` blockt auch parallele Konzern-CISO-
   Approval als zusaetzliche Stufe — was Konzerne wollen koennten.
   Hier missing: `forbidden_to_relax` (= Tochter darf zusaetzlich
   strenger, aber nicht weicher).

**Empfehlung**: `stricter_only` umbenennen zu `floor_only` (Konzern
ist Untergrenze, Tochter darf nur ueber Untergrenze gehen).
`broader_only` umbenennen zu `ceiling_only`. `forbidden_to_override`
in 2 splitten: `forbidden_to_change` + `forbidden_to_relax` (letzteres
erlaubt zusaetzliche-strenger-Override).

## Open questions for Phase 4

1. **An BSI-Specialist**: Wie soll der Wizard mit Tenant-spezifischen
   BSI-Edition-Versionen umgehen, wenn Konzern auf Edition 2024 ist
   aber eine Tochter aus Audit-Cycle-Gruenden noch Edition 2023
   referenzieren MUSS bis 2026-Q4? Brauchen wir
   `tenant.bsi_edition_pin: '2023'`?
2. **An DPO-Specialist**: §0 Decision-Matrix Pos. 2.1 — was ist
   der konkrete DE-vs-EN-Fallback? Ich plaediere fuer DE-Locale-
   Default = standalone Datenschutzleitlinie, EN-Locale-Default =
   section. Stimmt das mit BfDI-Erwartungen ueberein?
3. **An UX-Specialist**: Targeted-Re-Run-Modus (Worry #4) — soll
   das ein eigener Flow sein oder ein Step-1-Toggle "Re-Run mode:
   [Full] [Targeted: select templates]"?
4. **An Senior-Consultant**: Habt ihr in eurer Customer-Base
   Beispiele fuer Audit-Pack-Export-Formate, die externe Auditoren
   wirklich akzeptieren? PDF-Bundle? ZIP mit MD? Strukturierte
   JSON-Evidence?
5. **An External-Auditor**: Fast-Path "Confirm review — no change"
   ohne Pflicht-Comment — ist das auditor-tauglich oder ist es
   automatisch ein Finding-Trigger, wenn Review-Eintrag leer ist?
