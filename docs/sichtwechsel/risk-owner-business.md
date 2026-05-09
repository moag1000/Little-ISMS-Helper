# Risk-Owner-Sicht — Business-Pfade, mobil, knapp

> **Wer:** Abteilungsleiter oder Prozessverantwortlicher (Vertrieb, Einkauf, HR, Produktion). Kein InfoSec-Hintergrund. Tool öffnet er/sie nur wenn er/sie muss.
> **Denkweise:** Geschäftsprozess steht über Security. Denkt in Umsatz, Kunden, Mitarbeitern, Lieferketten, Reputation. Risiko in € und Ausfalltagen.
> **Frust-Trigger:** Mehrseitige Formulare, InfoSec-Jargon, "Was soll ich hier entscheiden?".
>
> Volle Persona-Definition: [`.claude/skills/persona-risk-owner-business`](../../.claude/skills/persona-risk-owner-business/)

[← Zurück zur Übersicht](README.md)

---

## Dashboard

Eigene KPIs gefiltert auf Bereich. Offene Tasks, eigene Top-Risiken, anstehende Freigaben.

![Risk-Owner-Dashboard](img/isb-practitioner/dashboard.png)

> *"Ich hab 5 Minuten — was muss ich wissen?"*

Kein Klausel-Wortlaut. Kein Field-Level-Detail. Eine Aufgabe, eine Entscheidung pro Karte.

---

## Aufgaben-Inbox

"Das musst DU jetzt tun." Mit Frist, Direktlink, Business-Kontext.

![Workflows-Inbox](img/risk-owner-business/workflows-inbox.png)

Workflow-System löst Approvals event-driven aus — sobald die ISB die Daten ausgefüllt hat, landet die Risk-Owner-Freigabe automatisch hier (siehe [Workflow-Auto-Progression](../WORKFLOW_AUTO_PROGRESSION.md)).

---

## Eigene Risiken

Filterbar auf "meine Risiken" — Risiken im eigenen Geschäftsbereich, ich als Risk-Owner zugewiesen.

![Eigene Risiken](img/isb-practitioner/risk-register.png)

> *"Was passiert wenn ich 'ablehne'? Mein ISB kümmert sich drum, warum bin ich hier?"*

Detail-View pro Risiko: Was bedeutet das für den Geschäftsprozess? Welcher Schaden in € pro Tag bei Ausfall? Welche Optionen — akzeptieren / behandeln / eskalieren?

---

## Geschäftsprozesse

Eigener Bereich aus Prozess-Sicht statt aus Asset/Control-Sicht.

![Geschäftsprozesse](img/risk-owner-business/business-processes.png)

BIA (Business Impact Analysis) zugeordnet: RTO, RPO, kritische Abhängigkeiten — in Stunden und €, nicht in CIA-Triade-Buchstaben.

---

## BCM-Übersicht

BC-Pläne, BC-Übungen, Krisenteam — falls der Risk-Owner als Crisis-Leader nominiert ist.

![BCM-Übersicht](img/risk-owner-business/bcm-overview.png)

Eigener Notfallplan abrufbar mit einem Klick. Wenn ich Bereitschaft habe und um 3 Uhr morgens das Telefon klingelt — der Plan ist hier.

---

## Risikobehandlungsplan

Was ist mit den Risiken aus meinem Bereich entschieden worden? Welche Massnahmen laufen, wer verantwortet, bis wann?

![Risikobehandlungsplan](img/isb-practitioner/risk-treatment-plan.png)

Verfolgung als Risk-Owner: keine paralleles Excel mit Fristen, sondern direkt im Tool mit Ampel-Status und Owner.

---

## Was der Risk-Owner hier nicht findet (und vermisst)

Aus der [Persona-Definition](../../.claude/skills/persona-risk-owner-business/SKILL.md):

- **Mobile-First-Bedienung** — heute Desktop-optimiert; Tablet-Sicht OK, Smartphone-Approval-Flow noch nicht.
- **E-Mail-Direkt-Approval** — Ein-Klick-Akzeptanz aus dem Inbox-Mail (signed token).
- **Business-Sprache pro Risiko** durchgängig — heute teils noch CIA-Triade-Wording in Risiko-Details.
- **One-Pager-Audit-Spur** ("Was hab ich freigegeben in den letzten 12 Monaten?") für persönliche Haftungsdoku.

→ Roadmap-Items aus Risk-Owner-Sicht.

---

[← Junior-Implementer](implementer-junior.md) · [Übersicht](README.md) · [Nächste Persona: Externer Auditor →](auditor-external.md)
