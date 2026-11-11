---
name: norm-abdeckung-bewerten
description: Bewertet, wie gut Little ISMS Helper ISO 27001, NIS-2 und DORA abdeckt, sweept bisher ungeprüfte Pfade und liefert Paketliste, Spec und Artefakt für die nächste Welle. Aktivieren bei "Normbewertung", "n-te Bewertung", "Restlücken", "Welle planen", "Norm-Abdeckung neu bewerten".
allowed-tools: Read, Grep, Glob, Bash, Write, Agent
---

# Norm-Abdeckung bewerten (Restlücken-Bewertung)

Wiederkehrendes Verfahren nach jeder Welle. Ergebnis: eine Spec `restluecken-welleN.md`, Sweep-Berichte und ein
Artefakt mit Zusammenfassung. Antworten und Dokumente auf Deutsch.

## Eingaben
- Vorlage = letzte Bewertung in `/mnt/project-files/normbewertung/` (aktuell `restluecken-welle10.md`, davor `welle9.md`).
  Zuerst lesen: Zahlentabelle, Gate-Reservierungen, Abschnitt „Nie gesweepte Pfade“ (= Kandidatenliste), Reihenfolge.
- Berichte der Threads der letzten Welle (Restpunkte, „parked points“, Kandidaten für die nächste Bewertung) und die
  Memory-Notiz „9th-assessment candidates“.
- Repo auf aktuellem `main` (`git fetch && git checkout origin/main`); Stand-SHA und Datum in die Spec schreiben.

## Verfahren
1. **Stand klären:** Welche Pakete der letzten Welle sind gemergt (`list_prs`, nie raten)? Offene Pakete als „läuft“
   markieren. Gate-Nummern aus CLAUDE.md / `scripts/README.md` lesen, nächste freie Nummer bestimmen.
2. **Sweeps über nie geprüfte Pfade** (Kandidatenliste der Vorlage + neue Wege seit der letzten Runde: Controller,
   Commands, Jobs, REST/MCP, Importe, Restore, Exporte, Mails/Webhooks, öffentliche Routen). Je Sweep ein
   günstiger Subagent (Sonnet/Haiku, nicht Opus), parallel, Auftrag mit: Pfadliste, Prüffrage (Mandantengrenze, Rolle,
   Modul-Gate, CSRF, Nachweis nur durch den Schritt, Löschen/Archiv, Last, i18n, a11y), Ausgabeformat
   `ID | Schwere | file:line | Beleg | Vorschlag`, „gelesen“ vs. „ausgeführt“ kennzeichnen. Berichte nach
   `/mnt/project-files/normbewertung/welleN-sweep/<nr>-<thema>.md`.
3. **Kritische Funde sofort melden:** Jeden KRITISCH/HOCH-Fund selbst am Code nachlesen. Bestätigt → sofort an den
   Coordinator (Hotfix-Thread S-Serie), nicht bis zum Ende der Bewertung warten.
4. **Normen neu bewerten:** ISO 27001 (23 Themen U4), NIS-2 (9), DORA (Art. 26 usw.) je Fachabdeckung und
   Integrität (Nachweis vom Workflow-Schritt geschrieben, S12). Regression gegenüber der Vorrunde ausweisen.
5. **Wizard „manual“ zählen:** Prüfungen im Compliance-Wizard ohne Datenableitung je Norm (ISO / NIS-2 / DORA und
   übrige) zählen und gegen die Vorrunde stellen; Ziel ISO/NIS-2/DORA = 0.
6. **Mutations-Gegenprobe:** Gegen die AST-Gates (`tests/PHPStan/Rule/Gate/`) neue Mutationen (Kontrollfluss,
   Vererbung, Traits, Aliase) bauen; PHPStan-Lauf per `scripts/setup/cloud_vendor.py` + `phpstan.cloud.neon`. Zahl
   grün/rot, gelaufen/gelesen berichten. Stilles Grün = Gate prüfen, nicht feiern.
7. **Pakete bilden:** je Fund-Gruppe ein Paket (S = Sicherheit/Hotfix, W = Wert/UX, T = Gates, B = Betrieb/Doku) mit
   Schwere, Umfang, Akzeptanz, Dauertest. Reihenfolge-Tabelle (was parallel, was nach wem wegen gleicher Dateien).
   **Gate-Nummern reservieren** (nächste freie, je Paket eine; Pakete ohne eigenes Gate sagen das ausdrücklich).
8. **Neue Kandidatenliste** für die nächste Bewertung am Ende der Spec („Nie gesweepte Pfade“).

## Ausgabe
- `/mnt/project-files/normbewertung/restluecken-welleN.md` mit Aufbau der Vorlage: Kopf (Stand, Berichtspfade,
  Gate-Reservierungen) → Zahlen-Tabelle (Vorrunde / Jetzt / Bemerkung) → Urteil in 1 Absatz → ein Abschnitt je Paket →
  Nie gesweepte Pfade → Reihenfolge.
- Sweep-Berichte wie oben. „Annahme“ = zur Laufzeit nicht geprüft, immer so benennen.
- Artefakt (Artifact-Tool) mit Zusammenfassung: Zahlen, Urteil, Paketliste, Reihenfolge, Links zu Spec/Berichten.
- Memory aktualisieren (Gate-Reservierungen, Kandidaten, Link zum Artefakt).

## Regeln (Michels Linien)
- Coordinator/Threads entscheiden selbst; nur fragen, wenn die Linie unbekannt ist.
- Ehrlich antworten: ungeprüft ist ungeprüft; „Haben wir X gesweept?“ → aus der offenen Liste beantworten.
- Keine Code-Änderungen in der Bewertung; Pakete setzen Threads um (alle Gates, CI grün, Squash-Merge, Doku).
- Keine personenbezogenen Daten oder Serverdomains in Berichte und Memory.
