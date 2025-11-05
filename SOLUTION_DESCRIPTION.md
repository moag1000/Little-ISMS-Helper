# Little ISMS Helper - Lösungsbeschreibung

## 1. Problemstellung und Lösungsansatz

### Welche Probleme löst die Software?

Kleine und mittelständische Unternehmen stehen vor mehreren Herausforderungen bei der Implementierung eines Informationssicherheitsmanagementsystems (ISMS):

**1. Mehrfacherfassung von Daten**
- Dieselben Informationen müssen für verschiedene Compliance-Anforderungen mehrfach dokumentiert werden
- Risikodaten, Asset-Informationen und Business-Continuity-Daten werden isoliert in verschiedenen Dokumenten gepflegt
- Bei Änderungen müssen mehrere Dokumente manuell aktualisiert werden

**2. Fehlende Verknüpfungen**
- Zusammenhänge zwischen Risiken, Vorfällen und Controls sind nicht nachvollziehbar
- Audit-Ergebnisse fließen nicht systematisch in das Risikomanagement ein
- Die Wirksamkeit implementierter Maßnahmen lässt sich schwer überprüfen

**3. Manuelle Compliance-Nachweise**
- Für verschiedene Frameworks (ISO 27001, TISAX, DORA) werden separate Dokumentationen geführt
- Überschneidungen zwischen verschiedenen Anforderungen werden nicht erkannt
- Der Nachweis der Compliance erfordert manuelle Zusammenstellung von Dokumenten

**4. Fehlende Transparenz**
- Der aktuelle Stand der ISMS-Implementierung ist nicht auf einen Blick erkennbar
- Offene Maßnahmen, Risiken und Vorfälle werden in verschiedenen Listen geführt
- Management-Reviews erfordern zeitaufwändige manuelle Datensammlung

### Lösungsansatz

Die Software implementiert ein **datenzentrisches ISMS-Managementsystem**, bei dem Informationen einmal erfasst und über Module hinweg wiederverwendet werden. Durch systematische Verknüpfungen zwischen Assets, Risiken, Controls, Vorfällen und Compliance-Anforderungen entsteht eine konsistente Datenbasis, die mehrfache Verwendung findet.

---

## 2. Nutzen und Vorteile

### Was ist der Benefit die Lösung einzusetzen?

**Reduzierung von Doppelarbeit**
- Einmal erfasste Daten werden automatisch für verschiedene Zwecke wiederverwendet
- Business-Impact-Analysen (BIA) fließen direkt in Asset-Schutzanforderungen ein
- Control-Implementierungsstatus wird automatisch für Compliance-Nachweise genutzt

**Konsistente Datenbasis**
- Alle Module greifen auf dieselben Stammdaten zu (Assets, Controls, Prozesse)
- Änderungen wirken sich automatisch auf alle abhängigen Berechnungen aus
- Widersprüche zwischen verschiedenen Dokumenten werden vermieden

**Nachvollziehbarkeit für Audits**
- Zusammenhänge zwischen Anforderungen, Controls und Nachweisen sind dokumentiert
- Compliance-Status lässt sich jederzeit abrufen
- Audit-Checklisten können direkt aus dem System generiert werden

**Datengetriebene Insights**
- Automatische Berechnung von Restrisiken basierend auf Control-Implementierung
- Validierung von Risikobewertungen durch tatsächlich eingetretene Vorfälle
- Erkennung von Mustern in der Vorfallshistorie

**Unterstützung mehrerer Frameworks**
- Cross-Framework-Mappings zeigen, welche ISO 27001-Controls auch TISAX- oder DORA-Anforderungen erfüllen
- Transitive Compliance-Berechnungen: Erfüllung einer Anforderung trägt automatisch zu anderen bei
- Gap-Analysen identifizieren fehlende Nachweise framework-übergreifend

---

## 3. Module und Funktionen

### Welche Module gibt es und was tun diese?

#### **3.1 Asset Management**

**Zweck:** Verwaltung von IT-Assets und Informationswerten

**Funktionalität:**
- Erfassung von Assets mit CIA-Bewertung (Confidentiality, Integrity, Availability)
- Asset-Typen, Eigentümer und Standorte
- Verknüpfung mit Risiken, Controls und Geschäftsprozessen
- Automatische Berechnung von Risiko-Scores basierend auf verknüpften Risiken und Vorfällen

**Datenwiederverwendung:**
- Asset-CIA-Werte werden aus Business-Continuity-Daten (RTO/RPO) abgeleitet
- Verfügbarkeitsanforderungen ergeben sich aus der Kritikalität unterstützter Geschäftsprozesse

#### **3.2 Risk Assessment & Treatment**

**Zweck:** Strukturiertes Risikomanagement nach ISO 27001

**Funktionalität:**
- Risikoidentifikation mit Bedrohungen und Schwachstellen
- Risikobewertung nach Wahrscheinlichkeit × Auswirkung
- Risikobehandlungsstrategien (Accept, Mitigate, Transfer, Avoid)
- Berechnung von Restrisiken nach Control-Implementierung

**Datenwiederverwendung:**
- Vorfallshistorie validiert Risikobewertungen (wurden Risiken tatsächlich realisiert?)
- Implementierte Controls reduzieren Restrisiken automatisch
- Aus Vorfällen werden neue Risiken vorgeschlagen (Threat Intelligence)

#### **3.3 Statement of Applicability (SoA)**

**Zweck:** Verwaltung der Anwendbarkeit von ISO 27001 Annex A Controls

**Funktionalität:**
- Verwaltung aller 93 Controls aus ISO 27001:2022 Annex A
- Festlegung der Anwendbarkeit mit Begründung
- Implementierungsstatus und -fortschritt (0-100%)
- Verantwortlichkeiten und Zieldaten

**Datenwiederverwendung:**
- Control-Implementierungsstatus fließt in Restrisiko-Berechnung ein
- Implementierte Controls werden für Compliance-Nachweise mehrerer Frameworks verwendet
- Control-Wirksamkeit wird durch Vorfallsanalyse validiert

#### **3.4 Incident Management**

**Zweck:** Strukturierte Behandlung von Sicherheitsvorfällen

**Funktionalität:**
- Vorfallsdokumentation mit Kategorisierung und Schweregrad
- Zeitstempel für Erkennung, Eindämmung und Lösung
- Root Cause Analysis und Lessons Learned
- Korrektur- und Präventivmaßnahmen
- Data Breach Tracking für DSGVO-Meldepflichten

**Datenwiederverwendung:**
- Vorfälle werden mit Assets, Risiken und Controls verknüpft
- Vorfallsmuster fließen in Risikobewertungen ein (Wahrscheinlichkeit und Impact)
- Erfolgreiche Incident-Response-Maßnahmen werden als Control-Empfehlungen vorgeschlagen

#### **3.5 Business Continuity Management (BCM)**

**Zweck:** Business Impact Analysis und Kontinuitätsplanung

**Funktionalität:**
- Erfassung von Geschäftsprozessen mit Kritikalitätsbewertung
- Recovery Time Objective (RTO), Recovery Point Objective (RPO), MTPD
- Finanzielle, reputative, regulatorische und operationelle Impact-Bewertung
- Verknüpfung mit unterstützenden IT-Assets

**Datenwiederverwendung:**
- RTO/RPO-Werte definieren automatisch Verfügbarkeitsanforderungen für Assets
- Kritikalität von Prozessen fließt in Asset-Schutzanforderungen ein
- BCM-Daten erfüllen DORA-Anforderungen zur operationellen Resilienz

#### **3.6 Internal Audit Management**

**Zweck:** Planung und Durchführung interner ISMS-Audits

**Funktionalität:**
- Flexible Audit-Geltungsbereiche (gesamtes ISMS, spezifische Frameworks, Assets, Standorte, Abteilungen)
- Audit-Team und Zeitplanung
- Audit-Checklisten mit Verknüpfung zu Compliance-Anforderungen
- Findings, Nichtkonformitäten und Empfehlungen

**Datenwiederverwendung:**
- Audit-Checklisten werden aus Compliance-Anforderungen generiert
- Audit-Ergebnisse fließen in Compliance-Fulfillment-Berechnungen ein
- Nichtkonformitäten können automatisch Risiko-Reviews auslösen

#### **3.7 Management Review**

**Zweck:** Managementbewertung des ISMS nach ISO 27001 Clause 9.3

**Funktionalität:**
- Strukturierte Review-Dokumentation
- Bewertung der ISMS-Performance
- Entscheidungen und daraus resultierende Maßnahmen
- Follow-up vorheriger Reviews

**Datenwiederverwendung:**
- KPIs aus anderen Modulen (Vorfälle, Risiken, Compliance-Status) fließen automatisch ein
- Audit-Ergebnisse werden berücksichtigt
- Offene Maßnahmen aus vorherigen Reviews werden getrackt

#### **3.8 Training & Awareness**

**Zweck:** Schulungsmanagement für ISMS-relevante Themen

**Funktionalität:**
- Schulungsplanung und -durchführung
- Teilnehmerverwaltung
- Feedback-Erfassung
- Verknüpfung mit behandelten Controls

**Datenwiederverwendung:**
- Training-Abdeckung wird für Controls nachgewiesen
- Schulungseffektivität kann mit Control-Implementierungsgrad korreliert werden
- Kritische Controls mit niedriger Schulungsabdeckung werden identifiziert

#### **3.9 ISMS Context & Objectives**

**Zweck:** Organisationskontext und strategische ISMS-Ziele

**Funktionalität:**
- Definition des ISMS-Geltungsbereichs
- Verwaltung interessierter Parteien
- Dokumentation gesetzlicher und regulatorischer Anforderungen
- ISMS-Ziele mit messbaren Indikatoren und Fortschrittstracking

**Datenwiederverwendung:**
- Gesetzliche Anforderungen werden mit Compliance-Frameworks verknüpft
- ISMS-Ziele können an KPIs aus anderen Modulen gekoppelt werden

#### **3.10 Multi-Framework Compliance Management**

**Zweck:** Parallele Verwaltung mehrerer Compliance-Frameworks

**Unterstützte Frameworks:**
- ISO 27001:2022
- TISAX (VDA ISA) für die Automobilindustrie
- EU-DORA für Finanzdienstleister

**Funktionalität:**
- Hierarchische Compliance-Anforderungen (Hauptanforderungen mit Detail-Anforderungen)
- Cross-Framework-Mappings zeigen Überschneidungen
- Mapping-Typen: Schwach (<25%), Teilweise (25-75%), Vollständig (75-99%), Übererfüllt (≥100%)
- Transitive Compliance-Berechnung: Erfüllung einer Anforderung trägt zu anderen bei

**Datenwiederverwendung:**
- ISO 27001-Controls werden auf TISAX- und DORA-Anforderungen gemappt
- Compliance-Fulfillment wird aus folgenden Quellen berechnet:
  - Control-Implementierungsstatus (gemappte Controls)
  - Asset-Inventar (für Asset-Management-Anforderungen)
  - BCM-Daten (für Resilienz-Anforderungen)
  - Incident-Management-Nachweise
  - Audit-Ergebnisse
- Gap-Analysen identifizieren fehlende Nachweise framework-übergreifend

#### **3.11 KPI Dashboard**

**Zweck:** Echtzeit-Überblick über den ISMS-Status

**Kennzahlen:**
- Anzahl verwalteter Assets
- Risiko-Übersicht (nach Behandlungsstatus)
- Offene Vorfälle
- Compliance-Status (implementierte Controls)
- Data Reuse Value: Geschätzte eingesparte Arbeitsstunden durch Datenwiederverwendung

---

## 4. Effizienzsteigerungen

### Welche Effizienzsteigerungen bietet das Tool gegenüber manueller Erfassung?

#### **4.1 Reduzierung von Dokumentationsaufwand**

**Business-Continuity-Daten**
- Manuelle Erfassung: RTO/RPO-Daten werden in separaten BCM-Dokumenten erfasst. Anschließend müssen daraus Asset-Schutzanforderungen manuell abgeleitet und in Asset-Listen übertragen werden.
- Mit Tool: RTO/RPO-Daten werden einmal erfasst. Die Software schlägt automatisch passende Verfügbarkeitsanforderungen für Assets vor (z.B. RTO ≤ 1h → Availability Level 5).
- Zeitersparnis: Geschätzt 2-3 Stunden pro Geschäftsprozess bei initialer Erfassung, 1 Stunde pro Änderung

**Compliance-Nachweise**
- Manuelle Erfassung: Für jedes Framework (ISO 27001, TISAX, DORA) wird separat dokumentiert, welche Anforderungen durch welche Maßnahmen erfüllt werden.
- Mit Tool: Control-Implementierung wird einmal erfasst. Cross-Framework-Mappings zeigen automatisch, welche anderen Anforderungen dadurch ebenfalls erfüllt werden.
- Zeitersparnis: Geschätzt 4 Stunden pro Compliance-Anforderung beim Nachweis für mehrere Frameworks

**Risikobewertungen**
- Manuelle Erfassung: Wahrscheinlichkeit und Auswirkung werden geschätzt. Bei eingetretenen Vorfällen muss manuell abgeglichen werden, ob die Risikobewertung korrekt war.
- Mit Tool: Vorfälle werden mit Risiken verknüpft. Das System zeigt automatisch, ob Risikobewertungen durch tatsächliche Ereignisse validiert wurden.
- Zeitersparnis: Geschätzt 1-2 Stunden pro Risiko bei Review-Zyklen

#### **4.2 Vermeidung von Inkonsistenzen**

**Szenario: Änderung eines Control-Status**
- Manuelle Erfassung: Control-Status muss in mehreren Dokumenten aktualisiert werden (SoA, Risiko-Behandlungsplan, Compliance-Nachweisdokumente).
- Mit Tool: Control-Status wird einmal geändert. Alle abhängigen Berechnungen (Restrisiken, Compliance-Fulfillment) werden automatisch aktualisiert.
- Zeitersparnis: Geschätzt 30-60 Minuten pro Control-Änderung

**Szenario: Neuer Sicherheitsvorfall**
- Manuelle Erfassung: Vorfall wird dokumentiert. Anschließend muss manuell geprüft werden, welche Risikobewertungen angepasst werden sollten und welche Assets betroffen sind.
- Mit Tool: Vorfall wird mit betroffenen Assets und realisierten Risiken verknüpft. Das System schlägt automatisch Risikobewertungs-Updates vor und berechnet den Gesamt-Impact basierend auf Asset-CIA-Werten.
- Zeitersparnis: Geschätzt 1-2 Stunden pro Vorfall bei der Nachbearbeitung

#### **4.3 Beschleunigte Audit-Vorbereitung**

**Audit-Checklisten**
- Manuelle Erfassung: Audit-Checklisten müssen manuell aus Compliance-Anforderungen erstellt werden. Nachweise müssen aus verschiedenen Dokumenten zusammengestellt werden.
- Mit Tool: Audit-Checklisten werden aus Compliance-Anforderungen generiert. Für jede Anforderung werden automatisch verknüpfte Controls, Assets und Nachweise angezeigt.
- Zeitersparnis: Geschätzt 4-8 Stunden pro Audit-Vorbereitung

**Management-Reviews**
- Manuelle Erfassung: KPIs müssen aus verschiedenen Quellen manuell zusammengetragen werden (Excel-Listen, Dokumente).
- Mit Tool: KPIs werden automatisch aus den Moduldaten berechnet und im Dashboard angezeigt.
- Zeitersparnis: Geschätzt 2-4 Stunden pro Management-Review

#### **4.4 Gap-Analysen**

**Multi-Framework-Gap-Analysen**
- Manuelle Erfassung: Für jedes Framework muss manuell ermittelt werden, welche Anforderungen noch nicht erfüllt sind und welche Nachweise fehlen.
- Mit Tool: Das System berechnet automatisch den Compliance-Status für alle Frameworks und zeigt priorisierte Lücken mit konkreten Handlungsempfehlungen.
- Zeitersparnis: Geschätzt 8-16 Stunden pro Framework bei initialer Gap-Analyse, 2-4 Stunden bei Updates

#### **4.5 Quantifizierung der Gesamteffizienzsteigerung**

Die Software berechnet selbst einen "Data Reuse Value", der geschätzte eingesparte Arbeitsstunden durch Datenwiederverwendung anzeigt. Die Berechnung basiert auf:

- **4 Stunden pro wiederverwendetem Control-Nachweis** (z.B. ISO 27001 Control erfüllt auch TISAX-Anforderung)
- **2 Stunden pro wiederverwendeter Asset-Information** (z.B. Asset-CIA-Wert erfüllt Compliance-Anforderung)
- **3 Stunden pro wiederverwendeter BCM-Analyse** (z.B. BIA-Daten erfüllen Resilienz-Anforderung)
- **2 Stunden pro wiederverwendetem Incident-Nachweis**
- **3 Stunden pro wiederverwendetem Audit-Ergebnis**

**Beispielrechnung für ein mittelständisches Unternehmen:**

Bei typischer Nutzung mit:
- 50 Assets
- 93 ISO 27001 Controls
- 100 TISAX-Anforderungen
- 50 DORA-Anforderungen
- 30 Geschäftsprozesse (BCM)
- 20 Vorfälle pro Jahr
- 2 interne Audits pro Jahr

Geschätzte Zeitersparnis:
- Control-Mappings: 100 TISAX + 50 DORA × 4h = 600 Stunden
- BCM → Asset-Protection: 30 Prozesse × 2h = 60 Stunden
- Incident → Risk-Updates: 20 Vorfälle × 1,5h = 30 Stunden
- Audit-Vorbereitung: 2 Audits × 6h = 12 Stunden
- Management-Reviews: 4 Reviews × 3h = 12 Stunden

**Gesamtersparnis im ersten Jahr: ca. 700-750 Arbeitsstunden**

Nach dem ersten Jahr (laufender Betrieb):
- Jährliche Ersparnis durch vermiedene Doppelerfassung: ca. 150-200 Stunden
- Ersparnis bei Audit-Zyklen: ca. 50 Stunden
- Ersparnis bei Management-Reviews: ca. 12 Stunden

**Jährliche Ersparnis im laufenden Betrieb: ca. 200-250 Arbeitsstunden**

#### **4.6 Qualitative Vorteile**

Neben der quantifizierbaren Zeitersparnis bietet das Tool folgende qualitative Verbesserungen:

- **Aktualität**: Echtzeit-Überblick über ISMS-Status statt veralteter Excel-Listen
- **Nachvollziehbarkeit**: Zusammenhänge zwischen Anforderungen und Nachweisen sind dokumentiert
- **Risikovalidierung**: Risikobewertungen werden durch tatsächliche Vorfälle validiert
- **Konsistenz**: Inkonsistenzen zwischen verschiedenen Dokumenten werden vermieden
- **Audit-Sicherheit**: Vollständige Audit-Trails durch Verknüpfungen in der Datenbank

---

## Zusammenfassung

Der Little ISMS Helper adressiert die Herausforderung der Mehrfacherfassung und isolierten Datenhaltung bei ISMS-Dokumentation. Durch systematische Verknüpfungen zwischen Modulen und intelligente Datenwiederverwendung reduziert die Software den Dokumentationsaufwand erheblich, ohne dabei Kompromisse bei der Vollständigkeit der Dokumentation einzugehen.

Die Lösung ist primär für kleine und mittelständische Unternehmen konzipiert, die mehrere Compliance-Frameworks parallel erfüllen müssen (z.B. ISO 27001 + TISAX, oder ISO 27001 + DORA) und dabei von der automatischen Wiederverwendung von Nachweisen profitieren möchten.
