# ISB-Review: Data-Reuse Improvement Plan

> **Review-Gegenstand:** `docs/DATA_REUSE_IMPROVEMENT_PLAN.md`
> **Review-Datum:** 2026-04-17
> **Review durch:** ISB / Security Officer (intern)
> **Anlass:** CM-Freigabe unter Auflagen erteilt → Plan prüft, ob Umsetzung auditfähig bleibt und Norm-Konformität gewahrt ist.
> **Prüfgrundlage:** ISO 27001:2022, ISO 27002:2022, ISO 19011:2018, EU-NIS2, EU-DORA, DSGVO.

---

## Gesamteinschätzung

Der Plan adressiert reale Lücken und ist strukturell belastbar. **Drei Feststellungen mit Major-Charakter** erzwingen jedoch Plan-Nachzug vor Sprint 1 — andernfalls Risiko für Nicht-Konformität in der nächsten Überwachung bzw. Rezertifizierung.

**Klassifikation analog ISO 19011 Kap. 6.4.8:**
- **3 Abweichungen (Major)** — Pflicht zur Behebung vor Sprint-1-Start.
- **6 Abweichungen (Minor)** — Behebung bis Ende Sprint 2.
- **4 Beobachtungen (Observation)** — als Verbesserungshinweis, kein Blocker.

**Positive Feststellungen** (zur Balance): Definition-Fulfillment-Trennung ist state-of-the-art, Import-Round-Trip-Prinzip korrekt, E2E-Testszenario praxisnah, Consultant-Exit sauber terminiert.

**Freigabeempfehlung an CM:** **Bedingte Freigabe**. Sprint 1 darf starten, sobald MAJOR-1 bis MAJOR-3 durch Plan-Änderungen adressiert sind. Minor dürfen sprintbegleitend behandelt werden.

---

## Major-Abweichungen (Pflicht vor Sprint 1)

### MAJOR-1 · Begriff "Auto-Fulfillment" ohne Pflicht-Review — NC-Risiko
**Betroffen:** WS-1 (Auto-Fulfillment aus Mapping).
**Norm-Bezug:** ISO 27001:2022 Klausel 9.1 (Überwachung, Messung), 8.1 (Betriebliche Planung) und A.5.36 (Einhaltung von Richtlinien und Normen).

**Feststellung:** Der Plan verwendet durchgehend die Begriffe *"Auto-Fulfillment"*, *"automatisch abgeleitet"*, *"auto-progress"*. In der externen Auditsituation triggert jede "Auto-Entscheidung" ohne dokumentierte Prüfung sofort die Frage *"Zeigen Sie mir die menschliche Validierung."* Fehlt diese, ist das eine **Major-NC** — nicht weil die Logik schlecht wäre, sondern weil Nachweis der Prüfung fehlt.

**Abweichung konkret:**
- Abgeleitete Fulfillments gehen laut Plan sofort mit Prozentwert in den Bestand, Status-Change ohne Human-in-the-Loop.
- Override-Metadaten bleiben erhalten — gut, aber **der Default-Zustand "abgeleitet ohne Review"** ist die eigentliche Angriffsfläche.

**Korrektur verpflichtend:**
1. **Sprachlich:** Alle Vorkommen von *"Auto-Fulfillment"* → **"Mapping-basierter Ableitungsvorschlag mit Review-Pflicht"** (Plan-weit, inkl. Screens).
2. **Zustandsmodell:** Abgeleitete Fulfillments starten in neuem Status `inherited_pending_review`. Erst nach aktiver Bestätigung (Pflichtfeld *Prüfer-Kommentar* mit mind. 20 Zeichen, 4-Augen bei Status "implemented") wird der Wert zum offiziellen Fulfillment.
3. **KPI für ISB:** Anzahl `inherited_pending_review` als Badge am Compliance-Trigger — so dass offene Ableitungen nicht übersehen werden (passt zu WS in `NAVIGATION_IMPROVEMENT_PLAN.md` Phase 5.2).
4. **Audit-Trail-Eintrag** pro Vorschlag-Erzeugung und pro Bestätigung/Ablehnung — getrennte Einträge, nicht nur ein "update".

### MAJOR-2 · Stichtag-Reproduzierbarkeit bei Mapping-Änderung nicht garantiert
**Betroffen:** WS-4 (Cross-Framework-Management-Report), WS-7 (Scheduled), WS-1 (Inheritance).
**Norm-Bezug:** ISO 27001:2022 Klausel 7.5.3 (Lenkung dokumentierter Information) — *"Änderungskontrolle (z.B. Version, Datum)"* und A.5.32 (Änderungsmanagement für Informationsgüter).

**Feststellung:** Plan nennt *"Stichtag: Bericht reproduzierbar zu beliebigem historischen Datum"* als Akzeptanzkriterium von WS-4. Diese Zusage ist nicht haltbar, solange der **Mapping-Katalog selbst nicht versioniert** ist. Wenn ein Mapping 27001-A.8.16 → NIS2-Art.21(2)(a) von 70 % auf 85 % korrigiert wird, würde ein rückwirkender Report zum 31.12. mit *neuem* Mapping rechnen — das ist keine historische Reproduzierbarkeit, das ist Geschichtsrevision.

**Korrektur verpflichtend:**
1. **Versionierung** auf `ComplianceMapping` ergänzen (Plan-Erweiterung): `version: int`, `validFrom: datetime`, `validUntil: ?datetime`. Eine Änderung erzeugt neuen Datensatz, alter bleibt inaktiv erhalten.
2. **Stichtag-Parameter auf Mapping-Ebene anwenden:** Report-Service liefert Mapping-Set *valide zum Stichtag*, nicht das aktuelle.
3. **Inheritance-Log** muss `mapping_version_used` speichern, nicht nur `mapping_id` — sonst kann man die damalige Ableitungs-Logik nicht rekonstruieren.
4. **Akzeptanzkriterium ergänzen** (WS-4): *Änderung eines Mappings nach Stichtag X ändert keinen Report mit Stichtag ≤ X.*

### MAJOR-3 · Funktionstrennung & Berechtigungsmodell nicht geregelt
**Betroffen:** WS-1 (Override), WS-2 (Import-Commit), WS-5 (Bulk-Tagging), WS-6 (FTE-Override).
**Norm-Bezug:** ISO 27001:2022 A.5.3 (Funktionstrennung / Segregation of Duties), A.5.15 (Zugriffskontrolle), A.5.18 (Zugriffsrechte).

**Feststellung:** Der Plan spricht von Änderungen, die Ableitungen, Imports und Tag-Strukturen großflächig beeinflussen können — ohne festzulegen, **welche Rolle welche Aktion ausführen darf** und **ob 4-Augen-Prinzip gilt**. Diese Lücke ist im Audit regelmäßig der erste Fragenkreis nach dem Thema "Zugriffsrechte".

**Korrektur verpflichtend (Pflicht-Ergänzung in Plan, Anhang "Rollenmatrix"):**

| Aktion | USER | AUDITOR | MANAGER | ADMIN | 4-Augen? |
|---|---|---|---|---|---|
| Framework aktivieren (Trigger Mapping-Ableitung) | — | — | lesen | ausführen | empfehlen |
| Ableitungsvorschlag bestätigen | — | lesen | ausführen | ausführen | **ja** (Ersteller ≠ Freigeber) |
| Ableitungsvorschlag ablehnen | — | lesen | ausführen | ausführen | nein |
| Manuellen Override setzen | — | lesen | ausführen | ausführen | **ja** |
| Import-Commit | — | — | ausführen | ausführen | **ja** ab 50 Zeilen |
| Bulk-Tag hinzufügen | — | lesen | ausführen | ausführen | nein |
| Bulk-Tag entfernen (rückwirkend) | — | lesen | — | ausführen | **ja** |
| FTE-Schätzung überschreiben | — | lesen | ausführen | ausführen | nein (aber Log) |

**Zusätzlich erforderlich:**
- Rollen-Voter (Symfony `Voter`) pro kritischer Aktion.
- Audit-Log-Eintrag MUSS `actor_user_id`, `actor_role`, optional `four_eyes_approver_id` enthalten.

---

## Minor-Abweichungen (bis Ende Sprint 2)

### MINOR-1 · Import-Audit-Trail pro Session reicht nicht
**Betroffen:** WS-2. **Norm:** ISO 27001:2022 A.8.15 (Protokollierung).

Import-Log muss **pro Zeile** nachweisen: Vor-Zustand, Nach-Zustand, Quelle (Dateiname+Hash), Entscheidung (übernehmen/skip/merge). Session-Level-Summary ist für "Zeigen Sie mir wie Requirement NIS2-3.2 am 12.08. entstanden ist" unzureichend.

**Korrektur:** `ImportRowEvent` pro Zeile neben `ImportSession`.

### MINOR-2 · Tag-Entfernung ohne Historie zerstört Scope-Rekonstruktion
**Betroffen:** WS-5. **Norm:** ISO 27001:2022 Klausel 4.3 (Scope), 7.5 (Dokumentierte Information).

Wenn ein Framework-Tag entfernt wird, muss das Ereignis auditierbar sein. Fragestellung im Audit: *"Zum Stichtag 30.06. — welche Assets waren NIS2-relevant?"* Ohne Tag-Historie nicht beantwortbar.

**Korrektur:** Tagging als zeitbezogene Relation (`tagged_from`, `tagged_until`, `removed_by`, `removal_reason`). Nie hartes Delete.

### MINOR-3 · FTE-Override ohne Begründungspflicht
**Betroffen:** WS-6. **Norm:** ISO 27001:2022 A.5.36.

FTE-Schätzung tenant-intern zu ändern ist legitim, aber **warum** muss dokumentiert sein — sonst Streit mit Auditor, wenn diese Zahl in Managementbewertung einfließt.

**Korrektur:** Pflichtfeld `override_reason: text (min 20 chars)` bei tenant-spezifischer FTE-Anpassung. Audit-Log-Pflicht.

### MINOR-4 · Scheduled-Report-E-Mail ohne Datenschutz-Regelung
**Betroffen:** WS-7. **Norm:** DSGVO Art. 32, ISO 27001:2022 A.5.34 (Datenschutz).

Versand von Compliance-Status-Reports per E-Mail: Empfänger-Kreis, Transport-Verschlüsselung, Inhalte-Minimierung.

**Korrektur:**
- Empfänger beschränkbar auf tenant-interne User mit Rolle ≥ MANAGER.
- Betreff und Preview-Text OHNE inhaltliche Details (generisch).
- Anhang statt Inline-Body; TLS-Versand vorausgesetzt (Symfony-Mailer-Config-Check).

### MINOR-5 · Wording "automatisch" plan-weit zu überarbeiten
**Betroffen:** WS-1, WS-8, teils WS-4.

Neben MAJOR-1 betrifft das alle Folgestellen. Empfohlene Ersetzungen:

| Alt | Neu |
|---|---|
| "Auto-Fulfillment" | "Mapping-basierter Ableitungsvorschlag" |
| "automatisch abgeleitet" | "als Vorschlag abgeleitet, Review-Pflicht" |
| "Tool rechnet Delta" | "Tool zeigt Delta zur Prüfung" |
| "auto-progress" | "regelbasierte Fortschrittserkennung mit Bestätigung" |

**Korrektur:** Plan-weites Search-Replace mit anschließendem Re-Read.

### MINOR-6 · DORA-ROI-Exportformat nicht normgenau
**Betroffen:** WS-3. **Norm:** DORA Art. 28; EBA/EIOPA/ESMA Final Draft ITS on ROI.

Plan schreibt *"DORA-Report generiert ROI-Export aus Supplier-Daten"*. Das ITS definiert präzise Spalten, Datentypen, Referenzen (LEI, NACE-Code, Substitutierbarkeitsskala). Free-Form-Export ist nicht aufsichtskonform.

**Korrektur:** Export-Schema gegen veröffentlichte ITS-Vorlage validieren, bevor WS-3 als abgenommen gilt. CSV-Header und Feldformate im Akzeptanzkriterium fixieren.

---

## Beobachtungen (OFI — Opportunity for Improvement)

### OBS-1 · Qualitätssicherung der Consultant-Mapping-Kataloge
**Bezug:** WS-1, WS-2. Die Kataloge bestimmen die Ableitungsqualität. **Empfehlung:** Consultant-Vertrag enthält Klausel zur Mapping-Herkunft (Quelle: z.B. "BSI-Kreuzreferenztabelle 27001-2022 ↔ NIS2" oder "eigene Methodik") und Haftungs-Rahmen. Tool sollte pro Mapping das Feld `source: string` unterstützen (z.B. "BSI-Kreuzreferenz v1.2", "Consultant: XY GmbH v2025-03").

### OBS-2 · Input für nächste Managementbewertung (9.3) fehlt
Plan ignoriert, dass die Umsetzung ein signifikanter Management-Input-Punkt ist: *"Änderungen in externen und internen Themen, die für das ISMS relevant sind"* (9.3.2 c), sowie *"Chancen zur fortlaufenden Verbesserung"* (9.3.2 g). **Empfehlung:** Plan-Abschluss ergänzt um Abschnitt "Input in Managementbewertung" mit Stichpunkten.

### OBS-3 · Scope-Abgrenzung (4.3) nicht adressiert
Framework-Tags (WS-5) könnten Scope-Information tragen (`in-scope/out-of-scope` als zusätzlicher Tag-Typ). Wäre Bonus-Nutzen, würde ISO 27001 Klausel 4.3 besser unterstützen. **Empfehlung:** In WS-5 als optionale Erweiterung aufnehmen.

### OBS-4 · Dieser Review-Prozess selbst nicht standardisiert
Für künftige Plan-Reviews ähnlicher Art wäre eine **ISB-Review-Vorlage** (als Template) sinnvoll. **Empfehlung:** Aktuelles Dokument als `docs/templates/PLAN_REVIEW_TEMPLATE.md` generalisieren (Major/Minor/Obs-Raster, Norm-Referenz-Spalte, Freigabe-Block).

---

## Positive Feststellungen (zur Balance)

| # | Aspekt | Warum auditfreundlich |
|---|---|---|
| P1 | Definition-Fulfillment-Trennung (`ComplianceRequirement` vs. `ComplianceRequirementFulfillment`) | Saubere Multi-Tenancy, verhindert Datenmischung zwischen Organisationen. |
| P2 | Inheritance-Metadaten-Design (`derivedFrom`, `overriddenAt`, `overrideReason`) | Mit Ergänzung der Pflicht-Review-Logik (siehe MAJOR-1) audit-tauglich. |
| P3 | Import-Round-Trip (Export-Format = Import-Format) | Löst Tool-Wechsel- und Migrations-Anforderung klassischer Auditfragen. |
| P4 | E2E-Testszenario "27001 → NIS2" | Praxisnah, testet echten Compliance-Manager-Workflow. |
| P5 | Consultant-Exit nach Sprint 4 | Verhindert dauerhafte Fremdabhängigkeit, interne Audit-Souveränität. |
| P6 | Stichtag-Anforderung in WS-4 | Richtige Absicht — muss nur wie in MAJOR-2 technisch abgesichert werden. |
| P7 | Klare Ampel-Darstellung (grün/gelb/rot) + Icon | Unterstützt unmittelbar die Auditor-Lesbarkeit im Walk-Through. |

---

## Abnahme-Empfehlung an Compliance-Manager

**Empfehlung:** **Bedingte Freigabe für Sprint-Start** unter folgenden Voraussetzungen:

1. **MAJOR-1, MAJOR-2, MAJOR-3** sind vor Sprint-1-Start in den Plan eingearbeitet (Version +1, Änderungshistorie am Dokumentende).
2. **Rollenmatrix (MAJOR-3)** wird als Anhang im Plan aufgenommen.
3. **Mapping-Versionierung (MAJOR-2)** wird als Entity-Ergänzung im WS-1-Umfang mitgeschätzt (Zusatz-Aufwand: ~1,5 FTE-Tage, Gesamt-WS-1 dann 5,5–7,5 Tage).
4. **MINOR-1 bis MINOR-6** sind bis Ende Sprint 2 abzuschließen, Fortschritt im Sprint-Review zeigbar.
5. **Wording-Überarbeitung (MINOR-5)** als erster Plan-Commit — das ist eine 30-Minuten-Aufgabe und entkräftet den größten Auditor-Trigger sofort.

**Nicht verhandelbar:** Pflicht-Review für abgeleitete Fulfillments (MAJOR-1). Alles andere ist Kür — das ist Pflicht.

### ISB-Sprint-2-Abnahme (Vorabankündigung)
Im Sprint-2-Abschluss prüft ISB konkret:
- [ ] Wird pro abgeleitetem Fulfillment ein `inherited_pending_review`-Status gesetzt und bis zur Review nicht als "erfüllt" gewertet?
- [ ] Mapping-Versionierung vorhanden und bei Stichtag-Reports berücksichtigt?
- [ ] 4-Augen-Voter für Override, Import-Commit ≥ 50 Zeilen, Bulk-Tag-Entfernen?
- [ ] Audit-Log-Einträge zeigen `actor_role` und ggf. `four_eyes_approver`?
- [ ] Import-Row-Log pro Zeile durchsuchbar?
- [ ] Tag-Historie bei entfernten Tags noch rekonstruierbar?
- [ ] DORA-Export-Schema auf ITS-Vorlage geprüft?

Falls eines davon nicht erreicht: Sprint-2-Abschluss blockiert.

---

## Unterschriften

| Rolle | Name | Datum | Unterschrift |
|---|---|---|---|
| ISB / Security Officer | _______________ | 2026-04-17 | _______________ |
| Compliance-Manager (Kenntnisnahme) | _______________ | _______________ | _______________ |
| CISO (Information) | _______________ | _______________ | _______________ |

---

## Änderungshistorie

| Version | Datum | Autor | Änderung |
|---|---|---|---|
| 1.0 | 2026-04-17 | ISB | Erstreview nach CM-Freigabe unter Auflagen |
