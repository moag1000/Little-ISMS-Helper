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
| **Datensätze** | die Prüfungen des Compliance-Wizards zur Klausel oder zum Artikel | ISO 27001 7.2 aus den Schulungen, 9.3 aus den Managementbewertungen, 10.2 aus den Korrekturmaßnahmen, DORA Art. 28 aus dem Lieferantenregister |
| **Mapping** | freigegebene Cross-Mappings auf Anforderungen, die schon erfüllt sind | ISO A.5.1 zu 100 % × Mapping 80 % → 80 % |

- Ein umgesetztes oder verifiziertes Control zählt 100 %, ein teilweise umgesetztes Control zählt mit seinem Prozentwert, „in Arbeit“ ohne Prozentwert zählt 50 %, alles andere zählt 0 %.
- Controls, die die SoA ausschließt, zählen nicht. Für eine Annex-A-Anforderung entscheidet die SoA über die Anwendbarkeit samt Begründung. Es gibt also eine Wahrheit, nicht zwei.
- Bei mehreren Mappings gilt das beste. Mappings anderer Organisationen zählen nie.
- Datensätze: Jede Wizard-Prüfung nennt ihre Klausel („7.2“) oder ihren Artikel („21(2)(a)“). Sie gilt für die Anforderung mit dieser Nummer und, ab zwei Ebenen, für deren Unterpunkte („9.2“ gilt für 9.2.1 und 9.2.2). Mehrere Prüfungen werden gemittelt. Manuelle Prüfungen, Prüfungen nicht aktiver Module und leere Module zählen nicht: Ein leeres Modul beweist nichts, die Anforderung bleibt dann bei der Bewertung von Hand.
- Ohne Daten bleibt es bei der Bewertung von Hand wie bisher (Schieberegler auf der Anforderung).

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
- **Zertifikat:** Ein hochgeladenes Zertifikat (Compliance > Zertifikate) setzt 100 % bis zu seinem Ablaufdatum.
- **Vererbung:** Eine bestätigte Vererbung mit eigener Begründung gilt ein Jahr.

## Liste „Übersteuerungen“

Sie finden die Liste unter Compliance > Übersteuerungen, oder Sie suchen mit ⌘K nach „Übersteuerungen“.
Auditoren dürfen sie lesen. Filter: Altbestand, Prüfung fällig, von Hand, Zertifikat,
Vererbung. **„Regel für N Altbestände übernehmen“** übergibt mit einem Klick jeden
Altbestand, für den eine Regel rechnet, wieder der Regel.

## Wann wird gerechnet?

- Sofort, wenn ein Control gespeichert wird (Status, Prozent, Anwendbarkeit, Begründung) oder wenn sich die verknüpften Controls einer Anforderung ändern. Gemappte Normen ziehen in derselben Anfrage nach.
- Beim Klick auf **„Neu berechnen“** im Framework-Dashboard.
- Fällige Übersteuerungen erscheinen 14 Tage vor dem Prüfdatum in „Mein Tag“ (Abschnitt „Erfüllung — Übersteuerung prüfen“) und in der täglichen Sammelmail bei der verantwortlichen Person der Anforderung, ersatzweise bei Compliance-Manager und ISB.
- Jede Nacht um 00:45 für alle Organisationen (Scheduler-Task `app:compliance:refresh-fulfillments`). So kommen auch neue oder freigegebene globale Mappings an, und die Werte aus Datensätzen (Schulungen, Audits, Lieferanten …) werden aktuell.

## Hinweis für Betreiber

Nach dem Update läuft die Migration, und es bleibt nichts weiter zu tun. Die erste Nacht rechnet alle
Organisationen durch und legt den Altbestand an. Wer nicht warten will, klickt im
Framework-Dashboard auf „Neu berechnen“. Danach lohnt ein Blick in die Liste
„Übersteuerungen“ (Filter Altbestand).
