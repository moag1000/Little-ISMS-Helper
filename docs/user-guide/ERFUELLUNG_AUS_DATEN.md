# Erfüllung aus Daten (Regel statt Schieberegler)

Der Erfüllungsgrad einer Anforderung wird aus den Daten der Organisation berechnet,
für jede Norm mit derselben Regel. Pflegen Sie das Control in der SoA, und
ISO 27001, NIS-2, BSI IT-Grundschutz, TISAX, DORA und alle gemappten Normen zeigen
den neuen Stand sofort.

## Die Regel

Die erste Quelle mit Daten gilt:

| Quelle | Woher | Beispiel |
|---|---|---|
| **Reifegrad** | eigener TISAX-/VDA-ISA-Import | Reifegrad 3 → 100 % |
| **Controls** | das Annex-A-Control mit gleicher Nummer, die `iso_controls` des Katalogs, explizit verknüpfte Controls | A.5.1 umgesetzt → 100 %; zwei Controls (100 % + in Arbeit) → 75 % |
| **Datensätze** | die Prüfungen des Compliance-Wizards zur Klausel oder zum Artikel | ISO 27001 7.2 aus den Schulungen, 9.3 aus den Managementbewertungen, 10.2 aus den geschlossenen Audit-Findings, DORA Art. 28 aus dem Lieferantenregister |
| **Mapping** | freigegebene Cross-Mappings auf Anforderungen, die schon erfüllt sind | ISO A.5.1 zu 100 % × Mapping 80 % → 80 % |

- Ein umgesetztes oder verifiziertes Control zählt 100 %, ein teilweise umgesetztes Control zählt mit seinem Prozentwert, „in Arbeit“ ohne Prozentwert zählt 50 %, alles andere zählt 0 %.
- Controls, die die SoA ausschließt, zählen nicht. Für eine Annex-A-Anforderung entscheidet die SoA über die Anwendbarkeit samt Begründung. Es gibt also eine Wahrheit, nicht zwei.
- Bei mehreren Mappings gilt das beste. Mappings anderer Organisationen zählen nie.
- Datensätze: Jede Wizard-Prüfung nennt ihre Klausel („7.2“) oder ihren Artikel („21(2)(a)“). Sie gilt für die Anforderung mit dieser Nummer und, ab zwei Ebenen, für deren Unterpunkte („9.2“ gilt für 9.2.1 und 9.2.2). Anforderungs-IDs in Artikelschreibweise („Art.21.2.a“, „NIS2-ART21-A“) zählen wie ihre Nummer. Zählt ein Katalog seine Zeilen anders als der Artikel (thematischer NIS-2-Katalog: „NIS2-23.1“ ist die Frühwarnung nach Art. 23 (4)(a); thematischer DORA-Katalog „DORA-x.y“), nennt die Prüfung diese Zeilen zusätzlich in `requirements`. Ein Test prüft, dass jede Prüfung mit Klausel oder Artikel mindestens eine Anforderung des geladenen Katalogs trifft, und dass jeder Prüftyp einen Handler hat. Mehrere Prüfungen werden gemittelt. Manuelle Prüfungen, Prüfungen nicht aktiver Module und leere Module zählen nicht: Ein leeres Modul beweist nichts, die Anforderung bleibt dann bei der Bewertung von Hand.
- Stehen hinter einer Anforderung Controls **und** Datensätze, zählen beide: der Mittelwert aus beiden, Quelle „Aus Controls und Datensätzen“. Beispiele: NIS-2 Art. 20 (1) aus den Controls 5.1/5.3 und der Billigung durch die Leitung, A.6.3 aus dem Control und den abgeschlossenen Schulungsteilnahmen, NIS-2 Art. 21 (2)(b) aus den Controls und den Vorfällen. Prüfungen, die nur Controls zählen, fallen dann weg, damit nichts doppelt zählt.
- Ohne Daten bleibt es bei der Bewertung von Hand (Schieberegler auf der Anforderung).
- Reifegrade gehören der Organisation: Ist und Soll eines MRIS-MHC und das Soll aus einer Branchen-Baseline stehen an der Erfüllungszeile der eigenen Organisation, nicht an der Anforderung, die alle Organisationen teilen. Eine andere Organisation sieht und rechnet nie mit diesen Werten. Das Soll, das ein Katalog mitbringt (TISAX: „established“), gilt, bis die Organisation ein eigenes setzt. Ein selbst hochgeladener TISAX-/VDA-ISA-Katalog behält seinen Reifegrad an den eigenen Zeilen.

### Das Audit-Urteil deckelt

Über allen Quellen steht das Urteil aus der Audit-Checkliste (ISO 27001 9.2): Das neueste
Urteil der letzten zwölf Monate begrenzt den Wert. „Nicht konform“ setzt die Anforderung auf
0 %, „teilweise“ auf den Konformitätsgrad der Zeile (ohne Angabe 50 %), „konform“ lässt den
Wert, wie er ist. Ist die Anforderung zu 100 % erfüllt und das Audit sagt „konform“, steht sie
auf **Verifiziert**. Ohne andere Quelle gilt das Urteil allein (Quelle „Aus Audit“). „Nicht
anwendbar“ und „nicht geprüft“ zählen nicht. Unter „Warum dieser Wert?“ steht das Audit mit
Datum; der Link führt zur Checkliste.

Die Checkliste selbst entsteht beim ersten Öffnen aus dem Scope des Audits: eine Zeile je
Anforderung jedes Frameworks im Scope (ein Audit ohne Framework prüft ISO 27001), mit den
Nachweisen der Organisation zur Anforderung schon eingetragen. Nach einer Scope-Änderung
ergänzt **„Aus dem Scope ergänzen“** neue Anforderungen; vorhandene Urteile bleiben.

### Welche Datensätze welche Klausel speisen

| Datensatz | Prüfung | Klauseln |
|---|---|---|
| Register (ISMS > Register, siehe [REGISTER.md](REGISTER.md)) | Einträge vorhanden und nicht überfällig zur Prüfung | ISO 27001 4.1 (Themen), 4.2 (rechtliche Anforderungen), 4.3 (Schnittstellen), 5.3 (Rollen), 6.1.1 (Chancen), 7.2 (Kompetenzen), 7.4 (Kommunikation), 10.1 (Verbesserungen; offene Vorschläge aus Bewertung, Audit und Erkenntnissen zählen als fehlend), A.8.16 (überwachte Quellen) |
| Rollenregister | jede vergebene Persona-Rolle (ISB, DSB, Leitung …) steht im Register; nicht übernommene Vorschläge zählen als fehlend | ISO 27001 5.3 |
| Leitungsentscheidungen | Billigung in Kraft 100 %, vorgelegt 50 %, abgelaufen 25 % | ISO 27001 5.1 (Leitlinie, Ressourcen, Ziele), 5.2, 6.1.2 (Risikokriterien), 6.1.3 (SoA), 6.2, 7.1, 9.3.3; NIS-2 Art. 20 (1); DORA Art. 5 (2), 6 (1) (IKT-Risikomanagementrahmen), 6 (8) (Resilienzstrategie), 28 (2) (Drittparteienstrategie) |
| Fristen (Mein Tag, Pflichtenkalender) | Anteil der offenen Pflichten, die nicht überfällig sind (`deadline_adherence`) | ISO 27001 8.1 (alle Fristenquellen), 9.1 (Zielmessung und Wirksamkeitstests; Ziele mit Kennzahl misst das Tool und zählen als fristgerecht; neu berechnet, sobald ein Datensatz einer Fristenquelle angelegt, gelöscht oder in Datum oder Status geändert wird) |
| Administratorkonten | Anteil der Administratorkonten mit lokaler Anmeldung, die einen zweiten Faktor nutzen (`account_mfa`; SSO-Konten zählen nicht, dort entscheidet der Identity Provider) | NIS-2 Art. 21 (2) (j), neben dem Status von Control A.8.5 |
| Angaben an vorhandenen Datensätzen | Anteil der Datensätze, die die verlangten Felder tragen (`record_completeness`, Felder in der Wizard-Definition) | ISO 27001 6.3 (freigegebene Änderungen der letzten 24 Monate mit Risikobewertung, Freigeber und Umsetzungsdatum), A.5.7 (Bedrohungsmeldungen der letzten 12 Monate mit Schweregrad und Empfehlung), A.5.23 (aktive Lieferanten mit Kennzeichen „Cloud-Dienst“: Bewertung, Sicherheitsanforderungen, Exit-Strategie), A.5.30 (aktive BC-Pläne mit RTO, RPO und Test); DORA Art. 14 (1) (Kommunikationsplan im BC-Plan), 17 (2) (Vorfälle der letzten 12 Monate eingestuft), 18 (1) (betroffene Kunden und wirtschaftliche Auswirkung), 26 (1) (abgeschlossener TLPT mit Enddatum und Zusammenfassung in den letzten 36 Monaten; ein geplanter zählt nicht), 30 (2) (Leistung, Standorte, AVV, Service-Level bei IKT-Lieferanten) |
| Klauseln 4–10 zusammen | Anteil der Datenprüfungen der Klauseln 4–10 mit mindestens 60 % (`clause_coverage`) | ISO 27001 4.4 |
| Schulungsteilnahmen | Anteil abgeschlossener Teilnahmen, befreite zählen nicht | ISO 27001 7.3, A.6.3 |
| Meldepflichten | Anteil der fälligen Meldungen, die rechtzeitig gemacht wurden | NIS-2 Art. 23 (4)(a)–(d) (Zwischenbericht nur auf Anforderung); DORA Art. 19 (4)(a)–(c); BSI C5:2026 NIS-2-Abgleich |
| NIS-2-Registrierung | Registrierung bestätigt und Datenänderungen binnen zwei Wochen gemeldet (`deadline_adherence` der Fristquelle `nis2_registration`) | NIS-2 Art. 3 (4), Art. 27 |

Gibt es zu einer Prüfung noch keinen einzigen Datensatz (kein Registereintrag, keine Entscheidung dieses Typs), zählt sie nicht. Die Anforderung bleibt dann bei ihren anderen Quellen oder der Bewertung von Hand.

### NIS-2-Dashboard

Die Kacheln Art. 21 (2)(a)–(j) im NIS-2-Dashboard und in der Anforderungsübersicht zeigen die Erfüllung der jeweiligen Anforderung, also denselben Wert wie die Anforderung selbst. Die Kennzahl der Kachel (zum Beispiel der Anteil gelöster Vorfälle) steht darunter als „Indicator“. Eine Kachel, deren Anforderung noch niemand bewertet hat, zeigt weiter nur die Kennzahl.

Auf der Anforderung zeigt die Karte **Erfüllung** den Wert, die Quelle und unter
„Warum dieser Wert?“ jedes Control oder Mapping, aus dem er stammt.

## Nachweise je Anforderung

Die Karte **Nachweise** auf der Anforderung zeigt jedes Dokument Ihrer Organisation, das hinter ihr steht. Das sind Dokumente an der Anforderung selbst, an ihrer Erfüllung und an den Controls, die die Regel liest (Nachweise am Control und Dokument-Control-Verknüpfungen). Ein Nachweis an Control A.5.1 zählt damit für ISO 27001, NIS-2, TISAX und jede andere Norm, hinter der A.5.1 steht. Daneben steht, woher der Nachweis kommt.

Das **Audit-Paket** (Compliance > Framework > Audit-Paket) und das **Zertifizierungs-Bundle** legen genau diese Nachweise ab. Dokumente anderer Organisationen und archivierte Dokumente sind nicht dabei. Die Karte „Controls dahinter“ zeigt nur die Controls Ihrer Organisation.

## Abweichen: Übersteuerung mit Begründung

Wissen Sie es besser als die Daten, dann setzen Sie auf der Anforderung eine
**Übersteuerung** mit Wert, Begründung (mindestens 10 Zeichen) und Prüfdatum. Dafür
brauchen Sie ROLE_MANAGER. Der Regelwert bleibt daneben sichtbar. Mit **„Regel
übernehmen“** folgt die Anforderung wieder der Regel. Jede Übersteuerung landet im
Audit-Log.

Übersteuerungen entstehen auch automatisch:

- **Altbestand:** Ein Wert, der vor der Regel von Hand gepflegt wurde und von ihr abweicht, bleibt erhalten. Er wird als Übersteuerung „Altbestand“ geführt und ist in 90 Tagen zu prüfen.
- **Zertifikat:** Ein hochgeladenes Zertifikat (Compliance > Zertifikate) setzt 100 % mit Status „Verifiziert“ bis zu seinem Ablaufdatum.
- **Vererbung:** Eine bestätigte Vererbung mit eigener Begründung gilt ein Jahr.

## Liste „Übersteuerungen“

Sie finden die Liste unter Compliance > Übersteuerungen, oder Sie suchen mit ⌘K nach „Übersteuerungen“.
Auditoren dürfen sie lesen. Filter: Altbestand, Prüfung fällig, von Hand, Zertifikat,
Vererbung. **„Regel für N Altbestände übernehmen“** übergibt mit einem Klick jeden
Altbestand, für den eine Regel rechnet, wieder der Regel.

## Wann wird gerechnet?

- Sofort, wenn ein Control gespeichert wird (Status, Prozent, Anwendbarkeit, Begründung) oder wenn sich die verknüpften Controls einer Anforderung ändern. Gemappte Normen ziehen in derselben Anfrage nach.
- Sofort, wenn ein Registereintrag, eine Leitungsentscheidung, eine Schulung, eine Schulungsteilnahme, ein Vorfall, eine Datenpanne, ein Lieferant, ein BC-Plan, ein Änderungsantrag, eine Bedrohungsmeldung, ein internes Audit oder ein Administratorkonto (Rollen, Aktivierung, Anmeldeart, MFA) gespeichert wird: Die Klauseln, die diese Datensätze lesen, rechnen neu (`RecordChangeCollector`). Fristtreue (ISO 27001 8.1, 9.1) rechnet neu, sobald ein Datensatz einer Fristenquelle angelegt, gelöscht oder in Datum oder Status geändert wird.
- Sofort, wenn in der Audit-Checkliste ein Urteil gespeichert wird: die geprüfte Anforderung und alles, was von ihr erbt.
- Beim Klick auf **„Neu berechnen“** im Framework-Dashboard.
- Die regelmäßige Prüfung einer Erfüllung („Nächste Prüfung“, von Zertifikaten auf ihr Ablaufdatum gesetzt) erscheint 30 Tage vorher in „Mein Tag“ (Abschnitt „Erfüllung — Prüfung fällig“) bei der verantwortlichen Person der Anforderung, ersatzweise bei Compliance-Manager und ISB. Auf der Anforderung bestätigt **„Prüfung bestätigen“** die Prüfung und setzt die nächste (Vorgabe zwölf Monate); der Wert selbst bleibt bei der Regel.
- Fällige Übersteuerungen erscheinen 14 Tage vor dem Prüfdatum in „Mein Tag“ (Abschnitt „Erfüllung — Übersteuerung prüfen“) und in der täglichen Sammelmail bei der verantwortlichen Person der Anforderung, ersatzweise bei Compliance-Manager und ISB.
- Jede Nacht um 00:45 für alle Organisationen (Scheduler-Task `app:compliance:refresh-fulfillments`). So kommen auch neue oder freigegebene globale Mappings an, und die Werte aus Datensätzen (Schulungen, Audits, Lieferanten …) werden aktuell.

## Hinweis für Betreiber

Nach dem Update läuft die Migration, und es bleibt nichts weiter zu tun. Neue Quellen wirken ab der nächsten Nacht oder nach „Neu berechnen“. Die erste Nacht rechnet alle
Organisationen durch und legt den Altbestand an. Wer nicht warten will, klickt im
Framework-Dashboard auf „Neu berechnen“. Danach lohnt ein Blick in die Liste
„Übersteuerungen“ (Filter Altbestand).
