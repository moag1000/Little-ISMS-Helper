# Register der Norm

ISO 27001 und NIS-2 verlangen eine Reihe von Verzeichnissen, die kein eigenes Fachmodul haben:
Kontextthemen, rechtliche Anforderungen, Rollen, Kompetenzen, Kommunikation und andere. Das Tool
führt sie in **einem** Register-Mechanismus. Ein Register ist ein Typ (`App\Enum\RegisterType`),
keine eigene Tabelle; alle Einträge liegen in `isms_register_entry`, Verknüpfungen zu anderen
Datensätzen in `isms_register_link`.

Einstieg: **ISMS > Register** (`/register`), über ⌘K „Register“ oder von der Kontextseite aus.

## Die Register

| Register | Norm | Pflegt | Vorschläge kommen aus |
|---|---|---|---|
| Externe Themen | ISO 27001 4.1 | ISB | Kontext: externe Themen (Freitext) |
| Interne Themen | ISO 27001 4.1 | ISB | Kontext: interne Themen (Freitext) |
| Rechtliche und vertragliche Anforderungen | ISO 27001 4.2 c, A.5.31 · NIS-2 Art. 21 | Compliance-Manager | aktive verpflichtende Frameworks, Anforderungen der interessierten Parteien, Kontext-Freitexte |
| Schnittstellen des Geltungsbereichs | ISO 27001 4.3 c | ISB | kritische Lieferanten |
| Rollen und Zuständigkeiten | ISO 27001 5.3 · NIS-2 Art. 20 | ISB | Inhaber der Persona-Rollen (CISO, ISB, DSB, …), Datenschutzbeauftragter der Organisation |
| Chancen | ISO 27001 6.1.1 | Risikomanager | Chancen aus dem letzten Management-Review |
| Kompetenzen | ISO 27001 7.2 · NIS-2 Art. 20 (2) | ISB | Standard-Kompetenzen je Persona-Rolle im Rollenregister |
| Kommunikation | ISO 27001 7.4 | ISB | — (Kommunikationsplan der interessierten Parteien wird darunter angezeigt) |

Register mit eigenem Fachmodul (interessierte Parteien, Risiken, SoA, Ziele, Schulungen, Dokumente,
Assets, Standorte, Prozesse, Lieferanten, VVT) stehen in derselben Übersicht mit Norm, pflegender
Persona und Anzahl, damit ein Auditor alle Verzeichnisse an einer Stelle findet.

## Vorschläge statt Doppelpflege

Über jeder Liste stehen Vorschläge aus Daten, die das Tool schon hat. Wer mindestens
`ROLE_MANAGER` hat, hakt an und übernimmt. Ein übernommener Vorschlag merkt sich seine Herkunft
(`origin`) und erscheint nicht noch einmal. Die alten Kontext-Freitexte bleiben erhalten und
werden im Kontextformular nur noch angezeigt, wenn sie befüllt sind.

Dieselben Vorschläge erscheinen nachts auch in der Vorschlags-Queue (**Vorschläge**, „Mein Tag“,
Sammelmail) bei der pflegenden Persona: rechtliche Anforderungen beim Compliance-Manager, Chancen
beim Risikomanager, alle übrigen beim ISB. Was auf der Registerseite übernommen wurde, verschwindet
beim nächsten Lauf aus der Queue und umgekehrt.

## Kompetenzlücken

Eine Kompetenz kann mit Schulungen verknüpft werden, eine Rolle mit Kompetenzen. Hat der
Rolleninhaber eine verknüpfte Schulung abgeschlossen oder die Kompetenz in seinem Profil, gilt sie
als belegt; sonst erscheint sie als Lücke in der Rolle, in der Rollenliste und in der Übersicht.

## Geltungsbereich (4.3)

Assets, Standorte, Prozesse und Lieferanten haben das Merkmal **„Im Geltungsbereich des ISMS“**
(Standard: ja). Die Listen filtern danach, die Detailseite zeigt es, die Register-Übersicht und die
Kontextseite zählen, was drin und was draußen ist. Die Leitlinie (5.2) wird im Kontext als Dokument
verknüpft statt als Freitext gepflegt.

## Prüfung

Jeder Eintrag hat einen Verantwortlichen (Benutzer oder Person, dazu Vertretungen) und ein nächstes
Prüfdatum: zwölf Monate, beim Kommunikationsplan drei. Die Prüfung ist eine Fristenquelle
(`RegisterReviewSource`, 30 Tage Vorlauf) für „Mein Tag“ und die Sammelmail: zuständig ist der
Verantwortliche, dann die Vertretung, sonst die pflegende Persona. „Geprüft“ setzt das Datum weiter, nicht mehr
gültige Einträge werden archiviert statt gelöscht (Löschen nur `ROLE_ADMIN`). Änderungen landen im
Audit-Log.

## Nachweis für das Audit

- `/register/export.csv` – alle Register als eine Tabelle.
- Das Audit-Paket enthält je Framework `REGISTERS.csv`.
- Datensatzseiten von interessierten Parteien, Lieferanten und Schulungen zeigen, in welchen
  Registereinträgen sie vorkommen.
- Sicherung und Wiederherstellung der Organisation umfassen die Register.
- Die automatisch vorbereitete Managementbewertung listet unter 9.3.2 b alle Einträge zu Themen,
  rechtlichen Anforderungen und Schnittstellen, die seit der letzten Bewertung neu, geändert oder
  archiviert sind.
