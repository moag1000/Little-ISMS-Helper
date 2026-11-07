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
| Leitung (`ROLE_TOP_MGMT`, Admin erbt sie) | billigt oder lehnt ab, im Entscheidungsdialog |
| Auditor | liest alles, lädt die Managementbewertung als PDF |

## Vorlagen

„Entscheidung vorlegen“ zeigt die Arten mit ihrem Normbezug und ob eine
gültige Billigung besteht. Die Vorlage ist vorbefüllt:

| Art | Vorbefüllt aus | Gilt |
|---|---|---|
| Leitlinie und Geltungsbereich | Kontext der Organisation | 12 Monate |
| Risikoakzeptanzkriterien | Freigabe-Schwellwerte und Risikoappetit | 12 Monate, endet bei Schwellwertänderung |
| Erklärung zur Anwendbarkeit | Controls (anwendbar, ausgeschlossen, umgesetzt), letzter SoA-Stand | 12 Monate |
| Sicherheitsziele | offene Ziele mit Sollwert und Termin | 12 Monate |
| Ressourcen | Ressourcenbedarf der letzten Managementbewertung | — |
| NIS-2-Risikomanagementmaßnahmen (Modul NIS-2/DORA) | umgesetzte von anwendbaren Maßnahmen | 12 Monate |

Eine neue Billigung ersetzt die ältere derselben Art (Status „Ersetzt“).

Ohne Vorlage festgehalten werden: Risikoakzeptanzen auf Leitungsebene, der
Abschluss einer Managementbewertung und jede automatische Risikoakzeptanz.

## Automatische Risikoakzeptanz

Restrisiken bis zur ersten Freigabestufe werden nur dann automatisch
akzeptiert, wenn die Leitung die aktuellen Schwellwerte gebilligt hat. Ohne
Billigung, oder nach einer Änderung eines Schwellwerts, entscheidet das
Risikomanagement. Jede automatische Akzeptanz verweist auf die Billigung, auf
der sie beruht. Die Seite Admin > Freigabe-Schwellwerte zeigt den Stand und
legt die Billigung mit einem Klick vor.

Risikoakzeptanzen oberhalb der Manager-Stufe entscheidet die Leitung
(`ROLE_TOP_MGMT`).

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

Die Felder „Kontextänderungen“, „Bewertung von Nichtkonformitäten“,
„Verbesserungschancen“ und „Ressourcen benötigt“ waren doppelt; die Migration
hat ihren Text in das jeweilige 9.3.2-Feld übernommen. Die frühere
strukturierte Maßnahmenliste wurde zu Aufgaben.

## Korrekturmaßnahmen (ISO 27001 10.2)

- Ein Befund braucht kein Audit mehr: Nichtkonformitäten aus
  Managementbewertung, Vorfall oder Selbstbewertung werden ohne Audit erfasst.
- Wird eine Korrekturmaßnahme als wirksam bestätigt und sind alle
  Maßnahmen des Befunds wirksam oder abgebrochen, wird der Befund
  geschlossen (jeder Schritt im Audit-Log).
- Die verknüpften Controls gelten mit der Bestätigung als geprüft
  (letzter Wirksamkeitstest) und kommen binnen 30 Tagen zur Überprüfung.
  Der Umsetzungsstatus bleibt unverändert.
- Wird eine Korrekturmaßnahme als unwirksam bestätigt, entsteht automatisch
  eine Folge-Korrekturmaßnahme.
