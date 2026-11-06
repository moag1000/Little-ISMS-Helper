# Mein Tag — der eine Posteingang

"Mein Tag" (`/{locale}/my-day`) zeigt alles, was auf die angemeldete Person wartet,
aus allen Modulen auf einer Seite. Freigaben und Vier-Augen-Anfragen werden direkt dort
entschieden; die Seite muss dafür nicht verlassen werden.

## Wer landet hier nach dem Login

| Rolle | Startseite |
|---|---|
| Normale Nutzer (z. B. Risk Owner, Fachbereich), Auditor | Mein Tag |
| Manager und alle Persona-Rollen (CISO, ISB, DSB, Risk-, Compliance-, BCM-Manager), Admin | Dashboard |
| Konzern-Rollen (Group CISO, Konzern-Auditor) | Dashboard |

Eine vor dem Login aufgerufene Adresse (z. B. der Link aus einer Freigabe-Mail) hat
immer Vorrang. Für alle Rollen steht "Mein Tag" oben in der Seitenleiste, mit Zähler.

## Kategorien

Angezeigt werden nur Kategorien mit offenen Punkten; der Rest wird zu einer Zeile
"N weitere Kategorien ohne offene Punkte" zusammengefasst. Dringende Kategorien,
Freigaben und Vier-Augen-Anfragen sind aufgeklappt, alle anderen zeigen nur den Zähler.

| Kategorie | Inhalt |
|---|---|
| Freigaben für mich | Workflow-Schritte, die ich entscheiden darf: als namentlich eingetragene Person, über die Rolle des Schritts (inkl. vererbter Rollen) oder als Admin |
| Freigaben überfällig | Von mir gestartete Workflows über der Frist |
| 4-Augen-Anfragen | Vier-Augen-Freigaben, die auf mich warten (entscheiden ab Manager) |
| Alva-Empfehlungen | Alvas Hinweise der Priorität 1; die übrigen stehen im Alva-Posteingang |
| Policy-Bestätigungen, Audit-Findings, Betroffenenanfragen, Korrekturmaßnahmen, Risiko-Akzeptanzen und -Reviews, Dokument-Reviews und -Freigaben, Nachweis-Reverifizierung, Wirksamkeitsprüfungen, Lieferanten-Reassessments, DSFA- und BC-Plan-Reviews, Schulungen, Vorfälle, Datenpannen (72h), kritische Schwachstellen, Audit-Checklisten, Change Requests, Management-Reviews, gestoppte Assessments, kritische Framework-Lücken | jeweils die mir zugewiesenen bzw. fälligen Punkte; Module ohne Aktivierung liefern nichts |

Freigaben tragen den Namen des Datensatzes ("Risiko: Kundendaten Online-Shop"), der
Workflow und der aktuelle Schritt stehen darunter.

## Entscheiden

Workflow-Freigaben, Vier-Augen-Anfragen und Risikoakzeptanzen nutzen dasselbe
Entscheidungsfenster: worum es geht (Datensatz), bei Risiken die Geschäftszeile
(Risikostufe, erwarteter Jahresschaden in €), darunter Kommentar bzw. Begründung.

- **Freigeben / Akzeptieren**: Kommentar optional.
- **Ablehnen**: Begründung Pflicht; sie steht in der Freigabe-Historie bzw. im Audit-Log.
- **Vier-Augen-Anfrage ablehnen**: Begründung mit mindestens 20 Zeichen.
- **Risikoakzeptanz ablehnen**: der Antrag ist damit erledigt und verschwindet aus
  "Mein Tag" und von der Risikoseite; der Antragsteller kann neu beantragen.

Nach der Entscheidung kehrt die Seite zu "Mein Tag" zurück.

## Auf dem Smartphone

Für den Fall "Risk Owner, unterwegs, eine Freigabe" ist "Mein Tag" auf kleine
Bildschirme ausgelegt (bis 575 px Breite):

- Statt einer Kachel pro Kategorie steht oben eine waagerecht wischbare Leiste mit
  Kategorie und Zähler; ein Tipp springt zum Abschnitt. Die erste Freigabe ist damit
  auf dem ersten Bildschirm.
- Die Erklärung zu Fachbegriffen (z. B. "4-Augen-Anfragen") steht als Zeile im
  Abschnitt, weil es auf Touch-Geräten keinen Hover-Tooltip gibt.
- Entscheidungs-Schaltflächen teilen sich die Zeilenbreite und sind mindestens 44 px hoch.
- Das Entscheidungsfenster öffnet als Blatt vom unteren Rand, volle Breite, Schaltflächen
  untereinander (Entscheidung oben, Abbrechen unten). Der Textbereich scrollt für sich,
  die Schaltflächen bleiben auch bei offener Tastatur sichtbar; Eingaben in 16 px, damit
  iOS nicht hineinzoomt. Das gilt für alle Bestätigungs- und Einstellungsdialoge der App.

Andere Seiten (Listen, Tabellen) scrollen auf dem Smartphone weiterhin seitlich; eine
eigene Kartenansicht dafür ist bewusst nicht vorgesehen.

Die frühere Seite "Ausstehende Workflows" (`/workflow/pending`) leitet auf "Mein Tag"
um. Vier-Augen- und Policy-Posteingang bleiben auf Dichte "Experte" erreichbar.

## Technische Referenz

- Controller: `src/Controller/MyDayController.php`, Route `app_my_day`
- Kategorien: `src/Service/MyDayAggregator.php`
- Datensatz-Namen, Alva-Kategorie, übersetzte Status: `src/Service/MyDay/MyDayInboxEnricher.php`
- "Wartet auf meine Entscheidung": `WorkflowInstanceRepository::findPendingForUser()` (eine
  Definition für Mein Tag, Seitenleisten-Zähler, Dashboards und Workflow-Seiten)
- Startseite nach Login: `src/Security/LandingRoute.php`
- Entscheidungsfenster: `decision_modal()` (ein Dialog für Workflow, Vier-Augen, Risikoakzeptanz),
  `decision_buttons()` / `decision_modals()` in `templates/_macros/workflow.html.twig`
- Bottom-Sheet auf dem Smartphone: Abschnitt "Phones" in `assets/styles/fa-modal.css`
- Template: `templates/my_day/index.html.twig`

## Verwandte Dokumente

- `docs/user-guide/ACTIVITY_FEED.md` — Aktivitäten
- `docs/WORKFLOW_AUTO_PROGRESSION.md` — automatische Workflow-Progression
