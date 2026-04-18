# ISB-Review: {PLAN_TITEL}

> **Review-Gegenstand:** `{PFAD_ZUM_PLAN}`
> **Review-Datum:** {YYYY-MM-DD}
> **Review durch:** {ROLLE} ({Name})
> **Anlass:** {kurze Beschreibung, z. B. "CM-Freigabe unter Auflagen erteilt → Plan prüft, ob Umsetzung auditfähig bleibt"}
> **Prüfgrundlage:** ISO 27001:2022, ISO 27002:2022, ISO 19011:2018, {ggf. zusätzlich: NIS2, DORA, DSGVO, BSI-IT-Grundschutz, TISAX, C5, SOC 2}.

---

## Gesamteinschätzung

_{1–3 Absätze · Tenor der Prüfung, wo liegt der Schwerpunkt, Risiko-Aussage.}_

**Klassifikation analog ISO 19011 Kap. 6.4.8:**
- **{N} Abweichungen (Major)** — Pflicht zur Behebung vor {Sprint/Release}-Start.
- **{N} Abweichungen (Minor)** — Behebung bis {Zieltermin}.
- **{N} Beobachtungen (Observation)** — als Verbesserungshinweis, kein Blocker.

**Positive Feststellungen** (zur Balance): _{Kurzliste, 3–5 Stichpunkte.}_

**Freigabeempfehlung an {Auftraggeber-Rolle}:** **{Freigegeben | Bedingte Freigabe | Zurückgewiesen}**. _{1 Satz Begründung.}_

---

## Major-Abweichungen (Pflicht vor {Sprint/Release}-Start)

### MAJOR-1 · {Titel der Feststellung}
**Betroffen:** {Workstream / Modul / Kapitel}.
**Norm-Bezug:** {ISO 27001 Klausel X.Y, Control A.N.M, ggf. weitere: NIS2 Art. …, DORA Art. …, DSGVO Art. …}

**Feststellung:** _{Was konkret fehlt / widerspricht / gefährdet. 2–4 Sätze.}_

**Abweichung konkret:**
- _{einzelner Abweichungspunkt 1}_
- _{einzelner Abweichungspunkt 2}_

**Korrektur verpflichtend:**
1. _{konkrete Maßnahme 1}_
2. _{konkrete Maßnahme 2}_
3. _{Audit-Trail-Anforderung, falls relevant}_

_(pro Major-Finding wiederholen)_

---

## Minor-Abweichungen (bis {Sprint-2 / Release-Ende})

### MINOR-1 · {Titel}
**Betroffen:** {Workstream}. **Norm:** {Klausel / Control}.

_{1–2 Sätze Feststellung.}_

**Korrektur:** _{1 Satz Maßnahme.}_

_(pro Minor-Finding wiederholen)_

---

## Beobachtungen (OFI — Opportunity for Improvement)

### OBS-1 · {Titel}
**Bezug:** {Workstream / Thema}. _{1–3 Sätze.}_
**Empfehlung:** _{konkrete Idee, nicht verpflichtend.}_

_(pro Observation wiederholen)_

---

## Positive Feststellungen (zur Balance)

| # | Aspekt | Warum auditfreundlich |
|---|---|---|
| P1 | _{Aspekt}_ | _{Begründung}_ |
| P2 | _{Aspekt}_ | _{Begründung}_ |

---

## Abnahme-Empfehlung an {Auftraggeber-Rolle}

**Empfehlung:** **{Freigegeben | Bedingte Freigabe | Zurückgewiesen}** unter folgenden Voraussetzungen:

1. _{Major-Findings sind vor Start eingearbeitet.}_
2. _{ggf. Anhang-Ergänzungen im Plan, Versionserhöhung.}_
3. _{Abhängigkeiten mit externen Rollen dokumentiert.}_

**Nicht verhandelbar:** _{Kernforderung, meist ein Major-Finding.}_

### {Sprint/Release}-Abnahme-Checkliste

Bei Abschluss prüft der Reviewer konkret:
- [ ] _{überprüfbare Forderung 1}_
- [ ] _{überprüfbare Forderung 2}_
- [ ] _{überprüfbare Forderung 3}_

Falls eines davon nicht erreicht: Abschluss blockiert.

---

## Unterschriften

| Rolle | Name | Datum | Unterschrift |
|---|---|---|---|
| {Reviewer-Rolle, z. B. ISB} | _______________ | {YYYY-MM-DD} | _______________ |
| {Kenntnisnahme-Rolle, z. B. CM} | _______________ | _______________ | _______________ |
| {Info-Rolle, z. B. CISO} | _______________ | _______________ | _______________ |

---

## Änderungshistorie

| Version | Datum | Autor | Änderung |
|---|---|---|---|
| 1.0 | {YYYY-MM-DD} | {Autor} | Erstreview |

---

## Nutzungshinweis

Dieses Template (`docs/templates/PLAN_REVIEW_TEMPLATE.md`) generalisiert das ISB-Review-Format, das erstmals für `docs/DATA_REUSE_PLAN_REVIEW_ISB.md` verwendet wurde (OBS-4 aus jenem Review).

**Workflow:**
1. Neue Plan-Review starten: Datei kopieren nach `docs/<PLAN>_REVIEW_<REVIEWER>.md`.
2. Platzhalter `{…}` durch konkrete Werte ersetzen, unbenötigte Blöcke löschen.
3. Wiederholende Blöcke (MAJOR-N, MINOR-N, OBS-N, P-N) für jeden Befund duplizieren.
4. Major/Minor-Findings MÜSSEN Norm-Referenz + verpflichtende Korrekturschritte enthalten (ISO 19011 Kap. 6.4.8).
5. Nach Abschluss Unterschriften-Block + Versions-Bump in der Änderungshistorie.

**Mindest-Sektionen (kein Weglassen):**

- Meta-Block oben (Review-Gegenstand, Datum, Reviewer, Prüfgrundlage)
- Gesamteinschätzung + Klassifikation
- Major-Abweichungen mit Norm-Referenz
- Minor-Abweichungen mit Norm-Referenz
- Beobachtungen (OFI)
- Positive Feststellungen
- Abnahme-Empfehlung + Checkliste für Abschluss-Prüfung
- Unterschriften
- Änderungshistorie

**Norm-Referenz-Spalte ist Pflicht** pro Major/Minor-Finding — ohne sie ist der Review nicht auditfest.
