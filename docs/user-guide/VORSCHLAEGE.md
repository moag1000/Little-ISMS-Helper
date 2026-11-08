# Vorschläge — eine Queue für Ableitungen, Vererbung, Importe und KI

Unter **Vorschläge** (`/{locale}/suggestions`, ⌘K „Vorschläge“, Abschnitt „Vorschläge“ in „Mein Tag“)
steht alles, was das Tool selbst vorbereitet hat: abgeleitet aus Ihren Daten, vererbt
aus einem anderen Framework, aus einem Import übernommen oder von einem Sprachmodell
geschrieben. Nichts davon ändert einen Datensatz, bevor eine berechtigte Person
annimmt. Jede Entscheidung steht mit Person, Zeit und Begründung im Audit-Log
(ISO 27001 7.5.3).

## Woher die Vorschläge kommen

| Art | Herkunft | Was beim Annehmen passiert | Entscheidet |
|---|---|---|---|
| Schutzbedarf erhöhen | Regel: Abhängigkeiten (Maximumprinzip) und BIA (RTO) | C/I/A des Assets werden angehoben, nie gesenkt | ISB, Risikomanager, Manager |
| Restrisiko einschätzen | Regel: umgesetzte und wirksame Maßnahmen des Risikos | Restwahrscheinlichkeit/-auswirkung werden eingetragen | Risikomanager, Manager |
| Behandlung abschließen | Regel: alle Maßnahmen des Plans umgesetzt | Risiko wechselt über den Lebenszyklus auf „behandelt“ | Risikomanager, Manager |
| Fortschritt Behandlungsplan | Regel: Anteil umgesetzter Maßnahmen | Fortschritt in Prozent wird gesetzt | Risikomanager, Manager |
| Wirksamkeit bewerten | Regel: Test, Vorfälle seit dem Test, geschützte Werte, Vorfälle, die das Control als „versagt“ nennen (einer: höchstens teilweise wirksam, ab zwei: unwirksam) | Wirksamkeit wird gesetzt (nur Erstbewertung oder Abwertung) | ISB, Manager |
| Control herabstufen | Regel: offene Abweichung aus einem Audit gegen ein Control, das als umgesetzt gilt (ISO 27001 10.2) | Hauptabweichung: Umsetzung „in Arbeit“ (50 %); Nebenabweichung: „verifiziert“ wird zu „umgesetzt“. Die Erfüllung der Anforderungen dahinter rechnet sofort neu | ISB, Manager |
| Risiko nach Vorfall | Regel: Vorfall als eingetretenes Risiko geschlossen (90 Tage) | Wahrscheinlichkeit +1 (ISO 27001 6.1.2) | Risikomanager, Manager |
| Risiko aus Schwachstelle | Regel: offene Schwachstelle ab CVSS 7 ohne Risiko | Risiko wird angelegt und verknüpft | Risikomanager, Manager |
| Register: Thema, rechtliche Anforderung, Schnittstelle, Rolle, Kompetenz, Chance | Import: Kontext-Freitexte, Frameworks, interessierte Parteien, kritische Lieferanten, Persona-Rollen, letzte Managementbewertung | Registereintrag wird angelegt (siehe [Register](REGISTER.md)) | ISB; rechtliche Anforderungen Compliance-Manager, Chancen Risikomanager; Manager |
| Erfüllung übernehmen | Vererbung über Framework-Mappings | Erfüllungsgrad wird übernommen, Kommentar anpassbar | Compliance-Manager, ISB |
| Anforderung zuordnen | Textähnlichkeit auf der SoA-Seite | Maßnahme wird der Anforderung zugeordnet | Compliance-Manager, ISB |
| Risiko aus Vorfall (KI) | Sprachmodell, Vorfälle ohne Risiko | Risiko wird angelegt und mit dem Vorfall verknüpft | Risikomanager, Manager |
| SoA-Begründung (KI) | Sprachmodell, anwendbare Maßnahmen ohne Begründung (6.1.3 d) | Begründung wird eingetragen, vorher anpassbar | ISB, Compliance-Manager |
| Korrekturmaßnahme (KI) | Sprachmodell, offene Feststellungen ohne Maßnahme (10.2) | Korrekturmaßnahme wird angelegt | ISB, Auditverantwortliche |
| SoA-Begründung aus Verknüpfungen | Regel: behandelte Risiken, Anforderungen anderer aktiver Frameworks, Umsetzungsstand (6.1.3 d); nachts nur, solange die KI-Variante aus ist | Begründung wird eingetragen, vorher anpassbar | ISB, Manager |
| Meldetext Vorfall (KI) | Sprachmodell, Vorfall mit offener NIS-2- oder DORA-Meldung ohne Meldetext (NIS-2 Art. 23 Abs. 4, DORA Art. 19) | Text steht als „Meldetext an die Behörde“ am Vorfall und geht in den MUS-Export; „Als gemeldet markieren“ bleibt bei Ihnen | ISB, CISO |
| Meldung Datenpanne Art. 33 (KI) | Sprachmodell, meldepflichtige Datenpanne, noch nicht gemeldet | Leere Felder Art, Folgen, Maßnahmen (Art. 33 Abs. 3 a, c, d) werden gefüllt, im Dialog als ein Text mit drei Abschnitten anpassbar | DSB |
| Antwort Betroffenenanfrage (KI) | Sprachmodell, offene Anfrage ohne Antwort, Identität geprüft wo Art. 12 Abs. 6 es verlangt | Antworttext der Anfrage wird eingetragen; Versand und Abschluss bleiben beim DSB | DSB |
| Zusammenfassung für die Leitung (KI) | Sprachmodell, Managementbewertung mit mindestens drei Eingaben (9.3.2) und ohne Zusammenfassung | Zusammenfassung wird eingetragen; Entscheidungen bleiben bei der Leitung (9.3.3) | ISB, CISO |
| Abweichungstext (KI) | Sprachmodell, offene Haupt- oder Nebenabweichung mit kurzen Notizen | Notizen werden durch den Abweichungstext ersetzt (ISO 19011 6.4.8), Nachweise nur wenn leer | ISB |
| Auswertung Lieferantenfragebogen (KI) | Sprachmodell, beantworteter Sicherheitsfragebogen (A.5.19–5.22, NIS-2 Art. 21 Abs. 2 d) | Auswertung wird an die Bewertungsergebnisse des Lieferanten angehängt; die Punktzahl bleibt bei Ihnen | ISB, Manager |

Neben den Rollen der Tabelle entscheidet immer auch, wer für den Datensatz zuständig ist
(Risikoeigner, Asset-Verantwortliche, …; dieselbe Zuständigkeit wie in „Mein Tag“ und der
Sammelmail). Offene Vorschläge stehen bei diesen Personen in „Mein Tag“ und in der
Sammelmail um 07:00 und gelten zwei Wochen nach dem Entstehen als fällig.

Jede Art erscheint nur, wenn ihr Modul aktiv ist. Prüfer (Auditor, Konzern-Auditor,
Group CISO) sehen alle Vorschläge, auch die entschiedenen, aber nur lesend.

## Wann Vorschläge entstehen

- **Jede Nacht um 05:30** prüft der Scheduler alle Organisationen (vor der Sammelmail
  um 07:00). Es gibt keine eigene Cron-Zeile, siehe `HOSTING_WORKER.md`.
- **„Jetzt prüfen“** auf der Vorschlagsseite (ab Manager) startet die Prüfung sofort
  im Hintergrund.
- **Beim Aktivieren eines Frameworks** landen die Vererbungsvorschläge sofort in der Queue.
- **„Entwurf vorschlagen“** auf der Detailseite: über den Verknüpfungen stehen die Entwürfe,
  die Sie für diesen Datensatz anfordern dürfen, z. B. „Meldetext (KI)“ am Vorfall oder
  „SoA-Begründung aus Verknüpfungen“ an der Maßnahme. Regelentwürfe entstehen sofort,
  KI-Entwürfe im Hintergrund (Fortschrittsseite). Danach öffnet die Vorschlagsliste mit
  diesem Datensatz, dort nehmen Sie an oder lehnen ab. Ein Knopf erscheint nur, wenn das
  Modul aktiv ist, die KI eingerichtet ist (bei KI-Entwürfen), Sie den Vorschlag entscheiden
  dürfen und zu dieser Art noch keiner offen ist. Nach einer Ablehnung können Sie erneut
  anfordern.
- Fällt die Bedingung weg (z. B. das Restrisiko wurde von Hand eingetragen), wird der
  offene Vorschlag beim nächsten Lauf zurückgezogen.

## Entscheiden

- **Annehmen** übernimmt den Vorschlag. Wo Text vorgeschlagen wird (Begründung,
  Kommentar), kann er im Dialog vorher angepasst werden.
- **Ablehnen** mit optionaler Begründung. Derselbe Vorschlag kommt für denselben Stand
  nicht wieder.
- Hat sich der Datensatz inzwischen geändert, wird der Vorschlag nicht angewendet,
  sondern als veraltet zurückgezogen.
- **Sammelaktion:** Regelvorschläge können markiert und gemeinsam angenommen oder
  abgelehnt werden. KI- und Vererbungsvorschläge nur einzeln.
- Auf der Detailseite eines Datensatzes zeigt ein Hinweis über den Verknüpfungen, dass
  Vorschläge dazu offen sind.
- Auf der SoA-Seite einer Maßnahme blendet **„Passt nicht“** einen Zuordnungsvorschlag
  dauerhaft aus.

## KI einrichten

**Admin > Einstellungen > KI-Vorschläge** (`/admin/settings/ai`). Standard ist **aus**.

1. **Betreiber (Super-Admin), einmal je Instanz:** lokales Modell eintragen, z. B. Ollama
   unter `http://localhost:11434/v1` mit Modell `mistral`. Jeder OpenAI-kompatible Server
   geht (Ollama, LM Studio, vLLM). Daten verlassen das Haus nicht.
2. **Organisations-Admin:** Betriebsart wählen:
   - *Lokales Modell der Instanz* (empfohlen),
   - *Externe API*, z. B. Mistral (`https://api.mistral.ai/v1`, Modell
     `mistral-small-latest`) mit eigenem API-Schlüssel. Der Schlüssel wird verschlüsselt
     gespeichert. Erlaubt sind nur https-Adressen im öffentlichen Netz.
3. **Personenbezogene Daten:** Standardmäßig ersetzt das Tool vor jedem externen Aufruf
   Namen der Personen der Organisation, E-Mail-Adressen, Telefonnummern, IBAN und
   IP-Adressen durch Platzhalter. Erst der Schalter „Personenbezogene Daten senden“
   hebt das auf, nur mit Auftragsverarbeitungsvertrag einschalten.
4. **Einsatzzwecke** einzeln an- oder abwählen, dann **Verbindung testen**.

Jeder KI-Aufruf steht im Audit-Log (`ai.request`) mit Einsatzzweck, Modell, Zieladresse
und ob ersetzt wurde, nie mit Inhalt. Je Lauf schreibt die KI höchstens zehn Vorschläge
pro Einsatzzweck und fragt zu jedem Datensatz nur einmal; „Entwurf vorschlagen“ fragt
genau dann, wenn Sie es anfordern.

Entwürfe kann auch der eigene KI-Assistent einer Person einreichen (Claude Desktop/Code über MCP,
[Anleitung](KI_ASSISTENT_MCP.md)); sie stehen mit Modell „MCP: <Token-Bezeichnung>“ in derselben Liste.

Technik und neue Vorschlagsarten: `docs/features/SUGGESTION_QUEUE.md`.
