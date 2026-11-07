# Standortkarte

Die Standortkarte (`/location/map`, Tab **Karte** unter *Standorte*) zeigt jeden
Standort der obersten Ebene als Pin auf einer Weltkarte. Sie braucht keine neuen
Eingaben: alles kommt aus Standorten, Assets, Risiken, Vorfällen, Geschäftsprozessen,
Notfallplänen, internen Audits und Lieferanten, die schon gepflegt sind. Modul:
`locations` (ISO 27001 A.7); die Ebenen Notfall, Drittland und Audit erscheinen
mit den Modulen `bcm`, `privacy` und `audits`.

## Was ein Pin zeigt

| Merkmal | Bedeutung | Quelle |
|---|---|---|
| Größe | Schutzbedarf: höchster Wert aus C, I, A der Assets am Standort (Maximumprinzip) | `Asset.physicalLocation` im Standortbaum |
| Form + Farbe (Ebene *Restrisiko*) | Kreis = niedrig, Dreieck = mittel, Raute = hoch, Raute mit Ring = kritisch, grauer Kreis = keine Risiken | höchster aktueller Risikowert, Schwellen 6 / 12 / 20 |
| Form + Farbe (Ebene *Prüfungen*) | Kreis = alle erfüllt, Dreieck = 1–2 offen, Raute = ab 3 offen | sechs Prüfungen, siehe unten |
| Form + Farbe (Ebene *Notfall*, BCM) | Kreis = jeder kritische Prozess hat einen in 12 Monaten getesteten Plan, Dreieck = Plan nur im Entwurf oder ungetestet, Raute = kritischer Prozess ohne Plan, grau = kein kritischer Prozess | Geschäftsprozesse mit Kritikalität hoch/kritisch, deren Assets am Standort stehen, und ihr Notfallplan (ISO 22301 8.4/8.5) |
| Form + Farbe (Ebene *Audit*) | Kreis = in den letzten 12 Monaten auditiert, Dreieck = innerhalb von 3 Jahren oder ein Audit ist geplant, Raute = seit 3 Jahren kein Audit und keins geplant | internes Audit mit Bereich *Standort* (ISO 27001 9.2) |
| Form + Farbe (Ebene *Drittland*, Datenschutz) | Kreis = EU/EWR oder Angemessenheitsbeschluss, Dreieck = USA (DPF) oder anderes Drittland, grau = Land unbekannt | Land des Standorts und [Länderzonen](#länderzonen) (Art. 44–49 DSGVO) |
| blass | Lage nur auf Landesebene bekannt | `geoSource = country` |

Farbe ist nie das einzige Signal. Die Tabelle unter der Karte enthält dieselben
Werte und ist die barrierefreie Ansicht; Zeile und Pin wählen sich gegenseitig aus.

**Aktueller Risikowert:** Restrisiko (Rest-Eintritt × Rest-Auswirkung), sobald das
Risiko behandelt ist (Status behandelt, gemindert, akzeptiert, überwacht), sonst
der Bruttowert. Geschlossene Risiken zählen nicht. Ein Risiko gehört zum Standort,
wenn es direkt einem Standort im Baum zugeordnet ist oder sein Asset dort steht.

## Regionen ohne Gebäude

Homeoffice, Außendienst und mobile Teams werden als Standort vom Typ **Region**
angelegt. Das Gebiet steht im Feld *Land*: ein Land (`Deutschland`, `IE`),
`EU/EWR` oder `weltweit`. Ort und Postleitzahl werden für Regionen nicht genutzt.

- Land: Marker in der Landesmitte mit gestrichelter Fläche
- EU/EWR: Marker über Mitteleuropa mit gestrichelter Fläche
- weltweit: kein Marker, Zeile "weltweit" in der Tabelle

Zutrittskontrolle und Videoüberwachung gelten für Regionen nicht.

## Die sechs Prüfungen

Eine Prüfung, die für den Standort nicht gilt, wird weggelassen statt als erfüllt
gezählt ("3 von 4 offen"). Prüfungen, die eine Organisation nicht nutzt, schaltet
ein Admin ab (siehe [Einstellungen](#einstellungen)); die Prüfung der
verantwortlichen Person gilt immer.

| Prüfung | Gilt wenn | Norm |
|---|---|---|
| Verantwortliche Person benannt | immer | A.5.2 |
| Vertretung benannt | Stufe kontrolliert/gesichert oder Schutzbedarf ≥ 3 | A.5.2 |
| Zutrittskontrolle passt zur Sicherheitsstufe | ein Bereich ist kontrolliert/gesichert, keine Region | A.7.2 |
| Videoüberwachung bei Stufe „Gesichert“ | ein Bereich ist gesichert, keine Region | A.7.4 |
| Alle Risiken mit Behandlung | Standort hat Risiken | 6.1.3 |
| Keine offenen Vorfälle an Assets | Standort hat Assets | A.5.26 |

## Sichten

Die Sicht wird nach Rolle vorgewählt und lässt sich umschalten:

- **CISO / Leitung:** Ebene Restrisiko. *Wo liegt kritisches Restrisiko?*
- **ISB:** Ebene Prüfungen. *Welche Nachweise fehlen wo?*
- **Compliance:** Ebene Prüfungen, nur Handlungsbedarf, dazu die Liste
  "Gleiche Lücke an mehreren Standorten".
- **BCM** (Modul `bcm`): Ebene Notfall. *Welcher kritische Standort hat keinen geübten Plan?*
  Dazu der [Ereignis-Modus](#ereignis-in-einem-land).
- **Datenschutz** (Modul `privacy`): Ebene Drittland mit Länderzonen und
  [Lieferanten](#lieferanten-und-unterauftragnehmer). *Wohin fließen Daten außerhalb der EU?*
- **Audit** (Modul `audits`, Rolle Auditor): Ebene Audit mit Stichprobenvorschlag,
  nur lesend. *Welche Standorte nehme ich in die Stichprobe?*
- **Konzern** (Rolle Konzern-CISO oder Konzern-Auditor, Organisation mit
  Tochtergesellschaften): alle Standorte der eigenen und der untergeordneten
  Organisationen auf einer Karte, dazu eine Zeile je Gesellschaft. Nur lesend,
  ohne Verantwortliche und ohne Links in fremde Datensätze; andere Konzerne
  oder Schwestergesellschaften bleiben unsichtbar.
- **Meine Standorte:** nur Standorte, bei denen man als verantwortliche Person
  eingetragen ist. Erscheint nur dann.

Ebene, Filter und gewählter Standort stehen in der URL und lassen sich teilen.
Die gewählte Sicht bleibt für die Sitzung erhalten. Auditoren sehen alle Sichten
lesend, ohne Aktionen im Panel.

Wer viele eigene Standorte hat (mehr als drei), sieht in *Meine Standorte* eine
Kachel mit dem Durchschnitt statt einer Kachel je Standort; die Tabelle nennt
jeden Standort einzeln.

**Bedienung:** Karte ziehen zum Verschieben, Strg/⌘ + Mausrad oder die Tasten +/− zum Zoomen, Cluster anklicken zum Hineinzoomen, "Alle Standorte" passt die Ansicht wieder ein. Pins sind per Tab erreichbar, Enter öffnet das Panel.

## Steuerung

**Kennzahlen.** Über der Karte stehen je Sicht vier Kennzahlen aus dem
KPI-Katalog (dieselben Werte wie auf den Dashboards und im Management-Report):
*Prüfungen erfüllt* (Anteil der anwendbaren Prüfungen über alle Standorte,
Schwellen unter *Admin → KPI-Schwellen*, Standard 90/70 %), *Offene Prüfungen*,
*Standorte mit kritischem Restrisiko*, dazu je Rolle überfällige Behandlungen,
offene Vorfälle, Control-Erfüllung oder *Eingesparte Tage (12 Monate)* aus dem
FTE-Tracking (ab Manager). In *Meine Standorte* steht stattdessen je eigener
Standort der Wert gegen den Median ähnlicher Standorte.

**Vergleich mit ähnlichen Standorten.** Das Panel stellt einen Standort neben
vergleichbare Standorte, nie neben Personen (§ 87 BetrVG). Vergleichsgruppe ist
die erste Regel, die mindestens drei Standorte ergibt: gemeinsamer Tag (z. B.
"Werke Lateinamerika"), dann gleicher Typ mit Schutzbedarf ±1, dann Schutzbedarf
±1. Kleinere Gruppen bekommen keinen Vergleich. Das Land ist bewusst keine
Gruppe. Bei einer offenen Prüfung nennt das Panel einen Standort der Gruppe,
der sie erfüllt. Verantwortliche anderer Standorte werden nur genannt, wenn ein
Admin das eingeschaltet hat; der Vergleich lässt sich auch ganz abschalten.

**Größte Hebel.** Unter der Karte: die drei offenen, noch unbehandelten Risiken,
deren geplante Behandlung das Restrisiko am stärksten senkt (Differenz Ist → Ziel
× Schutzbedarf).

**Aktionen im Panel.** Risiko erfassen (Standort vorbelegt, `?fromLocation=`) und Vorfall melden,
ab Manager zusätzlich Verantwortliche zuweisen, Audit planen (Bereich *Standort*,
Standort, Titel und Umfang vorbelegt) und mit BCM-Modul Notfallübung planen
(Name, Umfang und die Notfallpläne der Prozesse am Standort vorbelegt).

**Sammelaktionen (ab Manager).** Standorte in der Tabelle anhaken und in einem
Schritt Verantwortliche, Vertretung oder eine Gruppe (Tag) setzen. Jede
Sammelaktion steht als ein Eintrag im Audit-Log (ISO 27001 7.5.3); die gesparte
Zeit geht ins FTE-Tracking (Kalibrierung *Datensatz einzeln ändern*, Standard
2 Minuten je Datensatz).

**Export.** *Excel* liefert die Tabelle mit Erfüllungsgrad und Vergleich, *PDF*
ein Lagebild auf einer Seite für das Management-Review (ISO 27001 9.3):
Kartenbild mit den Pins der Ebene Restrisiko, Kennzahlen, größte Hebel,
Standorte mit Handlungsbedarf. Das PDF hält den Stand zum Stichtag fest, etwa
für die Audit-Stichprobe. Mit Auswahl nur die
angehakten Standorte. Euro-Werte nur mit Modul Risikoquantifizierung und
passender Rolle. Jeder Export wird protokolliert. Ist der Vergleich abgeschaltet,
fehlt die Vergleichsspalte.

**Ziele.** Ein ISMS-Ziel (ISO 27001 6.2) lässt sich mit einer Kennzahl des
Katalogs verknüpfen, optional für einen Standort. Der Ist-Wert wird dann
berechnet statt eingetragen; das Panel zeigt Ziel, Ist und Restlaufzeit.

**Mein Tag und Dashboards.** Verantwortliche sehen eigene Standorte mit offenen
Prüfungen unter *Mein Tag*. Stillgelegte Standorte (inaktiv) zählen weder in
den Kennzahlen noch im Vergleich oder bei den Hebeln. CISO-, ISB- und Compliance-Dashboard zeigen die drei
Standort-Kennzahlen mit Link zur Karte, sobald mindestens zwei Standorte
platziert sind. Die Detailseite eines Standorts hat einen Steckbrief mit
denselben Werten.

## Lieferanten und Unterauftragnehmer

In der Sicht *Datenschutz* zeigt die Karte Lieferanten, die personenbezogene Daten
verarbeiten (Auftragsverarbeiter-Status nicht *keiner*, Status nicht beendet), als
Quadrat im Land ihrer Verarbeitungsorte; ohne Verarbeitungsort zählt das Sitzland.
Ein Klick auf das Quadrat zeigt die Lieferanten im Land, die Unterauftragnehmer-Kette
mit gestrichelten Linien und die Transfer-Folgenabschätzungen für das Land.

Jedes Glied der Kette wird eingestuft (Art. 28 Abs. 4, Art. 44–49 DSGVO):

| Ziel | ohne Garantie | mit Garantie (SCC, BCR, Zertifizierung, Ausnahme) |
|---|---|---|
| EU/EWR, Angemessenheitsbeschluss | zulässig | zulässig |
| USA (DPF) | Garantien prüfen | zulässig |
| anderes Drittland | Drittland ohne Garantie | Garantien prüfen |

Die Kette ist so gut wie ihr schwächstes Glied. Die Tabelle *Lieferanten mit
personenbezogenen Daten* unter der Karte nennt dieselben Werte. Gepflegt werden
Verarbeitungsorte, Kette und Garantie im Lieferanten-Formular.

## Länderzonen

Die Ebene Drittland färbt die Länder: EU/EWR flächig, Angemessenheitsbeschluss
hell, USA (DPF) gepunktet, sonstige Drittländer schraffiert. Die Liste steht in
`config/gdpr_transfer_zones.yaml` mit Datum (`as_of`, in der Legende als *Stand der
Liste*). Sie gilt im ganzen Tool: Karte, Lieferkette, EWR-Kennzeichen im
DORA-Informationsregister und Framework-Empfehlungen. CI-Gate 80 scheitert, wenn
die Liste älter als zwölf Monate ist; dann mit der Seite der EU-Kommission
abgleichen und das Datum hochsetzen. Die Liste ersetzt keine Rechtsprüfung.

## Ereignis in einem Land

In der Sicht *BCM* fragt der Abschnitt *Ereignis in einem Land* unter der Karte:
Was trifft ein Stromausfall, Sturm oder Vorfall in Land X? Nach Wahl des Landes
stehen dort die betroffenen Standorte, die Zahl der Assets, die Geschäftsprozesse,
die diese Assets stützen, deren Notfallpläne und die Krisenstäbe (ISO 22301 8.4.2,
BSI 200-4). Genannt werden die Krisenstäbe der betroffenen Pläne, sonst alle
aktiven. *Aktivieren* startet den Krisenstab nach Rückfrage und wird protokolliert.

## Audit-Stichprobe

In der Sicht *Audit* schlägt die Karte eine Stichprobe nach IAF MD 1 vor: √n der
aktiven Standorte (aufgerundet), zuerst Standorte ohne Audit seit drei Jahren, dann
nach Abstand zum letzten Audit und nach Restrisiko. Die Standorte sind in Tabelle
und Panel markiert. Den Zufallsanteil, den IAF MD 1 verlangt, ergänzt der Auditor.

Ein internes Audit gehört zu einem Standort, wenn als Bereich *Standort* gewählt
und der Standort im Feld *Standort* gesetzt ist. Bestehende Audits mit Bereich
Standort wurden bei der Aktualisierung über den Namen zugeordnet.

## Drill-down

Das Seitenpanel eines Standorts verlinkt auf das Formular, in dem eine Lücke
geschlossen wird, und auf die Listen **Risiken**, **Assets** und **Vorfälle**,
gefiltert mit `?location=<id>` auf den Standortbaum. Auf der Detailseite eines
Standorts führt **Auf Karte zeigen** zurück.

## Lage der Standorte

Koordinaten werden beim Speichern automatisch und offline bestimmt:

1. Postleitzahl + Land für Deutschland, Österreich, Schweiz (GeoNames-Tabelle, ca. 47 000 Einträge)
2. Ort + Land (für alle anderen Länder, ca. 113 000 Orte über 1000 Einwohner, gängige deutsche Namen wie Mailand oder Prag werden erkannt)
3. Landesmitte, wenn nur das Land bekannt ist

Breiten- und Längengrad im Formular überschreiben das (Quelle *manuell*) und
bleiben bei Adressänderungen erhalten. Beide Felder leeren gibt den Standort an
die automatische Bestimmung zurück. Bestehende Standorte ohne Koordinaten werden
auf der Karte sofort platziert; **Lage speichern** (ab ISB/Manager) schreibt sie fest.

**Online nachschlagen (optional):** In den [Einstellungen](#einstellungen) lässt sich ein Nominatim-kompatibler Dienst einschalten. Er wird nur für Standorte
genutzt, die offline lediglich auf Landesebene liegen, und bekommt nur Postleitzahl,
Ort und Ländercode, keine Straße, keinen Standortnamen. Höchstens 10 Abfragen pro
Aufruf, eine pro Sekunde; das Ergebnis wird gespeichert. Für keinerlei Übermittlung
eine eigene Nominatim-Instanz eintragen. Standard: aus.

## Einstellungen

Je Organisation unter *Admin → Einstellungen → Standortkarte* (ab Admin):

| Einstellung | Standard | Wirkung |
|---|---|---|
| Vergleich der Standorte | Standorte vergleichen, Verantwortliche nicht nennen | *Aus* blendet den Vergleich in Panel, Kachel, Steckbrief und Export aus. *Verantwortliche nennen* zeigt bei "Erfüllt bei" auch die verantwortliche Person; ein Vergleich mit Personen kann als Leistungskontrolle gelten (§ 87 Abs. 1 Nr. 6 BetrVG), also nur mit Zustimmung des Betriebsrats. |
| Prüfungen je Standort | alle an | Abgeschaltete Prüfungen zählen weder als Lücke noch als erfüllt, auch in Kennzahlen, *Mein Tag* und Export. |
| Genauigkeit | auf etwa 1 km gerundet | Karte und Export zeigen Koordinaten auf zwei Nachkommastellen, damit genaue Adressen etwa von Rechenzentren nicht in Screenshots landen. Gespeichert wird weiter der genaue Wert. *Exakt* zeigt ihn. |
| Online nachschlagen | aus | siehe oben |

Jede Änderung steht im Audit-Log.

## Backup

Die Felder `latitude`, `longitude`, `geo_source` am Standort sind Teil des
normalen Backups, ebenso die Einstellungen in `Tenant.settings['location_map']`
(ein Test prüft beides).
