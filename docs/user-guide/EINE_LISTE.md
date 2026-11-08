# Eine Liste statt zwei (P5)

Manche Angaben standen bisher an zwei Stellen, die nicht miteinander sprachen. Seit P5 gibt es jeweils nur noch eine Liste. Alte Daten werden bei der Migration übernommen; über `/quick-fix` → „Ausstehende Migrationen anwenden“ ausführen.

| Was | Vorher | Jetzt |
|---|---|---|
| Maßnahmen eines Risikos | eigene Liste am Risiko und eine weitere am Behandlungsplan | **eine** Liste am Risiko. Der Behandlungsplan zeigt und ändert genau diese Liste (ISO 27001 6.1.3 / 8.3). Die Maßnahmen alter Pläne wurden in die Liste ihres Risikos übernommen. |
| Risiken eines Vorfalls | „eingetretene Risiken“ und „verknüpfte Risiken“ getrennt | **nur Verknüpfungen** (Risiko ↔ Vorfall, mit Art: eingetreten, vermutet, verwandt, Maßnahme versagt). Alte „eingetretene Risiken“ sind jetzt Verknüpfungen der Art „eingetreten“. „Eingetreten“ (für Neubewertung und Häufigkeit) heißt: Art „eingetreten“ oder „Maßnahme versagt“. |
| Freigabe eines Change | Freitext „Freigegeben von“ | **Freigebende Person** (Auswahl). Sie bekommt die Freigabe in „Mein Tag“ und in der Sammelmail; ohne Person die Rollen ISB/CISO. Ein alter Freitext, der genau einer Person der Organisation entspricht, wurde übernommen. Der Freitext bleibt sichtbar, solange er gefüllt ist. |
| AVV eines Lieferanten (DSGVO Art. 28) | „AVV vorhanden“ + Datum und daneben „AV-Vertrag unterzeichnet“ + Datum | **ein** Feld „AVV vorhanden“ + Datum. Wer an einer der beiden Stellen „unterzeichnet“ hatte, hat es jetzt am einen Feld. Der DORA-Export schreibt die Spalten `gdpr_av_contract_*` aus diesem Feld. |
| Unterauftragnehmer eines Lieferanten (DORA Art. 28, Art. 30) | Kette als Text/JSON im Lieferantenformular und daneben die DORA-Unterauftragnehmer-Datensätze | **nur die Unterauftragnehmer-Datensätze** (Lieferant → „Unterauftragnehmer erfassen“). Die alte Kette wurde in Datensätze überführt, Stufen als Baum. Detailseite, AVV-Anlage, Standortkarte, CSV- und XBRL-Register lesen dieselbe Liste; der CSV-Import legt neue Namen als Datensätze an. |
| Rechtliche Anforderungen eines Stakeholders | Feld ohne Formular | Formularfeld am Stakeholder (ISO 27001 4.2 c), je Zeile Anforderung + Fundstelle. Jede Zeile wird ein Vorschlag für das Register „Rechtliche Anforderungen“. |

## Freitexte, die nur noch sichtbar sind, solange sie gefüllt sind

- Schulung: „Teilnehmer (alt)“ — Teilnehmende sind Benutzer bzw. Teilnahmen. Der Schulungsnachweis-Export zählt die Teilnahmen.
- Risiko: „Bedrohung“ und „Schwachstelle“ als Text, **wenn das Modul Schwachstellen & Threat Intelligence aktiv ist** — dann wählt man die Bedrohung aus dem Katalog und die Schwachstelle aus dem Register. Ohne das Modul bleiben die Textfelder.
- Kontext der Organisation: zusätzlich „Anforderungen interessierter Parteien“ (jetzt am Stakeholder).
- Managementbewertung: „Maßnahmen (alt)“ (bereits seit Paket 7/8).

## Werte, die das Tool ableitet

Diese Felder sind nur lesbar, solange die Ableitung greift. Der Hilfetext nennt die Quelle; Änderungen kommen als Vorschlag in die [Vorschlagsliste](VORSCHLAEGE.md).

- Behandlungsplan „Fertigstellungsgrad“: Anteil umgesetzter Maßnahmen des Risikos (Vorschlagsart „Fortschritt Behandlungsplan“). Ohne Maßnahmen bleibt das Feld editierbar.
- Asset „Verfügbarkeit“: aus der BIA des unterstützten Geschäftsprozesses (RTO → Verfügbarkeit, BSI 200-2). Liegt der Wert bereits über der BIA, bleibt das Feld editierbar.

## Entfernt

- Aufbewahrungsfrist an Dokumentversionen (`retention_until`): wurde nirgends gesetzt oder gelesen.

## Backups

Backups von vor P5 lassen sich weiter einspielen: „eingetretene Risiken“ werden beim Restore zu Verknüpfungen der Art „eingetreten“, Maßnahmen eines Behandlungsplans kommen in die Liste seines Risikos, die Unterauftragnehmer-Kette eines Lieferanten wird zu Unterauftragnehmer-Datensätzen und ein „AV-Vertrag unterzeichnet“ zu „AVV vorhanden“.
