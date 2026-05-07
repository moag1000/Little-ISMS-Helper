# Senior-Consultant Review — Policy-Wizard Plan

> Reviewer: Senior ISMS Consultant, ~15 Jahre, ~30 Onboardings/Jahr.
> Scope: `docs/plans/policy-wizard/05-architecture.md`. Specialist
> inputs (`01`-`04`, `06`) skimmed where architecture pointed.

## My profile

Ich mache hybride Onboardings: typischerweise 1–2 Tage Kickoff vor Ort
beim Kunden (Mittelstand 100–1500 MA, oft IT-/Maschinenbau, Kliniken,
Stadtwerke, Versicherungen), danach 6–12 Wochen Remote-Begleitung mit
einem 90-Min-Jour-Fixe pro Woche. Was reinkommt: ca. 50 % "wir haben
GAR nichts schriftlich" (Bauchgefühl-ISMS), 30 % "halbgare Word-Docs
von einem Praktikanten 2019", 15 % M&A-Mischmasch (3 Tochter-Standards,
nichts harmonisiert), 5 % wirklich solide ISMS (meist nach
Re-Zertifizierung). Policy-Set ist immer der erste Knackpunkt — ohne
Top-Level-Policy + 5-7 Topic-Policies kommt kein Stage-1-Auditor durch.

## Customer-facing verdict

**Ja, ich würde das einem Mittelstand-CIO im Kickoff zeigen — mit drei
klaren "aber".** Die Wizard-Idee + die SoA-Verkopplung (§8) + der
Re-Run-Skip-Mechanismus (§10) + die Bulk-Approval-Inbox (§9.2) sind
state-of-the-art und genau das, was Kunden erwarten, die schon mal
generische SaaS-GRC-Tools gesehen haben.

Wo's hält: Hierarchie-Vererbung mit `overrideMode`-Matrix (§7.3) ist
elegant und beantwortet die "wir haben 14 Tochtergesellschaften"-Frage,
die in 70 % meiner Pitches in den ersten 10 Minuten kommt. Der
"Konzern-Defaults"-Wizard ist ein echter Differenzierer.

Wo es mich vor dem Kunden nackig macht (siehe "What I dread"): Word-
Upload, Letterhead/PDF-Branding, Doc-Merge, Migration bestehender
Docs, Industry-Presets. Wenn ein CIO nachfragt "wir haben schon ein
30-seitiges Sicherheits-Handbuch — was passiert damit?", muss ich
heute leider wegrudern. Das wird die häufigste Frage sein und das
Plan adressiert sie nicht.

## Demo flow check

Mein 60-Min-Slot im Kickoff:

| Min | Was ich zeige | Wizard-Support |
|---|---|---|
| 0–5 | Tenant-Setup + Standard-Mix (ISO+DORA+BCM) | §6 Step 1 — perfekt, "N documents will be generated" Preview ist ein Verkaufsargument |
| 5–15 | Step 2 (Scope, Sites) + Step 3 (Rollen, Krisen-Team) | §6 Step 2/3 — solide, aber: Sites-Multi-Pick setzt voraus, dass `Location`-Entities schon da sind. Bei frischem Tenant leer → Sackgasse. Brauche "Quick-Add Location"-Inline |
| 15–30 | Step 4 (Risk Appetite, Schutzbedarf, Annex-A-Applicability) | §6 Step 4 — **hier verliere ich den CIO**. Annex-A ist 93 Controls. Ohne Industry-Preset oder "Skip — wir entscheiden später" stecke ich 15 Min in Erklärungen. Plan sagt "pre-fills from baselines if loaded" — wenn die Baseline aber nicht passt, ist das schlimmer als nichts |
| 30–40 | Step 5 (Crypto, RPO, RTO, DORA-Felder) | §6 Step 5 — DORA-Felder sind ok dokumentiert; Crypto-Algorithmen-Liste muss ich aus dem Bauch füllen, brauche "BSI-TR-02102-Default"-Knopf |
| 40–50 | Step 6 (Cadence) + Step 7 Generate | §6 Step 6/7 — gut, der "atomic transaction"-Hinweis ist beruhigend. Generate dauert hoffentlich <10 s, sonst Pitch-Killer |
| 50–60 | Approval-Inbox + erstes Doc öffnen, Inline-Editor | §9.2 — top, aber: das tatsächlich generierte Doc wird wie "Lorem-Ipsum mit Variablen" aussehen, wenn die Translation-Keys noch nicht legal-text-redigiert sind (siehe §15 Risk #1). Demo-Datenbestand muss in Sprint W3 priorisiert werden, sonst Pitch tot |

Wo ich mich entschuldigen muss: Annex-A-Step ohne Industry-Preset,
fehlender Word-Upload für "wir haben schon was", kein Live-PDF-Render
mit Tenant-Letterhead.

## Industry-preset gaps

Plan §12 listet vier Edge-Cases (Microenterprise / KRITIS / Cross-Border
/ GDPR-only). Das ist zu wenig. Aus 30 Onboardings/Jahr brauche ich:

| Sektor | Wer trifft drauf | Bundle muss enthalten |
|---|---|---|
| **Healthcare (Klinik / MVZ / Pflege)** | jeder 4. Mittelstand-Pitch in DE | § 22 BDSG-Block (DPO erwähnt es im Input §612), § 203 StGB-Verschwiegenheit, MDR/IVDR-Verweise wo Medizinprodukte, Patientenrecht-Sections in Privacy, BSI `APP.4.2` (SAP)/`APP.5.4` (KIS), ISO 27799-Verweis (optional), Notfall-RTO ≤4h für klinische Kernsysteme |
| **Public-sector / KRITIS (Stadtwerke, Behörden, Wasser/Strom)** | jeder 5. Pitch | BSIG § 8a/§ 8b Reporting-Block, KRITIS-VO Schwellwerte, BSI 200-2 Standard-Absicherung Default (nicht Basis), § 70 ff. BDSG, AT-LAW DSGVO-Modifikationen, IT-NotFall-Plan-Pflicht (BSIG § 8a Abs. 1a), Lieferanten-Klauseln Vergaberecht-konform |
| **B2C-SaaS-Startup (≤50 MA, GDPR-zentrisch)** | jeder 6. Pitch (Berlin/Hamburg-Cluster) | Microenterprise-Fork EXTENDED: kein BCM-Programme-Volltext, dafür Privacy + Cookie + DSR-Workflow als Kernset, AVV-Template-Generator, US-Sub-Processor-Liste mit Adäquanzbeschluss-Stand, Schrems-II-TIA-Methodik, sehr kurze Top-Level-Policy (3-5 Seiten) |
| **Manufacturing / OT (Maschinenbau, Industrie 4.0)** | jeder 4. Pitch | IEC 62443-Verweise zonal/conduit, OT/IT-Segmentation-Policy, BSI ICS-Security-Kompendium-Anker, Patch-SLA OT vs. IT getrennt (OT typisch wartungsfenster-gebunden), Remote-Wartung-Lieferanten-Policy, Safety-Security-Schnittstelle (IEC 61508 / ISO 13849 cross-ref) |
| **Multi-tenant SaaS-Provider (ICT-Third-Party)** | jeder 8. Pitch, sehr lukrativ | DORA CTPP-Flagging vorbereiten (§2 Non-Goal aktuell — aber Tenant-Self-Assessment "bin ich CTPP?"-Frage in Step 1 wäre billig), Mandantentrennung-Policy, Customer-Data-Egress-Policy, Sub-Processor-Notification-Workflow (Art. 28 DSGVO), Multi-Region-DR-Plan, SOC-2-Type-II-Bridge-Mapping (existiert vermutlich schon im Compliance-Modul) |
| **Financial Services (Versicherung / Bank / Zahlungsdienstleister)** | jeder 5. Pitch DACH | DORA-Vollausstattung default-on, BAIT/VAIT-Historie als Annex (siehe Memory: DORA löst BAIT ab — aber Auditoren fragen noch 2 Jahre danach), MaRisk AT 7.2 (Memory-Note: MRIS ≠ MaRisk! ist eigenes Framework), GwG-Logging-Klausel, Auslagerungsrichtlinie mit MaRisk-AT-9-Anker |
| **Bildung (Schulen / Hochschulen)** | jeder 12. Pitch | Art. 8 DSGVO + § 8 BDSG (DPO-Input §629), Landes-DSG-Verweise (variiert pro BL), elterliche Einwilligung <16, Lernplattform-AVV, Forschungsdaten-Privileg Art. 89 DSGVO |
| **HR-intensive (Personaldienstleister, Zeitarbeit)** | jeder 10. Pitch | § 26 BDSG, BetrVG § 87(1) Nr. 6 Werkzeug-Check (DPO §635), Bewerber-Tracking-Privacy, Leiharbeitsvertrags-Daten-Klausel |

Architektur sollte einen `IndustryPresetBundle`-Begriff einführen
(parallel zu `PolicyTemplate`), der: (a) Templates filtert/priorisiert,
(b) Default-Werte für `TenantPolicySetting` liefert, (c) Step 4
Annex-A-Applicability vorbelegt, (d) Step 5 SLAs sektor-typisch füllt
(OT-Patch-SLA ≠ IT-Patch-SLA). Plan §12 reicht nicht — das müssen
First-Class-Citizens sein. **→ neuer Open-Question-Punkt für Phase 4**.

## Hand-holding content

Plan setzt First-Time-Manager voraus (kein Junior-Implementer-Pfad
explizit definiert; Phase-3-Frage in §14 erwähnt es). Pflichtinhalte:

| Wizard-Step (§6) | Mandatory In-Product Content |
|---|---|
| **Step 1 Welcome** | 90-sek-Video "Was generiert dieser Wizard?", Vergleichstabelle ISO/BSI/DORA für Nicht-Standard-Erfahrene, "Bin ich Microenterprise?" Self-Check |
| **Step 2 Scope** | "Was gehört in eine Scope-Aussage?"-Tooltip mit 3 Beispielen (kleines, mittleres, Konzern-Tochter), Inline-Hilfe "Was ist der Unterschied zwischen Standort und Geltungsbereich?" |
| **Step 3 Rollen** | "Wer wird typischerweise CISO/ISB?" + Konflikt-Hinweis (DPO ≠ ISB, BSI-Quelle), Krisenteam-Standardbesetzung-Vorlage 5-7 Rollen mit RACI-Andeutung |
| **Step 4 Risk** | **HIER MAXIMAL-HILFE.** Risk-Appetite-Tier-Slider mit verbalisierter Beschreibung pro Stufe ("1=alle Restrisiken müssen GF-genehmigt sein"), Schutzbedarf-3-vs-4-Vergleich, Annex-A-Pre-Filter "Was trifft Sie nicht zu?"-Wizard-im-Wizard (5 Ja/Nein-Fragen → 12 Controls werden vorab als not-applicable markiert mit Begründungs-Vorschlag) |
| **Step 5 Operational** | Crypto-Algorithmen-"BSI-TR-02102-Default-übernehmen"-Knopf, RPO/RTO-Erklär-Diagramm, DORA-Significance-Self-Assessment-Wizard (§4 b DORA-VO Schwellen) |
| **Step 6 Cadence** | "12 Monate ist Standard, warum?"-Tooltip, Branchen-Cadence-Vergleich (Klinik kürzer, B2C länger zulässig) |
| **Step 7 Review** | "Was passiert nach Generate?"-Erklär-Card, "Ihre nächsten 5 Schritte"-Checkliste (CISO-Review, DPO-Cross-Check, Top-Mgmt-Bulk-Approval, Q1-Internal-Audit-Plan, BCExercise-Kalender) |

Nicht-funktionaler Pflichtinhalt: pro generiertes Dokument ein
"Auditor's Eye" Inline-Hint im Editor: "Ein externer Auditor wird hier
nach folgendem suchen: …" — schließt direkt §11.1 (3 mandatory tailoring
fields) auf und gibt dem ISB Sicherheit, was er reinschreiben muss.

## What I love (from a pitch angle)

1. **"In 30 Minuten von 0 auf 25 Policies"** — das ist der Money-Slide.
   Konkret messbar (§1.1), atomic Transaction (§6 Step 7), Re-Run-Idempotenz
   (§1.5). Verkauft sich gegen jedes Word-Template-Set.
2. **SoA-Verkopplung bidirektional (§8.1–§8.4)** — kein anderes Tool im
   Mittelstand-Segment macht das so sauber. "Policy generieren = SoA-
   Eintrag bekommt Evidence-Link" ist genau die Auditor-Story.
3. **Hierarchy-Override-Matrix (§7.3)** — Konzern-Mütter lieben das.
   "stricter_only" als Default ist juristisch wasserdicht und vermeidet
   das klassische Tochter-relaxiert-Konzern-Disaster.
4. **Bulk-Approval-Inbox + Review-no-change-Fast-Path (§9.2 / §9.4)** —
   Top-Mgmt-Friction ist die Nr.-1-Beschwerde nach 12 Monaten ISMS.
   Das adressiert genau diesen Schmerz.
5. **Immutability + Supersedes-Versioning (§10)** — Stage-2-Auditoren
   fragen JEDES Mal nach Version-History. Built-in zu haben spart
   2-3 Stunden Audit-Vorbereitung pro Re-Zertifizierung.

## What I dread (from delivery angle)

1. **"Wir haben schon 50 Word-Policies — was passiert damit?"**
   *Architektur schweigt komplett.* Plan §1.5 spricht nur von Wizard-
   Re-Run-Idempotenz, nicht von Pre-existing-Documents.
   → **Feature-Request:** Step 1.5 "Bestandsaufnahme": Tenant-Manager
   markiert vorhandene Documents als (a) "wegwerfen — Wizard
   überschreibt", (b) "behalten — Wizard skipped Topic", (c) "in den
   Wizard mergen — Wizard erzeugt neue Version mit Inhalts-Übernahme".
   Detail siehe Migration-Story unten.

2. **"Wir wollen unser Letterhead-PDF als Output."**
   *Architektur generiert nur `Document.content` Markdown (§8.6).* Kein
   Wort zu PDF-Render mit Branding.
   → **Feature-Request:** `TenantBranding` (Logo, Header/Footer-HTML,
   Schriftart, Farben) + PDF-Export-Service mit Wasserzeichen für
   `status=draft`. MUSS für Stage-1-Audit-Demo.

3. **"Wir haben 14 Tochtergesellschaften — wird ALLES nach unten gepusht?"**
   §7.2 sagt "every subsidiary gets the 24 topic policies offered" —
   das ist organisatorisch ein Meeting-Marathon. Konzern-CISO mit 14
   Töchtern × 24 Policies × Bulk-Approval = 336 Approve-Klicks.
   → **Feature-Request:** "Push-Down-Konfigurator" im Konzern-Defaults-
   Wizard: pro Template + pro Tochter eine Matrix-Zelle "auto-generate"
   / "subsidiary-decides" / "skip". Sonst eskaliert das.

4. **"Können wir zwei Policies zusammenfassen? Wir wollen Access + IAM
   in einem Dokument."**
   *§3 Standards-Matrix gibt fixe Counts (24 ISO Topics).* Kein Merge.
   → **Feature-Request:** `PolicyTemplate.canMergeWith` (FK-Liste).
   In Step 6 optional "Merge-Group"-Auswahl. Generator concatiniert
   Body-Sections mit shared-Header. Audit-Trail behält beide
   Template-IDs in `substitutionVariables`.

5. **"Auditor will die Privacy-Policy als eigenständiges Dokument —
   warum lebt sie als Section in einer anderen?"**
   §3 Anmerkung "10 of 16 privacy documents collapsed into sections" —
   das ist effizient, aber wenn Aufsichtsbehörde / DPO-Auditor das
   Dokument einzeln verlangt, brauche ich einen Re-Extract-Knopf.
   → **Feature-Request:** Per-Section "Auch als Standalone-Dokument
   generieren"-Toggle. Doppelter Doc-Eintrag, gleicher Inhalt, gleiche
   `wizard_run_id`-Tag — Single-Source bleibt, View doppelt.

6. **"Wir haben drei Standorte mit unterschiedlichen RPO-Anforderungen
   (DC1 = 4h, DC2 = 24h, Cloud-Backup = 1h)."**
   §6 Step 5 hat genau EIN RPO-Tier-Feld pro Tenant.
   → **Feature-Request:** Multi-Tier-Default in Step 5 mit Site-Override
   in `Location`-Entity. Generator rendert dann eine Tabelle in der
   Backup-Policy.

7. **"Unsere Konzernsprache ist Englisch, deutsche Tochter braucht aber
   DE-Versionen."**
   §8.6 sagt "both DE and EN bodies generated; UI-language picks one" —
   das ist gut, aber: bei Approval-Workflow (§9.1) muss ich klarmachen,
   welche Sprachversion genehmigt wird. Auditor fragt: "wer hat die DE
   gegen die EN gegengeprüft?"
   → **Feature-Request:** Approval pro Sprachversion (oder explizit
   "EN ist Master, DE ist Übersetzung — kein separater Approval"-Setting
   im TenantApprovalConfig).

8. **"Was kostet uns ein DORA-Update wenn die RTS final werden?"**
   §15 Risk #4 erwähnt das, aber kein Mechanismus.
   → **Feature-Request:** `PolicyTemplate.regulatoryWatchTag`. Wenn
   Tag flippt (Admin-Action), alle Documents mit dem Tag bekommen
   `review_due` mit Begründung "regulatory update — RTS-Final-Veröffentlichung".

## Onboarding flow recommendation

### Phase 1 — Pre-workshop (Woche -2 bis 0)

**Vor dem Workshop muss landen:**
- Tenant + Konzern-Tochter-Hierarchie (matched §7.1)
- Mindestens 1 `Location` (siehe Step-2-Hint oben)
- Mindestens 3 Rollen besetzt: GF/Top-Mgmt-Approver, CISO/ISB, DPO
  (§6 Step 3 Voraussetzung)
- Geladenes Compliance-Framework (ISO 27001:2022 zwingend, BSI-IT-GS-Kompendium
  optional, DORA-Catalog wenn Finanzsektor) — sonst sind Annex-A-
  Applicability + DORA-Article-Mapping in §6 Step 4 leer
- Industry-Preset gewählt (Phase-4-Open-Question)
- Optional: Maturity-Self-Assessment (vorhandenes Modul) — Output
  preselected Annex-A-Applicability

Architecture-Section-Anker: §6 Step 2 Sites-Multi-Pick, §6 Step 4
"pre-fills from baselines if loaded", §7.2 Konzern-Inheritance-Read.

### Phase 2 — Workshop runtime (Tag 1, 90 Min Wizard-Block)

Reihenfolge die ich live durchspielen will:

1. **Step 1** Welcome + Standard-Wahl + Industry-Preset (10 Min) — §6
2. **Step 2** Scope + Sites + Climate-Wording (10 Min) — §6
3. **Step 3** Rollen + Krisenteam (15 Min — der Kunde diskutiert hier
   immer am längsten "wer ist eigentlich unser CISO") — §6
4. **PAUSE / Café (10 Min)** — wichtig, sonst Step-4-Müdigkeit
5. **Step 4** Risk + Annex-A (20 Min mit Industry-Preset; ohne 40 Min) — §6
6. **Step 5** Operational Baselines (10 Min) — §6
7. **Step 6** Cadence (5 Min — schnell, Defaults nehmen) — §6
8. **Step 7** Review + Generate Live-Demo (5 Min) — §6
9. **Optional 15-Min-Block:** Bulk-Approval-Inbox-Walkthrough — §9.2

### Phase 3 — Post-workshop 30 Tage

| Woche | Aktion | Plan-Anker |
|---|---|---|
| W+1 | Top-Mgmt-Bulk-Approval-Termin (1 h, alle 25 Docs) | §9.2 |
| W+1 | DPO-Cross-Check Privacy-Touched-Docs | §9.1 step 3 |
| W+2 | CISO arbeitet die 3 Mandatory-Tailoring-Felder pro Doc ein | §11.1 |
| W+2 | Alva-Hint "Risk-Register seed leer — wollt ihr aus Asset-Inventory generieren?" | §13 Sprint W6 |
| W+3 | Erstes BCExercise nach §11.4 Auto-Schedule durchführen | §11.4 |
| W+4 | Internal-Audit-Programme-Doc reviewt + erste Audit gescheduled | §11.4 |
| W+4 | Re-Run Wizard-Diff-Check: was hat sich seit Generate geändert? | §10 |

## Sprint priority (consultant view)

Plan §13 Reihenfolge: W1 Domain → W2 ISO-Wizard → W3 Generation+SoA →
W4 BSI → W5 DORA+BCM → W6 Polish.

**Meine Reihenfolge** (was mein Pitch braucht):

| Eng-Sprint | Consultant-Sprint | Begründung |
|---|---|---|
| W1 Domain | W1 Domain | unverändert — ohne Entities geht nix |
| W2 ISO-Wizard-Core | **W2 ISO-Wizard-Core + Industry-Preset-Skeleton** | Preset-Begriff muss früh in `WizardRun.inputs`-Schema, sonst Re-Architecture in W6 |
| W3 Generation+SoA | **W3 Generation+SoA + PDF-Render-MVP + Word-Upload-Skeleton** | ohne PDF-Demo kein Pitch-Dokument; Word-Upload ist nicht-blockierend aber Skeleton jetzt einbauen |
| W4 BSI | **W4 Industry-Presets-Content (Healthcare, Public, OT, B2C-SaaS) + Hand-Holding-Content** | wichtiger als BSI-Templates, weil Healthcare/Public-Sector-Kunden nicht bis W4-BSI warten können. BSI-Delta verschiebe ich auf W5 |
| W5 DORA+BCM | **W5 BSI-Templates + DORA-Templates (parallel)** | BSI nachgezogen; DORA wegen Finanzsektor-Pipeline |
| W6 Polish | **W6 BCM + Polish + Konzern-Push-Down-Konfigurator + Migration-Tool** | BCM kann später, weil Onboarding-Workshop primär Top-Level + Topic-Policies braucht. Migration-Tool als Pflicht-Item |

Begründung für die Verschiebungen: ich verkaufe Industry-Presets, nicht
BSI-Schichten. Healthcare-/Public-Sector-Bundle macht 25 % meines Pitch-
Volumens aus, BSI-Standalone vielleicht 10 %.

## Migration story for existing tenants

**Problem:** ein Bestandskunde hat 50 Word/PDF-Policies bereits im
`Document`-Modul. Wizard generiert neue Drafts → Kollision /
Verwirrung.

**Architektur-Vorschlag** (fehlt aktuell in §1–§13 komplett):

1. **Pre-Wizard "Bestandsaufnahme"-Schritt** (neuer Step 0):
   - Listet alle existing Documents mit `documentType in [policy,
     programme, plan, methodology]`.
   - Pro Doc: Match-Vorschlag gegen verfügbare `PolicyTemplate.topic`
     (heuristisch via Title-Similarity / Tag-Match).
   - Manager wählt pro Doc:
     - **`replace`** — Wizard generiert neue Version, alte wird via
       `Document.supersedes` archiviert (immutable).
     - **`keep`** — Wizard skipped Template komplett; SoA-Link
       wird trotzdem aktualisiert (existing-doc → control).
     - **`merge`** — Wizard generiert Draft, importiert Plain-Text
       aus alter Doc als Pre-fill in Mandatory-Tailoring-Fields
       (§11.1) → Manager kürzt nur noch.
     - **`split`** — alte Doc enthält mehrere Topics, Wizard
       generiert pro Topic einen Draft, alte Doc bleibt als Index.

2. **Mapping-Persistenz:** `WizardRun.inputs.preExistingDocMapping`
   (json) speichert die Entscheidung, sodass Re-Run sich nicht erneut
   fragt.

3. **Audit-Tag:** Migration-betroffene Docs bekommen Tag
   `wizard-migration:<run-id>` zusätzlich zu §8.5-Tags.

4. **Word-Upload für `merge`:** wenn der Original-Inhalt nicht im
   `Document.content`-Feld liegt (sondern als Attachment), brauche ich
   einen Word/PDF-Text-Extractor → existing FileUploadSecurityService +
   Apache-Tika-Schnittstelle (oder PHPWord). Skeleton in W3 anlegen,
   Inhalt in W6.

5. **Bulk-Match-UI:** für 50 Docs muss das ein Listen-View mit
   Quick-Actions sein, nicht 50 einzelne Modals. Aurora `_fa_entity_card`
   list-view + Bulk-Action-Bar.

→ **Eigener Sprint W5.5 oder W6-Item** im Plan ergänzen. Ohne dieses
Feature kann ich den Wizard bei keinem Bestandskunden einführen, nur
bei Greenfield-Tenants. Das halbiert meinen adressierbaren Markt.

## Open questions for Phase 4

1. **An ISO 27001 + DPO Specialist:** Wie verhält sich der Policy-
   Wizard zum existing ISO-27701-Wizard (Memory-Note: ISO 27701 v1.5
   ist live)? §14 letzter Punkt erwähnt das schon — ich brauche eine
   konkrete UX-Antwort: ein Wizard mit Privacy-Toggle, oder zwei
   Wizards mit Cross-Reference, oder ein "Master-Wizard" der beide
   orchestriert?

2. **An ISMS + BSI Specialist:** Industry-Preset-Bundles als First-
   Class-Citizen — was ist die Mindest-Liste an Sektoren für v1?
   Mein Vorschlag oben (Healthcare, Public-Sector, B2C-SaaS, OT,
   Multi-Tenant-SaaS, Financial, Education, HR-intensive) ist 8 — zu
   viele für W4? Welche 4 sind MVP?

3. **An BCM Specialist:** Plan §11.4 sagt "Wizard auto-creates 12 BCExercise
   records". Was ist die Default-Verteilung (1/Monat? quartalsweise
   gestaffelt? Tabletop in M1, Walkthrough in M3, Functional in M6,
   Full in M12)? Ohne sinnvollen Default ist Auto-Create wertlos.

4. **An DORA Specialist:** Multi-Tenant-SaaS-Provider ist §2 Non-Goal
   ("DORA CTPP-mode v2"). Aber: kann der Wizard zumindest in Step 1
   ein Self-Assessment "bist du CTPP / wirst du CTPP-relevant?"
   einbauen, das im Output-Doc einen Disclaimer-Block setzt? Das
   schützt mich rechtlich beim Pitch und kostet wenig.

5. **An UX-Specialist (Phase 3 parallel):** Wenn die Migration-Story
   (Step 0 Bestandsaufnahme bei 50+ Docs) reinkommt — ist das ein
   eigener Pre-Wizard oder Step 0.5 im Haupt-Wizard? Bauchgefühl:
   eigenes Modul, weil 50-Doc-Mapping eine eigene Sitzung wert ist und
   nicht in den 30-Min-Wizard-Workflow passt.

---

**Bottom-line:** Plan ist solide gebaut, technisch sauber, aber zu
"Greenfield-optimistisch" und zu "ein Mittelstand wie der andere".
Wenn Industry-Presets, Migration-Story, PDF-Branding und Word-Upload
nicht in v1 reinkommen, kann ich den Wizard bei ~40 % meiner Pipeline
nicht einsetzen. Mit den oben skizzierten Ergänzungen ist es das
beste Onboarding-Tool im DACH-Mittelstand-Segment, das ich in den
letzten 5 Jahren gesehen habe.
