# CISO-Executive Review — Policy-Wizard Plan

> Review of `05-architecture.md` from the perspective of a Konzern-CISO
> (C-Level, regulated sector). Reviewer profile: 12 Toechter, BaFin- +
> NIS2-Scope, Reporting an Vorstand + Pruefungsausschuss, ~1.100 MA
> gesamt. Mix DE/EN bewusst.

---

## My profile

12 Toechter (8x DE, 2x AT, 1x NL, 1x PL), Holding ist Finanzdienstleister
unter BaFin-Aufsicht — also DORA + MaRisk + ISO 27001 + NIS2 fuer drei
der Toechter. Reporting an CEO + Pruefungsausschuss alle 6 Monate, ad-hoc
bei Material-Incidents. Mein groesster Painpoint heute: ich habe sechs
verschiedene Policy-Sets in vier Toechtern, nichts ist konsistent, jeder
externe Auditor findet eine andere Luecke, und meine Konzern-ISMS-Dame
schreibt seit 18 Monaten an einer einheitlichen Top-Level-Policy, die
keine Tochter umsetzt.

## Strategic verdict (TL;DR)

**Conditional Yes.** Ich wuerde das Feature finanzieren, aber NUR wenn
zwei Punkte vor Sprint W6 geloest sind: (a) das Konzern-Defaults-Wizard
(§7.3) muss ZUERST kommen, nicht in Sprint W6 hinten ran, sonst rollen
Toechter unkontrolliert aus; und (b) das Bulk-Approval (§9.2) braucht
ein robustes 4-Augen-Default fuer alles >ISO Cl. 5.2 — sonst sehe ich
mich vor dem Pruefungsausschuss erklaeren, warum die GF "25 Policies in
einer Sitzung durchgewinkt" hat. Der Audit-Trail ist solide, das
Re-Generation-Versioning ist sauber gedacht — aber der Plan
unterschaetzt den politischen Sprengstoff von Top-Mgmt-Signoff in der
Praxis.

## What I love

- **§9.4 Review-without-changes Fast-Path** — das ist der einzige
  Mechanismus, der mich davor rettet, jaehrlich 25 Policies durch 4
  Approval-Stufen zu schicken obwohl sich nichts geaendert hat. Sehr
  gut: der Fast-Path bleibt CISO-only und zieht GF nicht rein.
  `reviewWithoutChangesAutoCompletes=true` als Default ist mutig und
  richtig.

- **§8.1-8.4 SoA-Bidirektionalitaet im selben DB-Transaction-Block** —
  wenn das atomar klappt, habe ich endlich einen Auditor-Beweis "diese
  Policy deckt diesen Control ab" ohne manuellen Mapping-Aufwand.
  Besonders wertvoll: §8.4 Cascade durch framework-inheritance —
  generiert ein Tochter-Wizard eine Policy, die Konzern-loaded-Framework
  hat, sehe ich's automatisch im Konzern-Roll-up.

- **§10 Immutability nach Approval + supersedes-Verkettung** —
  juristisch sauber. Wenn der Auditor 2027 fragt "wie sah eure
  Backup-Policy am 1.3.2026 aus", kann ich den `v2`-Document mit
  Hash-Snapshot zeigen, nicht eine wackelige Git-History.

- **§8.5 Tagging-Schema** (`policy-wizard-generated`, `standard:dora`,
  `wizard-run:<id>`) — der externe DORA-Auditor kann sich seine
  Evidence-Liste selbst filtern, ich muss kein Power-Point bauen.
  Plus `dora-validity:2025-01-17` als Tag fuer die Stichtags-Frage
  "war die DORA-Policy zum 17.01.25 in Kraft" ist clever.

- **§11.1 drei verpflichtende Tailoring-Felder pro Topic** — exakt
  meine Erfahrung: jeder Auditor riecht eine Standard-Vorlage in
  Sekunden. Wenn die Wizard-UX die Felder hart blockt
  (`status=ready_for_review` erst freigegeben), bekommen wir das
  "templated feel"-Problem klein.

- **§4.1 `inheritedFromTenant_id` + `overrideMode`** — das macht im
  Datenmodell sichtbar, was bei mir heute in PowerPoint steht. Wenn
  ich einer Tochter-CISO erklaere "du darfst Risk-Appetite NIE
  lockerer als Konzern setzen", kann sie's im Tool sehen statt in
  einer 200-Seiten-PDF zu suchen.

## What worries me

**1. Bulk-Approval ohne hartes 4-Augen-Default (§9.2).** Die
`bulk_approval_dual_signoff: false` als Default ist gefaehrlich. Ein
CFO klickt am Freitag Nachmittag 25 Policies durch und der erste
Auditor im Q1 fragt "wer hat das im Detail gepruft?". Antwort heute
laut Architektur: niemand, ein Mensch hat eine Sammelfreigabe
gegeben. Bei DORA-Scope erwartet die BaFin explizit dokumentierte
**individuelle** Top-Mgmt-Auseinandersetzung mit IKT-Risiken.
**Mitigation:** Default umdrehen — `bulkApprovalDualSignoff=true` fuer
alle Tenants mit `dora_in_scope=true` oder `bafin_regulated=true`.
Dual-Signoff darf bei nicht-regulierten Tenants optional sein, nicht
umgekehrt.

**2. §9.3 zu viele Knoepfe, kein Audit-Default-Profil.** Sieben
Settings (`topLevelPolicyApprovers`, `topicPolicyApprovers`,
`topicPolicyEscalationToTopMgmt`, ...) sind im Konzern mit 12 Toechtern
ein Kombinatorik-Albtraum. Eine Tochter-CISO setzt etwas falsch und
ich erfahre es erst beim Audit. **Mitigation:** Ship vier
**Sektor-Presets** ("Konzern-Finanzdienstleister", "KMU-ISO-only",
"Public-Sector-KRITIS", "Healthcare-GDPR"), die diese 7 Settings
konsistent vorbelegen. Konzern-CISO setzt Preset, Tochter erbt,
fertig. Free-Form-Konfig nur fuer SUPER_ADMIN.

**3. Liability-Frage fuer falsch generierte Policies — gar nicht
adressiert (§11).** Die Tailoring-Felder sind gut, schuetzen aber
nicht vor dem Fall: Template enthaelt veraltete Rechtslage (z.B. ein
NIS2-Artikel-Zitat aendert sich), Wizard stempelt es in 12 Tochter-
Policies, GF unterschreibt, BaFin findet Falschinformation.
**Mitigation:** §10-Versioning erweitern — bei `PolicyTemplate.version`
Bump muss eine **Re-Acknowledge-Pflicht** auf alle abgeleiteten
approved Documents kommen, mit T-90d Frist und Eskalation an
Konzern-CISO. Heute steht in §10 nur "draft documents flagged for
re-review", approved bleibt ungestoert.

**4. §7.3 Override-Matrix matcht nicht meine Realitaet.** Die Tabelle
listet 6 Beispiel-Settings, aber bei mir gibt's 30+: HR-Logging-Cadence
(BetrVG-Verhandlung pro Tochter unterschiedlich), Krypto-FIPS-Pflicht
(US-Tochter ja, EU-Tochter nein), Datenklassifizierung (4 vs 3 Stufen
historisch gewachsen). Plan suggeriert "stricter_only" als universelles
Pattern — funktioniert bei numerischen Werten, nicht bei
Schema-Entscheidungen. **Mitigation:** Override-Mode-Liste explizit auf
"strukturelle" (Schema, Liste, Boolean) vs "numerische" (Skalare)
Settings aufteilen, fuer strukturelle nur `forbidden` oder `free`,
nicht `stricter_only`. Sonst Bugs in der Resolver-Logic.

**5. Translation-Quality-Risiko fuer Privacy + DORA (§15).** 7000 DE+EN
Keys, Outsource an Legal-Agency klingt vernuenftig — aber wenn ein
falsch uebersetzter Privacy-Policy-Satz zu €20M GDPR-Fine fuehrt,
trage ich die Verantwortung, nicht die Agency. **Mitigation:** Pro
Sprache und Standard ein **Legal-Counsel-Approval-Gate** in Sprint W3
einbauen, NICHT nur "translation-quality sweep" in W6. Plus: jedes
Document muss vor Publish einen `legal_review_required` Flag fuer den
Tenant tragen (default ON fuer Privacy/DORA-Scope, OFF fuer reine
ISO-Tenants). Heutiger Plan vermischt linguistic QA mit legal QA.

**6. §6 Step 6 "Approver designation per document" — das ist meine
Hoelle.** Eine Tochter-CISO oeffnet Step 6 und muss 25 mal "Approver"
auswaehlen. In der Realitaet sagt sie: "alles CISO". Dann hat sie sich
selbst als Approver fuer ihre eigene Risiko-Akzeptanz-Policy gesetzt —
4-Augen-Verstoss. **Mitigation:** Step 6 darf "Approver per Document"
nur als Override anbieten, NIE als initial-empty Pflichtfeld. Default
muss aus `topicPolicyApprovers`-Setting kommen + Wizard erkennt, wenn
Approver = Wizard-Initiator, und blockt mit "self-approval forbidden".

**7. §7.1 "ROLE_GROUP_CISO sets baseline" — nicht meine Realitaet.**
In meinem Konzern setzt der Konzern-CISO Baselines NICHT alleine — der
**Konzern-Compliance-Officer** und ich ziehen gemeinsam. Das Tool muss
eine 2-Personen-Konstellation am Konzern-Tier unterstuetzen, sonst
mache ich's per Stellvertreter-Account, was schlecht fuer Audit-Trail
ist. **Mitigation:** Konzern-Defaults-Wizard (§7.3 letzter Absatz)
muss ein 4-Augen-Approval haben — `ROLE_GROUP_CISO` setzt vor,
`ROLE_GROUP_COMPLIANCE` (oder ein zweiter Konzern-Top-Role) bestaetigt.

**8. §15 keine Aussage zu Performance + Concurrency.** Wenn 12
Toechter parallel ihren Wizard laufen lassen (was bei einem
zentral-getriggerten "alle bis 30.06.26 fertig"-Initiative passiert),
schreiben 12 WizardRuns gleichzeitig in `TenantPolicySetting` und
ziehen aus `PolicyTemplate`-Cache. Der Plan sagt nichts zu
Lock-Strategie oder Read-Replica. **Mitigation:** Phase-4-Frage an
ISMS-Specialist + UX: Soft-Lock auf Tenant-Level (nur ein offener
WizardRun pro Tenant), und Konzern-Defaults-Lese-Pfad explizit als
Cached-Resolver bauen.

## What's missing

- **Board-Reporting / KPI-Surface.** Kein Wort darueber, wie mein
  Pruefungsausschuss den Wizard-Output sieht. Ich brauche: "X von Y
  Toechtern haben Policy-Set vollstaendig", "N Policies sind ueberfaellig
  zur Review", "Avg. Approval-Cycle-Time pro Policy-Topic". Ohne diese
  3 KPIs ist das Tool fuer mein Stakeholder-Management 80%-wertlos.
  **Erforderlich:** ein eigener Sprint-Block oder mindestens ein
  Konzern-Roll-up-Dashboard in Sprint W6.

- **Witnessing fuer sensible Policies.** §9.2 erwaehnt 4-Augen optional,
  aber bei ISO Cl. 5.2 Top-Level-Policy + DORA Risk-Mgmt-Framework
  brauche ich verpflichtend ZWEI namentlich genannte Top-Mgmt-Personen
  + Datum + Standort der Unterzeichnung. Das ist heute nicht im
  Approval-Trail-Widget (§9.6) sichtbar. **Erforderlich:** Witness-Feld
  pro `top_mgmt_signoff`-Step + Anzeige im Approval-Trail.

- **Mobile-Sign-Off.** Mein CFO + CEO sitzen nie am Desktop wenn
  Policies kommen. Wenn Bulk-Approval-Inbox nicht mobile-ready ist,
  warte ich 4 Wochen auf einen Termin. **Erforderlich:** Step in
  Sprint W6 explizit "responsive Bulk-Approval-Inbox + Push-Notification
  via existierende Notification-Infrastruktur".

- **Annual Renewal-of-Applicability Ritual.** ISO 27001 Cl. 9.3
  Management Review erwartet jaehrlichen Beschluss "Policy-Set ist
  weiterhin angemessen". Der Wizard kann einmal generieren, aber wo
  ist der jaehrliche **Ritual-Trigger**, der mich + GF zwingt,
  systematisch durchzulaufen? **Erforderlich:** ein
  "Annual-Applicability-Review"-Wizard-Variant, der am Geburtstag des
  Wizard-Runs feuert und die `topicPolicyApprovers` durch eine
  Lightweight-Bestaetigung schickt (kein Re-Generate, nur "bestaetigt
  weiterhin angemessen").

- **GRC-Tool-Integration / Export.** Falls Konzern-Mutter ein
  bestehendes GRC-System nutzt (verbreitet bei BaFin-Pflichtigen),
  brauche ich strukturierten Export der generierten Policies +
  SoA-Mapping per API. Heute null Aussage zu Export-Format.
  **Erforderlich:** OSCAL-Export oder zumindest JSON-Schema-Export
  des Document+SoA-Bundles als API-Endpoint, dokumentiert fuer
  Sprint W6.

- **Incident-Trigger-on-Policy-Failure.** Wenn eine generierte Policy
  6 Monate nach Publish nicht durch Awareness-Training-Coverage erreicht
  wird (existiert kein Training-Material dafuer im Awareness-Modul),
  muss das ein Konzern-CISO-Hint aufmachen — sonst habe ich Policies,
  die niemand kennt, ein klassischer Audit-Finding-Generator.
  **Erforderlich:** Alva-Hint-Rule "Policy ohne Training-Coverage nach
  90 Tagen" (passt zur bestehenden Alva-Hint-Foundation aus Memory).

## Sprint priority (my order)

Aktuelle Reihenfolge (W1 Domain → W2 ISO → W3 Generation → W4 BSI →
W5 DORA+BCM → W6 Polish) ist Engineering-getrieben. Aus CISO-Sicht:

1. **Sprint W1 — Domain** (unveraendert — Foundation-Pflicht)
2. **Sprint W2 — Wizard Core (ISO 27001 only)** (unveraendert)
3. **NEU: Sprint W3-Konzern — Konzern-Defaults-Wizard + Hierarchie-
   Validator + Sektor-Presets.** Vorgezogen aus W6. Grund: ohne
   Konzern-Defaults rollen Toechter unkontrolliert aus, und ich kann
   das Tool nicht freigeben. Sektor-Presets entschaerfen §9.3-Sorge.
4. **Sprint W3-Doc — Document-Generation + SoA-Link** (entspricht
   altem W3, nur einen Sprint nach hinten)
5. **Sprint W4 — DORA-Addon** (vorgezogen vor BSI! Grund: BaFin-Druck
   2025, BSI-Kompendium-2025-Edition-Drift macht BSI-Templates
   eh provisorisch — DORA-Pflicht zuerst)
6. **Sprint W5 — BSI Grundschutz + BCM** (zusammengezogen, BCM ist
   ohnehin BSI-200-4-naheliegend)
7. **Sprint W6 — Privacy/GDPR-Sections + Konzern-Roll-up-Dashboard +
   Mobile-Bulk-Approval + OSCAL-Export.** GDPR-Sections koennen
   warten weil viele meiner Toechter nicht GDPR-Hauptscope sind;
   Dashboard + Export sind aus C-Level-Sicht **Sprint-W6-Pflicht**,
   nicht "Polish".

Falls W3-Konzern nicht reinpasst: Konzern-Defaults-Wizard MUSS
spaetestens parallel zu W2 als Tracer-Bullet existieren, sonst ist
W2-ISO-Output fuer Konzerne unbrauchbar.

## Open questions for the specialists

1. **An ISMS-Specialist (ISO 27001):** §9.4 Fast-Path "review without
   changes" auto-completes ohne Top-Mgmt — ist das ISO 27001 Cl. 5.2
   konform? Cl. 5.2 sagt "top management shall ensure ... reviewed".
   Reicht eine jaehrliche Sammelbestaetigung im Management-Review, oder
   muessen GF jede Policy einzeln re-acknowledgen, auch wenn unveraendert?

2. **An DORA-Specialist:** §9.2 Bulk-Approval-Default ohne 4-Augen — in
   wie weit verletzt das DORA Art. 5(2) "ICT risk management framework
   shall be approved ... by the management body"? Reicht eine
   gemeinsame Sitzungsentscheidung, oder erwartet die ESA pro
   Framework-Document einen Einzelbeschluss?

3. **An BCM-Specialist (ISO 22301):** §11.4 "Auto-create 12 BCExercise
   records" — wer ist Owner dieser Records? Der CISO? Crisis-Team-Lead?
   In meiner Realitaet zerschiesst eine ungeteilte Owner-Default-Wahl
   die Plan-Vorbereitungen, weil niemand sich zustaendig fuehlt. Brauche
   einen Ownership-Mapping-Prozess vom Wizard.

4. **An DPO-Specialist:** §10 "approved Documents reference v1
   translation keys forever". Wenn die GDPR-Aufsicht die Privacy-Policy
   pro Tenant in DE/EN+ einer dritten Sprache (NL/PL) verlangt und ich
   v1 nachtraeglich um eine NL-Variante erweitern will — bleibt v1
   immutable und ich muss v2 generieren obwohl der Inhalt identisch
   ist? Das ist auditorisch unschoen ("warum gibt's plotzlich v2 ohne
   Changes?").

5. **An BSI-Specialist:** §12 "BSIG §8a 2-year audit cadence; wizard
   sets reviewIntervalMonths=12 (defensive default)" — bei meiner
   Versicherungs-Tochter im KRITIS-Scope ist das richtig, aber bei
   meiner reinen Vertriebs-Tochter ohne KRITIS-Pflicht ist 12 Monate
   uebertrieben (Aufwand). Kann der Wizard ueber `tenant.kritis_scope`
   und `tenant.bsig_paragraph_8a_applicable` Booleans differenzieren,
   damit nicht-KRITIS-Toechter 24-Monate-Cadence kriegen?
