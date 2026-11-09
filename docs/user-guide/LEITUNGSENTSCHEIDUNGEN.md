# Leitungsentscheidungen und Managementbewertung

Was die Leitung entscheidet, steht an einer Stelle: **Leitungsentscheidungen**
(Menü Compliance, Suche „Beschluss“ oder „Leitungsentscheidung“, Route
`/{locale}/management-decisions`). Jede Entscheidung hält fest, wer wann was
gebilligt oder abgelehnt hat, mit Kommentar oder Begründung und dem Bezug auf
den Datensatz. Das ist der Nachweis für ISO 27001 5.1, 6.1.2 a, 6.1.3 d/f,
9.3.3 und NIS-2 Art. 20 Abs. 1.

## Wer was tut

| Wer | Tut |
|---|---|
| CISO / ISB (`ROLE_MANAGER`) | legt eine Vorlage vor; sie ist aus den Daten vorbefüllt |
| Leitung (`ROLE_TOP_MGMT`, ausdrücklich zugewiesen; Admins erben sie nicht) | billigt oder lehnt ab, im Entscheidungsdialog |
| Auditor | liest alles, lädt die Managementbewertung als PDF |

Hat niemand in der Organisation die Rolle Leitung, bleiben Leitungsentscheidungen
liegen; sie fallen nie an Admins zurück. Admins erhalten in Mein Tag die Pflicht
„Keine Leitung zugewiesen“ (ISB und CISO werden informiert), das Register zeigt
den Hinweis mit „Rolle Leitung zuweisen“ (für Admins mit Link zur Benutzerverwaltung).

## Vorlagen

„Entscheidung vorlegen“ zeigt die Arten mit ihrem Normbezug und ob eine
gültige Billigung besteht. Die Vorlage ist vorbefüllt:

| Art | Vorbefüllt aus | Gilt |
|---|---|---|
| Leitlinie und Geltungsbereich | Kontext der Organisation | 12 Monate |
| Risikokriterien | Risikoskala (Stufengrenzen, Bedeutung der Stufen 1–5), Freigabestufen und Risikoappetit | 12 Monate, endet bei jeder Änderung an Skala, Stufenbedeutung oder Freigabestufen |
| Erklärung zur Anwendbarkeit | Controls (anwendbar, ausgeschlossen, umgesetzt), letzter SoA-Stand | 12 Monate |
| Sicherheitsziele | offene Ziele mit Sollwert und Termin | 12 Monate |
| Ressourcen | Ressourcenbedarf der letzten Managementbewertung | — |
| NIS-2-Risikomanagementmaßnahmen (Modul NIS-2/DORA) | umgesetzte von anwendbaren Maßnahmen | 12 Monate |

Eine neue Billigung ersetzt die ältere derselben Art (Status „Ersetzt“).

Ohne Vorlage festgehalten werden: Risikoakzeptanzen auf Leitungsebene, der
Abschluss einer Managementbewertung und jede automatische Risikoakzeptanz.

## Automatische Risikoakzeptanz

Restrisiken bis zur ersten Freigabestufe werden nur dann automatisch
akzeptiert, wenn die Leitung die aktuellen Risikokriterien gebilligt hat. Ohne
Billigung, oder nach einer Änderung an Skala, Stufenbedeutung oder
Freigabestufen, entscheidet das Risikomanagement. Jede automatische Akzeptanz
verweist auf die Billigung, auf der sie beruht. Die Seite Admin >
Risikokriterien zeigt den Stand und legt die Billigung mit einem Klick vor.

## Risikoskala (ISO 27001 6.1.2 a/b)

Die Skala gehört der Organisation und wird unter **Admin > Risikokriterien**
gepflegt (Suche: „Risikoskala“):

- **Stufengrenzen**: ab welchem Wert (Wahrscheinlichkeit × Auswirkung, je
  1–5) ein Risiko mittel, hoch oder kritisch ist. Vorgabe 6 / 12 / 20, die
  Organisation legt ihre eigenen fest; jede
  Stufe behält mindestens einen Wert. Die Matrix bleibt 5 × 5, damit
  bestehende Bewertungen vergleichbar bleiben.
- **Bedeutung der Stufen**: was Wahrscheinlichkeit 1–5 und Auswirkung 1–5
  hier heißen (z. B. „5 = Schaden über 1 Mio. €“). Das Risikoformular zeigt
  den Text in der Stufenauswahl, die Risikomatrix darunter.

Die Grenzen gelten überall gleich: Matrix, Filter, Risikoliste, Dashboards,
Kennzahlen, Berichte, PDFs, Exporte und die Heatmaps (Code liest sie nur über
`App\Risk\RiskMatrixThresholds`, Gate 87 verhindert feste 20/12/6). Eine
Tochtergesellschaft ist nie laxer als ihre Holding: es gilt je Grenze der
niedrigere Wert.

Jedes Risiko wird in der Skala seiner eigenen Organisation eingestuft, nicht
in der des Betrachters: Berichte, Exporte, geplante Berichte, Benachrichtigungen
(„Risiko kritisch“) und Holding-Ansichten über mehrere Organisationen nutzen
auch ohne angemeldete Sitzung (Cron, Hintergrundjob) die Grenzen der
Organisation, zu der das Risiko gehört. Code übergibt dafür die Organisation
an `RiskMatrixThresholds` bzw. `risk_level_band(score, risk.tenant)`; Gate 87
lehnt Aufrufe ohne sie ab.

Skala, Stufenbedeutung und Freigabestufen billigt die Leitung zusammen als
„Risikokriterien“. Jede Änderung beendet die Billigung; bis zur neuen
Billigung pausiert die automatische Akzeptanz.

Risikoakzeptanzen oberhalb der Manager-Stufe entscheidet die Leitung
(`ROLE_TOP_MGMT`). Wer wann akzeptiert hat, entsteht nur über die Freigabe
(Entscheidungsdialog), nicht über ein Formularfeld.

## Wo die Leitung entscheidet

- **Leitungs-Dashboard**, oben: „Zu entscheiden“ (offene Vorlagen mit
  Billigen/Ablehnen, fehlende oder bald endende Billigungen) und „Letzte
  Entscheidungen“.
- **Mein Tag**: Kategorie „Leitungsentscheidungen“ (aus der Fristenquelle), mit denselben Knöpfen.
- **Fristenquellen**: offene Vorschläge (`management_decision`, an `ROLE_TOP_MGMT`) und offene Aufgaben aus der Managementbewertung (`management_review_action`, an die verantwortliche Person, sonst ISB/CISO) melden sich als Fristenquelle; Mein Tag und die Sammelmail lesen sie von dort.
- **Vorbereiten**: Billigungen, die fehlen oder in 60 Tagen enden (Leitlinie, Risikokriterien, SoA, Ziele, Ressourcen, mit NIS-2 auch die Maßnahmen nach Art. 20), stehen beim ISB/CISO in Mein Tag („Leitungsentscheidungen vorbereiten“, Quelle `standing_approval`), bis eine Vorlage eingereicht ist. Das Leitungs-Dashboard zeigt dieselbe Liste unter „fehlt“.
- **Management-Review anstehend**: die Leitung wird mitinformiert, vorbereitet wird es vom eingetragenen Prüfer, sonst vom ISB/CISO.
- **Board-One-Pager**: Abschnitt „Entscheidungen der Leitung“.

## Managementbewertung (ISO 27001 9.3)

- **Eingaben 9.3.2 a–g** stehen in der Reihenfolge der Norm, im Formular, auf
  der Detailseite und im PDF. „Aus aktueller Datenlage generieren“ füllt sie
  aus den Daten: a aus den Maßnahmen und Entscheidungen der vorherigen
  Bewertung, b aus dem Kontext der Organisation, c aus den seit der letzten
  Bewertung geänderten interessierten Parteien, d aus Befunden,
  Korrekturmaßnahmen, Kennzahlen, Vorfällen, Audits und Zielen, e aus den
  Rückmeldungen interessierter Parteien, f aus Risiken und
  Behandlungsplänen. Das nächste Bewertungsdatum wird zwölf Monate später
  vorgeschlagen.
- **Maßnahmen aus der Bewertung** (9.3.3) sind Aufgaben mit Verantwortlichem
  und Frist; sie werden auf der Detailseite angelegt und erledigt und
  erscheinen in der nächsten Bewertung unter a.
- **Abschluss**: Die Leitung klickt „Ergebnisse billigen und abschließen“.
  Der Abschluss erscheint als Entscheidung im Register.
- Anlegen und Bearbeiten: CISO/ISB (`ROLE_MANAGER`); Löschen: Admin.

## Korrekturmaßnahmen (ISO 27001 10.2)

- Ein Befund braucht kein Audit: Nichtkonformitäten aus
  Managementbewertung, Vorfall oder Selbstbewertung werden ohne Audit erfasst.
- Wird eine Korrekturmaßnahme als wirksam bestätigt und sind alle
  Maßnahmen des Befunds wirksam oder abgebrochen, wird der Befund
  geschlossen (jeder Schritt im Audit-Log).
- Die verknüpften Controls gelten mit der Bestätigung als geprüft
  (letzter Wirksamkeitstest) und kommen binnen 30 Tagen zur Überprüfung.
  Der Umsetzungsstatus bleibt unverändert.
- Wird eine Korrekturmaßnahme als unwirksam bestätigt, entsteht automatisch
  eine Folge-Korrekturmaßnahme.
