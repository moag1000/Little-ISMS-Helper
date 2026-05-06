# Junior-Implementer Walkthrough — Little ISMS Helper

> **Wer ich bin:** Ich bin Marko, IT-Admin bei einem Mittelständler, 11 Jahre AD/Exchange/Jira-Admin, seit 2019 nebenher QMB für unser 9001-QM-System. Seit drei Monaten hat mir der Chef dazu 27001 draufgedrückt — "du kennst dich mit Prozessen aus, das machst du jetzt mit". ISB/CISO gibt's nicht, nur mich und einen Berater auf Zuruf.
> **Aufgabe:** Mach dich mit dem Little ISMS Helper vertraut, Montag sollst du das für die ganze Firma pflegen.
> **Datum:** 2026-04-17
> **Stimmung zu Beginn:** Skeptisch-neugierig. Ich hab schon zwei andere ISMS-Tools gesehen (Verinice, ein Excel-Monster) — viel erwarte ich nicht. Positiv: Deutsch in der Navi, das ist schon mehr als bei den Amerikanern.

---

> Visuelle Sicht aus Junior-Perspektive: [Sichtwechsel — Junior-Implementer](sichtwechsel/implementer-junior.md)

## 1. Erster Eindruck nach Login

Ich logge mich ein. Statt Dashboard lande ich auf einer **Welcome-Seite** — groß Logo, "Dringende Aufgaben"-Kasten (bei mir leer, weil leere Tenant), darunter Compliance-Wizards, darunter eine Kachelwand "Aktive Module". Ganz unten ein "Begrüßungsseite nicht mehr anzeigen"-Haken. Okay, das versteh ich. Man denkt an mich.

![Welcome-Screen](sichtwechsel/img/implementer-junior/welcome.png)

Die **Navigation** oben ist ein Mega-Menü. Ich klicke mich durch und baue mir gedanklich die Reihenfolge:

- **Dashboard** (Home, Dashboard, Analytics)
- **ISMS-Kern** (Kontext, Interessierte Parteien, SoA, Ziele)
- **Assets & Risiko** (Assets, Lieferanten, Standorte, Personen, Risiken, Risikomatrix, Risikoappetit, Behandlungspläne)
- **BCM** (Geschäftsprozesse, BC-Pläne, Übungen, Krisenteam)
- **Datenschutz** (Verarbeitungstätigkeiten, DSFA, Data Breaches)
- **Operations** (Dokumente, Change Requests, Workflows, Vorfälle, Schwachstellen, Patches)
- **Compliance** (Audits, Schulungen, Management Reviews, Frameworks, NIS2, Compliance-Wizard, Mapping-Qualität)
- **Admin** (sehe ich als Admin: Mandanten, Nutzer, Rollen, Module, Backup, Audit-Log, Lizenzierung …)

**Was mir sofort auffällt:**
- Sehr viele Punkte. Als QMB-9001-Denker frage ich mich: "Was muss ich zuerst, was kann warten?" — darauf gibt die Navi keine Antwort. Alphabetisch-funktional sortiert, nicht nach ISMS-Aufbau-Sequenz.
- Die Welcome-Seite hat einen "First Steps"-Hinweis (laut Code), sehe ihn aber nur wenn er nicht weggeklickt wurde. Einen **geführten Onboarding-Wizard ("Erstelle jetzt deinen Kontext → Assets → Risiken")** gibt's nicht. Die Module-Kacheln sind lose — ich muss die Reihenfolge selbst erahnen.
- "ISMS-Kern" klingt nach Startpunkt — aber "Assets" liegt unter einer separaten Kategorie. Für einen Neuling sieht das aus wie zwei getrennte Welten, obwohl Assets die Grundlage fürs Risikomanagement sind.
- **Positiv:** Die Dringend-Aufgaben-Karte ganz oben ist ein guter "wo muss ich heute drauf schauen"-Anker — sobald erstmal Daten drin sind.

**Frust beim ersten Eindruck: 2/5.** Noch ist alles sauber, aber ich sehe kein "Klick hier zuerst"-Signal. Ich fühle mich wie in einem voll-möblierten Haus ohne Grundriss.

---

## 2. Modul-für-Modul

Ich habe **13 von 14 Modulen** abgedeckt. **Übersprungen:** "Organisationsstruktur / Corporate Structure" als eigenes Fachmodul — das ist im Tool eine Admin-Funktion (`admin/tenants/corporate_structure.html.twig`), nicht ein ISMS-Fachmodul. Ich erwähne es kurz beim Punkt 2.3, bewerte es aber nicht als eigenes Modul.

### 2.1 Kontext / ISMS-Scope

**Index:** Wenn leer, gibt's einen **Empty-State** mit "Kontext jetzt anlegen"-Button und einer schönen Info-Box unten mit vier nummerierten Kacheln (Klausel 4.1, 4.2, 4.3, 4.4). Das ist **gut gemacht** — die Norm-Inhalte stehen als Checkliste da ("externe Themen, interne Themen, Scope, Ausschlüsse"). Das kenne ich aus 9001, da heißt es Kontext auch so.

**Neu-Form:** Ich klicke auf "Kontext anlegen" und lande direkt im Edit-Formular (ohne separates "new"). Felder: Organisationsname, ISMS-Scope, Scope-Exclusions, externe Themen, interne Themen, interessierte Parteien (als **Freitext!**), Anforderungen der Parteien (Freitext), rechtliche Anforderungen, regulatorische Anforderungen, vertragliche Pflichten, ISMS-Policy, Rollen, Review-Daten.

**Was mich verwirrt:**
- Ich habe daneben ein eigenes Modul "Interessierte Parteien" (strukturiert!). Hier im Kontext-Dialog ist das aber nochmal ein **Freitextfeld**. Was ist der Unterschied? Ein kleiner Link "Zum Interessierte-Parteien-Modul" ist da — aber nicht sofort klar, welches das "Master"-Feld ist. Ich fülle den Freitext aus, weil er vor mir liegt, und pflege nachher nochmal strukturiert dieselbe Info. Doppelarbeit.
- **ISMS-Scope vs. Scope-Exclusions**: okay, das kenn ich. Aber "externe Themen" und "interne Themen"? In 9001 fülle ich da PESTEL/SWOT rein — hier steht kein Hinweis, was erwartet wird. **Tooltips? Beispiel-Text?** Fehlanzeige.

**9001-Analogie:** **passt gut.** "Kontext der Organisation" gibt's in 9001:2015 genauso. Wer 9001 kann, versteht 27001 Kap. 4 analog. Einziger Unterschied: Hier kommt noch ISMS-Policy + Rollen mit rein.

**Frust-Score: 2/5.** Info-Box unten ist Gold wert. Der Interessierte-Parteien-Freitext im Kontext-Formular ist aber Quatsch, wenn es ein eigenes Modul gibt.

**Notiz:** *"Gute Hilfe unten, aber Freitext neben strukturiertem Modul = 'was pflege ich wo?'"*

---

### 2.2 Interessierte Parteien

**Index:** Alert-Banner oben "Überfällige Kommunikationen" (leer bei mir), dann eine Liste. Wenn leer, steht nur ein kurzer Satz "Noch keine Parteien — fügen Sie die erste hinzu". **Minimaler Empty-State**, kein Beispiel, keine Vorschlagsliste ("Kunde, Lieferant, Aufsichtsbehörde, Mitarbeiter, Eigentümer …"). Als Einsteiger denke ich: "Soll ich wirklich jeden Kunden einzeln anlegen, oder Kundengruppen? Ist mein Steuerberater eine interessierte Partei?"

**Neu-Form:** Name, Typ, Wichtigkeit (low/medium/high), Beschreibung, Kontaktperson, Mail/Phone, Anforderungen, wie-adressiert, Kommunikations-Frequenz/Methode/last/next, Feedback, Zufriedenheitslevel, Issues. **Fünf Sektionen, viele Felder.** Tooltips gibt's laut Template für `satisfactionLevel` und `importance`. Was die anderen Felder bedeuten, muss ich raten.

**9001-Analogie:** "Interessierte Parteien" = 1:1 aus 9001:2015 Kap. 4.2. Ich kenne das. Aber in 9001 habe ich meist nur Name + Erwartungen dokumentiert — hier werden Engagement-Score, Zufriedenheit, letzte Kommunikation abgefragt. Wirkt wie Stakeholder-Management aus PRINCE2/PMI. **Mehr als 9001 liefert.**

**Frust-Score: 3/5.** Zu viele Pflichtfelder gefühlt, keine Beispielliste, der "Engagement-Score" ist eine Black Box (wird berechnet, aber wo steht die Formel?).

**Notiz:** *"Empty-State ohne Beispiele. Engagement-Score ohne Erklärung irritiert."*

---

### 2.3 Organisationsstruktur / Corporate Structure

Gibt es im User-Bereich **nicht als eigenständiges Modul**. Liegt unter **Admin → Mandanten → Corporate Structure** (`tenant_management_corporate_structure`). Als Nicht-Admin sehe ich das gar nicht. Als Admin (ich bin's, gut) sehe ich Gruppen, Standalone-Tenants und einen Hinweis "Nur ein Mandant — Konzernstruktur erst relevant bei mehreren".

Das wirkt wie eine **Feature für Holding-Szenarien**, nicht wie Aufbauorganisation (Abteilungen, Teams). Für meine Firma mit einem Mandanten ist das irrelevant. Aber **wo trage ich meine interne Aufbauorg ein?** Die Rolle-Felder bei Assets und Risiken fragen nach "Owner" als Person — aber eine Abteilungshierarchie finde ich nirgends sauber abgebildet. Für 9001 hätte ich da ein Orgramm — hier: nur User + Personen-Modul. Der "Person"-Eintrag im Menü könnte das sein, aber ich bin nicht sicher.

**Frust-Score: 3/5.** Nicht weil das Modul schlecht ist, sondern weil ich den Ort "meine Firmenstruktur" nicht eindeutig finde.

**Notiz:** *"Corporate Structure = Konzern-Feature, nicht Aufbauorg. Verwirrend benannt für KMU."*

---

### 2.4 Assets

**Index:** KPI-Kacheln (Total, Kritisch, Ø CIA, mit Risiken), Filter (Typ, Klassifikation, Owner, Status), Tabelle mit Checkboxen für Bulk-Aktionen, Suchfeld. **Gut aufgeräumt.** Wenn leer → **schöner Empty-State** mit "Asset anlegen"-Button.

**Neu-Form:** Fünf Fieldsets: Basic Info (Name, Typ, Beschreibung), Assignment (Owner, Location), Financial (Acquisition-, Current-, Monetary-Value), CIA-Values (Confidentiality, Integrity, Availability als 1–5).

![Asset-Anlage-Form](sichtwechsel/img/implementer-junior/asset-new.png)

**Was mich verwirrt:**
- **Asset-Typ ist ein Freitext bzw. Dropdown, aber ich sehe keine Typ-Vorauswahl.** Ist "Laptop" ein eigener Typ? Oder "Hardware"? Oder "Endgerät"? In Verinice hieß das "Gerät-Client". Hier muss ich es mir selbst ausdenken — und später beim Filter sehe ich die Typ-Statistik, aber zu spät. Wünsche: **vorgegebene Typ-Liste** (Hardware, Software, Information, Service, Person, Facility) mit Beispielen.
- **CIA-Werte 1–5:** was heißt eine 3 bei Vertraulichkeit? Der Tooltip ist verknüpft (laut Template), aber als 9001-Kopf denke ich an "niedrig/mittel/hoch", nicht 1–5. Ich hätte lieber **verbale Labels** ("öffentlich / intern / vertraulich / streng geheim / Top-Secret") statt nackter Zahlen. Ein Info-Kasten "CIA-Hilfe" ist da, aber Inhalt sehe ich erst nach Klick.
- **Drei Finanzfelder** (Acquisition, Current, Monetary Value) — für einen Laptop ist "Anschaffungswert" klar, "current value" Abschreibung, aber "monetary value" ist **doppelt**? Ich lasse zwei Felder leer.
- **Owner = Person oder User?** Ich sehe ein Dropdown. Dort stehen meine User, aber eine "Abteilung" kann ich nicht auswählen. Wenn der Laptop der Finance-Abteilung gehört, muss ich eine konkrete Person angeben. Rollenwechsel? Muss ich das Asset dann umowen?

**9001-Analogie:** 9001 hat keine Assets. Das ist 27001-spezifisch. Hier greifen meine 9001-Instinkte nicht — ich denke in **Prozessen und Dokumenten**, nicht in "CMDB-Einträgen".

**Frust-Score: 3/5.** Form ist sauber, aber die Begriffe und Skalen sind unnötig mühsam für einen Einsteiger.

**Notiz:** *"CIA 1–5 ohne Textlabels = Raten. Asset-Typ ohne Vorgaben = chaotische Typ-Taxonomie. Drei Geldfelder = zwei zu viel."*

---

### 2.5 Lieferanten / Supplier

**Index:** KPIs (Total, Kritisch, Überfällige Assessments, Non-Compliant), zwei farbige Alert-Kästen für überfällige und non-compliant, dann Tabelle. Der DORA-Export-Button oben rechts irritiert mich ("DORA? was ist das?" — ich weiß was DORA ist, ich bin nicht blöd, aber hier fehlt mir der Kontext, warum der Button gerade hier hängt).

**Neu-Form:** Fünf Sektionen: Details (Name, Kritikalität, Status, Beschreibung, Service), Contact (Person, Mail, Phone, Adresse), Security Assessment (Score, Last/Next Assessment, Findings, Non-Conformities, Requirements), Certifications (hasISO27001, hasISO22301, hasDPA, dpaSignedDate, certifications), Contract (Start/End).

**Was mich verwirrt:**
- **Security Score:** Zahl? Prozent? Ich habe eine Tooltip-Referenz im Template (`supplier.tooltip.security_score`), aber was die Skala ist, sagt mir niemand im Form-Footer. Wenn ich "75" eintrage — gut oder schlecht?
- **hasDPA** (= Data Processing Agreement / AVV) ist als Checkbox da, aber ein QMB ohne DSGVO-Tiefe weiß erstmal nicht: *Ist das Pflicht? Was wenn wir keinen haben?*
- **Certifications** ist ein Freitextfeld. Ich würde ISO 27001, SOC 2, TISAX als Multi-Select erwarten.
- **4 Tabs** wurden laut Auftrag erwähnt — ich sehe aber nur Sektionen in einem langen Formular. Das ist okay, aber nicht wie ein Tab-Layout. Vielleicht meint der Auftrag das Show-View, das habe ich nicht im Detail geöffnet.

**9001-Analogie:** **Starke Analogie** — in 9001:2015 Kap. 8.4 ist "Lenkung extern bereitgestellter Prozesse" genau das. Ich kenne Lieferantenbewertung und Freigaben. Das hier ist einfach feingranularer.

**Frust-Score: 2/5.** Besser als Assets, weil die Analogie zu 9001 stark ist und die Felder selbsterklärender wirken. Score-Skala bleibt aber eine offene Frage.

**Notiz:** *"Security-Score ohne Skala = Zahl ohne Bedeutung. Certifications als Freitext = späteres Reporting-Elend."*

---

### 2.6 Risiken

**Index:** Viele KPI-Kacheln, Matrix-Vorschau, Filter, Exportbuttons (PDF, Excel, CSV). **Sehr reichhaltig, fast überwältigend.**

![Risiko-Anlage-Form](sichtwechsel/img/implementer-junior/risk-new.png)

**Neu-Form:** Hier wird es hart. **Sechs Sektionen:**
1. Basic Info: Title, **Asset (Dropdown)**, Description, Threat, Vulnerability, Category, Person, Location, Supplier
2. GDPR Assessment: involvesPersonalData, involvesSpecialCategoryData, legalBasis, processingScale, requiresDPIA, dataSubjectImpact
3. Inherent Risk: Probability, Impact (1–5)
4. Residual Risk: Residual-Probability, Residual-Impact
5. Treatment: Strategy, Risk-Owner, Description
6. Acceptance: ApprovedBy, ApprovedAt, Justification, Status, ReviewDate

**Plus** eine eingeklappte "Risikoanalyse-Hilfe" mit Skalen-Tabellen für Probability und Impact. **DAS** ist vorbildlich — sobald ich aufklappe, habe ich 1–5 mit verbalen Labels und Beschreibungen ("1 = sehr unwahrscheinlich, alle paar Jahre; 5 = wöchentlich"). **Das fehlt bei Assets komplett.**

**Was mich verwirrt:**
- **Asset-Dropdown ist Pflicht-artig.** Wenn ich keine Assets habe, ist das Dropdown leer. Der Neu-Form sagt mir nicht: "Lege erst Assets an". Ich klicke verzweifelt, bis mir dämmert: Ich muss zurück und Schritt 2.4 machen.
- **Threat vs. Vulnerability:** DAS ist der Klassiker. Als 9001-Mensch denke ich "Risiko = eine unerwünschte Möglichkeit". Hier will das Tool von mir Bedrohung UND Schwachstelle getrennt. Der Unterschied? "Feuer" = Bedrohung, "keine Brandmelder" = Schwachstelle. Ohne Erklärung (Tooltip für `category`, aber nicht für `threat`/`vulnerability`) ist das **die häufigste Einsteiger-Falle.**
- **Inherent vs. Residual:** Vier Zahlen (Pi/Ii, Pr/Ir) wollen ausgefüllt werden. Als Einsteiger fülle ich beide gleich aus, weil ich noch keine Maßnahmen habe → dann sieht das Tool so aus, als sei mein Risiko schon "behandelt", obwohl ich nichts getan habe. **Die Bedeutung ("inherent = ohne bestehende Maßnahmen, residual = nach bestehenden Maßnahmen")** muss ich raten.
- **GDPR-Sektion mitten im ISMS-Risiko-Form:** Für 95% meiner Risiken irrelevant. Wirkt wie aufgepfropft. Warum nicht ein Schalter "Betrifft personenbezogene Daten?" und dann die Felder aufklappen?
- **Treatment Strategy-Dropdown** (Akzeptieren/Vermeiden/Vermindern/Übertragen) — der Tooltip ist da, aber die Folgewirkungen (löst das einen Workflow aus? muss ich das separat freigeben?) sind nicht sichtbar.
- **Acceptance-Sektion** erscheint schon im Neu-Formular. Das verwirrt: *Soll ich jetzt gleich jemanden eintragen der das genehmigt hat, obwohl ich das Risiko gerade erst anlege?* Die Sektion müsste zumindest einen Hinweis tragen "Nur relevant wenn Behandlungsstrategie = akzeptiert".

**9001-Analogie:** **Mittel.** 9001 hat "risikobasiertes Denken" (Kap. 6.1), aber keine quantitative Matrix. Hier ist deutlich mehr Struktur als in 9001 — ich brauche eine Schulung dafür.

**Frust-Score: 4/5.** Die inline Risikoanalyse-Hilfe ist super, der Rest schwer. Entity-Abhängigkeit (Risiko braucht Asset) wird nicht kommuniziert. Threat/Vulnerability-Unterschied nicht erklärt. Acceptance-Felder zu früh sichtbar.

**Notiz:** *"Das ist die Sektion wo ich um 17:30 Uhr ratlos vor dem Bildschirm sitze."*

---

### 2.7 Schwachstellen / Vulnerabilities

**Index:** KPIs (Critical, High, Overdue, Total), Tabelle. Empty-State als einfacher Info-Alert "Noch keine Schwachstellen". **Keine Empty-State-Call-to-Action** wie bei Assets.

**Neu-Form:** Sechs Sektionen: Identification (CVE-ID, Titel, Beschreibung), Severity (severity-Dropdown, CVSS-Score, CVSS-Vector), Status (Source, Status, Affected Assets), Dates (discovered, published, remediation-deadline, remediated), Exploit (availability, activelyExploited), Remediation (responsible, notes).

**Was mich verwirrt:**
- **CVE-ID?** Ich weiß was ein CVE ist (als IT-Admin). Aber was, wenn meine Schwachstelle **kein CVE hat** — z.B. "Password-Policy zu kurz, 6 statt 12 Zeichen"? Das ist auch eine Schwachstelle. Kann ich CVE leer lassen? Vermutlich ja, aber nichts sagt es mir.
- **CVSS-Vector** ist ein Freitext. Wer das nicht aus dem Kopf kennt ("CVSS:3.1/AV:N/AC:L/…"), wird hier scheitern. Ein **Vector-Builder** (Dropdown pro Achse) wäre Gold wert.
- **Unterschied zu Risiko unklar:** Eine Schwachstelle "Password-Policy schwach" kann ich auch als Risiko anlegen. Wann ist's das eine, wann das andere? Aus 9001-Sicht: beides wäre bei mir eine "Nonconformity + CAPA". Hier: zwei Module, zwei Datenhaushalte. **Wann wird aus einer Schwachstelle automatisch ein Risiko?** Nie, ich muss selbst verknüpfen. Wird nicht im UI angeboten.

**9001-Analogie:** **Schwach.** Kenne ich nicht aus 9001. In IT-Security ist das Standard, aber ich muss mich einlesen.

**Frust-Score: 3/5.** Form sauber, aber CVSS ist für Nicht-Security-Profis ohne Builder unbrauchbar, und die Beziehung Schwachstelle↔Risiko ist unklar.

**Notiz:** *"CVSS-Vector im Freitext = 9 von 10 falsch eingetragen."*

---

### 2.8 Controls / Maßnahmen + SoA

**Index:** SoA heißt "Statement of Applicability" — für mich ein Fremdwort. Info oben ("soa.page.subtitle") soll das erklären, aber ohne 9001-Analogie (= wie Prozesslandkarte + Dokumentenmatrix) hätte ich Probleme, das zu übersetzen.

Die Kacheln zeigen: Total Controls / Applicable / Implemented / Compliance Rate %. Bei mir leerem Tenant sehe ich die 93 ISO-27001-Controls als Stammdaten — **super, das muss ich nicht selbst anlegen.** Pluspunkt.

**Kategorien-Überblick** mit Emojis (🏢 Organizational, 👥 People, 🏠 Physical, 💻 Technological) — niedlich und hilft beim Scannen. Positiv.

**Was mich verwirrt:**
- **Applicable vs. Not Applicable:** Was bedeutet das? Ein Control als "nicht anwendbar" zu markieren erfordert Begründung (bei 27001 Kap. 6.1.3). Kann ich das einfach abklicken? Wo ist der Justification-Feld? Das sehe ich nicht auf der Index-Seite.
- **Was IST eigentlich ein Control?** Die Bezeichnungen "A.5.23 Cloud-Services" sind Norm-Zitat. Als Einsteiger lese ich das und denke: "Ist das eine Anforderung, eine Regel, eine Maßnahme, ein Dokument?" **Alle drei, eigentlich.** Aber das sagt mir keiner.
- **Implementation Status:** implemented / partially / not / not applicable — das ist okay. Aber die Frage "Wie weise ich Implementierung nach?" (Evidence-Attachment?) ist nicht klar. Muss ich Dokumente hochladen? Verknüpfen?
- **Ich habe "controls" und "soa" getrennte Template-Folder gesucht** — `templates/controls/` existiert nicht, alles unter `templates/soa/`. Für mich als User: wo bearbeite ich einen einzelnen Control? Über die SoA-Kategorie-Seite? Okay, das muss ich ausprobieren.

**9001-Analogie:** **SoA = Prozesslandkarte + Anwendbarkeitserklärung.** Implementation Status = wie "Prozess dokumentiert/umgesetzt". Die Analogie hilft.

**Frust-Score: 3/5.** 93 Stammdaten-Controls sind großartig. "Applicable ja/nein + Begründung" nicht sofort sichtbar, Control-Bedeutung nicht erklärt.

**Notiz:** *"93 fertige Controls = Geschenk. Aber 'A.5.23' ohne Klartext nebenan = ich lese nur Code."*

---

### 2.9 Compliance-Frameworks

**Index (Compliance-Wizard):** Das ist der **schönste Teil des Tools.** Kacheln für jedes verfügbare Framework (ISO 27001, NIS2, DORA, DSGVO, TISAX …), mit Farbe, Icon, "Erforderliche Module"-Badges (grün wenn aktiv, gelb wenn fehlt). **So muss das.**

![Compliance-Wizard](sichtwechsel/img/compliance-manager/compliance-wizard.png)

**Was mich verwirrt:**
- **"Vergleichen"-Button** oben rechts → macht was? Framework-Gap-Vergleich, klingt toll, aber als Einsteiger verstehe ich nicht, wann ich das brauche.
- **Reihenfolge beim Aufbau:** Soll ich den Wizard **vor** oder **nach** dem Anlegen meiner Assets/Risiken starten? Die Landing-Seite sagt mir nichts.

**9001-Analogie:** 9001 kennt Framework-Mapping auch (z.B. IATF 16949 auf 9001 aufsetzen), aber nicht so visualisiert. Hier ist **mehr als bei 9001-Tools**.

**Frust-Score: 1/5.** Wirklich gut gemacht. Einziger Wunsch: "Empfohlener Einstieg: Starte hier, wenn du dein ISMS neu aufbaust".

**Notiz:** *"Das wäre eigentlich der Startpunkt für einen Neuling. Nur sieht das die Navi nicht so."*

---

### 2.10 Vorfälle / Incidents

**Index:** KPIs (Total, Open, Critical, Avg Resolution Days), Status-Kanban-Style mit 4 Status-Karten (open, in_progress, resolved, closed), Severity-Verteilung.

**Neu-Form:** Title, Category, Description, Severity, Status — plus eine **NIS2-Alert-Box** die erscheint wenn Severity = high/critical ("Meldepflichten nach NIS2: Early Warning 24h, Detailed 72h, Final 1 Monat"). **Starke Hilfe!** Das nimmt einen Neuling an die Hand.

Es gibt einen **"Incident-Escalation-Preview"**-Stimulus-Controller der live Warnungen anzeigt, wenn ich ein Schwere-Level wähle.

**Was mich verwirrt:**
- **Category:** Dropdown mit was? Muss ich raten (Malware, Phishing, Unauthorized-Access, …?).
- **Incident vs. Vulnerability vs. Risk:** Ein Ransomware-Befall ist ein Incident. Aber wo lege ich davor das Risiko "Ransomware" an, und die Schwachstelle "Kein EDR"? Drei Module, drei Stellen. Verknüpfung muss ich selbst ziehen.
- **Wenn ich den Incident auf "Data Breach" setze, startet ein GDPR-Wizard (`_gdpr_breach_wizard_modal`) — super, das ist Automatik.** Aber man muss wissen, dass man den Haken setzen muss.

**9001-Analogie:** 9001 kennt "Fehler" und CAPA. Der Incident-Workflow hier ist ähnlich — nur dass ich hier zusätzlich Melde-Fristen nach NIS2 tracken muss. Das NIS2-Banner hilft.

**Frust-Score: 2/5.** Starkes Feedback durchs NIS2-Banner. Schwächen: Kategorie-Vorauswahl unklar, Beziehung zu Risk/Vulnerability nicht sichtbar.

**Notiz:** *"NIS2-Banner ist Gold. Es zeigt: 'ab jetzt läuft deine Uhr'."*

---

### 2.11 Audits

**Index:** KPIs (Total, Upcoming, InProgress, Completed), Status-Karten. Action-Buttons: "Neues Audit", "Excel-Export".

**Was ich sehe ohne tief reinzugehen:** Audit-Entität hat wohl Plan/Progress/Completed/Reported-Lifecycle. Checklist-Funktionalität (`audit/checklist.html.twig`) — klingt nach Audit-Checklisten-Abhaken.

**9001-Analogie:** **Stärkste Analogie!** In 9001 bin ich interner Auditor — Planung, Durchführung, Bericht, Korrekturmaßnahmen. Das hier wird analog aussehen. Ich bin entspannt.

**Frust-Score: 1/5 (geschätzt, nicht tief geprüft).** Vermutlich vertraut.

**Notiz:** *"Hier bin ich zuhause — kenne ich aus 9001."*

---

### 2.12 Dokumente

**Index:** KPIs (Total, Total-Size-MB, PDFs, Images, Excel-Docs), Upload-Button. Standard-DMS-Gefühl.

**Was mich verwirrt:**
- **Dokument-Typen:** Policy, Procedure, Guideline, Record? Nicht sichtbar auf der Landing.
- **Versionierung?** 9001 verlangt Lenkung dokumentierter Information. Gibt es hier Versionsstände, Freigaben, Archivierung? Muss ich prüfen.
- **Verknüpfung Dokument → Control:** Wenn ich meine "Passwort-Richtlinie" als PDF hochlade, soll sie als Evidence für A.5.17 dienen. Wo mache ich diese Verknüpfung? Vermutlich im Document-Show-View. Muss ich suchen.

**9001-Analogie:** **Stark.** Dokumentenlenkung kenne ich.

**Frust-Score: 2/5.** Solide, aber Verknüpfung zu Controls ist der wichtige Punkt.

**Notiz:** *"Dokumente sind Pflicht, aber die Evidenz-Verknüpfung muss ich suchen."*

---

### 2.13 Schulungen

**Index:** Statistics-Cards (via Turbo-Frame lazy-geladen), Button "Neue Schulung". Wirkt wie ein Trainings-Tracking-Modul.

**Was mich verwirrt:**
- **Schulung = Schulungsprogramm oder einzelne Teilnahme?** Ist ein Eintrag ein Kurs oder ein Kurs-pro-Person?
- **Teilnehmer-Zuweisung:** Muss ich jeden User einzeln zuordnen oder Gruppen?
- **Pflichtschulungen** (z.B. jährliche DSGVO/Security-Awareness) — wo tracke ich Intervall und Mahnen?

**9001-Analogie:** **Stark.** Kap. 7.2 Kompetenz, Qualifikationsmatrix — ich mache das in 9001 auch.

**Frust-Score: 2/5.** Nicht geprüft, aber vermutlich okay.

**Notiz:** *"Vertraut. Hoffe es gibt Pflicht-Intervalle."*

---

### 2.14 BCM / Business-Continuity

**Index (Geschäftsprozesse):** KPIs (Total, Critical, Avg RTO, mit Risiken), Business-Process-Liste. Daneben noch separate Module BC-Pläne, Übungen, Krisenteam.

**Was mich verwirrt:**
- **RTO = Recovery Time Objective.** Abkürzung ohne Auflösung (außer in Tooltip vielleicht). Ich kenne das, aber ein Kollege vielleicht nicht.
- **"Business Process" als BCM-Entität**: warum liegt das unter BCM und nicht unter ISMS-Kern? Ein Geschäftsprozess ist für mich ein **Querschnittsthema** (auch für 9001-QM). Hier wird er unter Continuity einsortiert. Das ist konsequent, aber nicht intuitiv.
- **BIA (Business Impact Analysis)** ist ein eigener View (`bia.html.twig`). Stark! Aber Begriff ohne Einstieg.
- **Die BC-Module sind sehr feingranular** (Plan, Exercise, Crisis Team) — für einen Einsteiger klingt das nach "Overkill". In 9001 hätte ich einen "Notfall-Plan" als Dokument. Hier drei Entities.

**9001-Analogie:** **Mittel.** 9001 hat keine BCM-Klausel, aber die ISO 22301 ist bekannt. Wer das noch nie gemacht hat, wird ausgebremst.

**Frust-Score: 3/5.** Viel drin, aber Einstieg fehlt.

**Notiz:** *"BCM ist ein eigenes Universum. Ohne Einstiegs-Wizard verloren."*

---

## 3. End-to-End-Szenario: Neuer Laptop für CFO

**Chef:** "Marko, wir haben einen neuen Laptop für den CFO. Leg das mal sauber im ISMS ein."

**Meine Mental-Simulation:**

**Schritt 1 — Reflex:** Ich gehe sofort auf **Navi → Assets → Neu**. Titel: "Laptop CFO — ThinkPad X1 2026". Asset-Typ: Ich tippe "Laptop". Wird kein Dropdown, also Freitext. Beschreibung: "Dienstlaptop des Finanzvorstands, Vollverschlüsselung, Microsoft 365".

**Schritt 2 — Hänge beim Owner-Feld:** Dropdown will eine Person. Habe ich unseren CFO im System? Wahrscheinlich als User → ja. Wähle "Klaus Meier". Location: Dropdown leer. Habe ich Standorte gepflegt? Nein. **Erste Rückwärts-Aktion:** Ich klicke auf den Location-Link in der Navi, lege "HQ Frankfurt" an. Zurück zum Asset-Form — ah, die Daten waren noch da (kein Frust). Location gewählt.

**Schritt 3 — Finanzfelder:** Acquisition-Value 2.800 €. Current-Value = 2.800 € (ist neu). Monetary-Value lasse ich leer — **ich weiß nicht, was das heißen soll.**

**Schritt 4 — CIA-Werte:** Ein CFO-Laptop hat Finanzdaten → Vertraulichkeit hoch → C: 5? 4? Die Skala ist ohne Labels. Ich fülle C: 4, I: 4, A: 3 aus dem Bauch. **Unsicher, ob das die "richtigen" Werte sind. Kein Vergleich zu anderen Laptops, weil erster Eintrag.**

**Schritt 5 — Speichern:** Klappt. Yay! Asset-Nummer erhalten.

**Schritt 6 — Chef-Frage im Kopf: "Sauber eingelegt"?** Reicht das Asset allein? Ich überlege:

- **Risiko?** Dienstlaptop = Diebstahl, Verlust, Malware. Muss ich ein Risiko "Diebstahl des CFO-Laptops" anlegen? **Zweite Rückwärts-Aktion:** Risiko → Neu. Asset-Dropdown: "Laptop CFO" — **der ist da!** Erleichterung. Threat: "Diebstahl". Vulnerability: "öffentlicher Transport". Ich werde Ratlosigkeit bei Probability/Impact — ohne Kontext zu anderen Risiken 3/4? Keine Ahnung. Inherent-Werte fülle ich, Residual lasse ich erstmal gleich (weil ich keine Maßnahmen kenne). Treatment-Strategy: "Vermindern". Owner: CFO selbst? Oder IT-Leitung? Wähle mich. Speichern.

- **Controls verknüpfen?** Ich gehe zu SoA → A.5.23 (Cloud), A.6.7 (Remote Working), A.8.1 (User Endpoint Devices)? Ohne Suche/Filter finde ich die Codes nicht. Ich gebe auf.

- **Dokumente?** Anschaffungsbeleg, Gerätesteckbrief — hochladen? Ich finde keinen direkten "Dokument zu Asset verknüpfen"-Flow im Asset-Form. Muss ich über das Dokumente-Modul und dann verknüpfen? Gebe auf.

**Schritt 7 — Fertiger Zustand aus meiner Sicht:**
- Asset angelegt ✓
- Location angelegt ✓
- Ein Risiko grob verknüpft ✓
- Controls-Verknüpfung **nicht fertig**
- Dokumente **nicht angehängt**
- Schulungsnachweis "Mobile Device Policy" **nicht geprüft**

Ich sage dem Chef: "Asset ist drin." Der Chef denkt ich bin fertig. In Wahrheit habe ich 40% der ISMS-Hygiene geleistet.

**Frust-Score End-to-End: 4/5.** Die Rückwärts-Actions (Location nicht vorhanden) sind der Hauptkiller. Und dass ich Risiko/Control/Dokument **selbst** ziehen muss statt dass das Tool mich durchführt.

---

## 4. Top 10 Sofort-Findings

| # | Modul | Was mich verwirrte | Was ich mir gewünscht hätte | Aufwand |
|---|-------|--------------------|----------------------------|---------|
| 1 | Risiko/Neu | Threat vs. Vulnerability ohne Erklärung | Beispiele direkt am Feld ("z.B. Feuer / z.B. fehlende Brandmelder") | S |
| 2 | Risiko/Neu | Asset-Dropdown leer wenn keine Assets — keine Warnung | Banner "Bitte erst Assets anlegen — [Link]" bei leerem Dropdown | S |
| 3 | Asset/Neu | CIA-Werte 1–5 ohne verbale Labels | Labels neben Zahl: "4 = hoch / vertraulich / stark eingeschränkt" | XS |
| 4 | Onboarding | Kein geführter Pfad für Neuling | 5-Schritt-Wizard "Mein erstes ISMS: Kontext → Asset → Risiko → Control → Dokument" | M |
| 5 | Kontext | Freitext "Interessierte Parteien" parallel zum strukturierten Modul | Eines weg, oder Freitext autogeneriert aus Modul | S |
| 6 | Controls/SoA | "A.5.23" ohne Kurzerklärung | Klartextlabel direkt hinter Code, Tooltip mit Normzitat | S |
| 7 | Risiko/Neu | Acceptance-Sektion sichtbar bevor Treatment = "akzeptieren" | Sektion ausgrauen/aufklappen nach Strategy-Wahl | S |
| 8 | Incident/Risk/Vuln | Keine klare Verknüpfung zwischen den drei | "Aus Vulnerability ein Risiko ableiten"-Button / Link-Matrix | M |
| 9 | Asset/Neu | "monetaryValue" zusätzlich zu Acquisition+Current | Feld entfernen oder Formel/Hilfe zeigen wie es berechnet wird | XS |
| 10 | Vulnerability/Neu | CVSS-Vector als Freitext | Vector-Builder (Dropdowns pro Achse) oder "CVSS Score nur"-Modus | M |

**Bonus-Finding ohne Platz in Top 10:** Ein "Was soll ich jetzt tun?"-Hinweis pro Empty-State. Das Context-Modul hat das schon vorbildlich, andere Module nicht.

---

## 5. Was ich alleine hinbekomme — wo ich Hilfe brauche

**Was ich alleine hinbekomme:**

1. **Kontext / Interessierte Parteien / Dokumente / Audits / Schulungen pflegen.** Diese Module liegen nahe an 9001, die Begriffe sind mir vertraut, die Info-Boxen sind gut (Kontext) oder die Analogie trägt (Audits/Training/Docs).
2. **Assets anlegen** — mit leichter Unsicherheit bei CIA und Typ, aber operativ machbar. Nach ein paar Wochen und 20 Assets hab ich ein Gefühl für die Skala.
3. **Vorfälle eintragen und Status pflegen.** Der NIS2-Banner hilft, und der Lifecycle (open → in_progress → resolved → closed) ist aus QM-Beschwerdemanagement vertraut.

**Wo ich definitiv Hilfe brauche:**

1. **Risiken bewerten und behandeln.** Inherent/Residual, Threat/Vulnerability-Abgrenzung, Treatment-Strategy-Entscheidungen, Akzeptanzbegründungen — da kippe ich ohne einen ISB oder Berater. Die Skala-Hilfe im Formular ist ein guter Anker, aber ich brauche eine Schulung + erste 5–10 Risiken **mit** jemandem zusammen.
2. **SoA füllen und "nicht anwendbar" begründen.** Für jedes der 93 Controls entscheiden "gilt bei uns / nicht" — Stundenfresser ohne Anleitung. Ich brauche den Compliance-Wizard mit Beispiel-Begründungen, oder einen Berater der durchgeht.
3. **DSGVO/NIS2/DORA-Mapping.** Alles was über 27001 hinausgeht (Data Breach 72h, NIS2 Early-Warning, DPIA-Auslösekriterien) ist mir fremd. Die Banner helfen operativ, aber strategisch brauche ich einen DPO/Anwalt beim Onboarding.

---

**Würde ich das Tool ohne Schulung produktiv nutzen?** Mit Einweisung — denn die Module sind einzeln benutzbar und die 9001-Nähe trägt weit, aber ohne eine halbtägige Crashkurs-Session zu Risiko-Denkweise, SoA-Logik und den Verknüpfungen zwischen Asset↔Risiko↔Control↔Incident stochere ich drei Monate im Nebel und produziere audit-untaugliche Halbwissen-Daten.
