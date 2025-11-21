# BDSG-Referenz (Bundesdatenschutzgesetz)

**Kompakte Referenz für den DPO Specialist Agent**

## Überblick

Das **Bundesdatenschutzgesetz (BDSG)** ist das deutsche Datenschutzgesetz, das die DSGVO auf nationaler Ebene ergänzt und konkretisiert. Es nutzt die sogenannten **Öffnungsklauseln** der DSGVO, um in bestimmten Bereichen spezifischere oder abweichende Regelungen zu treffen.

**Aktuelle Version**: BDSG (neu) - in Kraft seit 25. Mai 2018 (parallel zur DSGVO)

**Struktur**: 4 Teile mit 85 Paragraphen

---

## Teil 1: Gemeinsame Bestimmungen (§§ 1-21)

### § 1 BDSG - Anwendungsbereich

**Zweck**: Regelung der Verarbeitung personenbezogener Daten durch:
1. Öffentliche Stellen des Bundes
2. Öffentliche Stellen der Länder (soweit nicht eigene Datenschutzgesetze greifen)
3. Nicht-öffentliche Stellen (Unternehmen, Vereine)

**Wichtig**: Das BDSG gilt **zusätzlich** zur DSGVO, nicht anstelle!

### § 22 BDSG - Verarbeitung besonderer Kategorien personenbezogener Daten

**Öffnungsklausel zu**: Art. 9 Abs. 2 lit. b DSGVO (besondere Kategorien im Beschäftigungskontext)

**Erlaubt**: Verarbeitung besonderer Kategorien (z.B. Gesundheitsdaten), wenn:
1. **Erforderlich** für die Ausübung von Rechten oder Pflichten aus dem Arbeitsrecht
2. **Keine Anhaltspunkte** für überwiegendes Interesse der betroffenen Person am Ausschluss

**Beispiele**:
- Schwerbehindertenausweis (§ 164 SGB IX)
- Krankmeldungen
- Betriebsärztliche Untersuchungen
- Mutterschutz

**Best Practice**:
```php
// ProcessingActivity mit § 22 BDSG als Rechtsgrundlage
$activity = new ProcessingActivity();
$activity->setName('Verarbeitung Krankmeldungen');
$activity->setLegalBasis('Art. 9 Abs. 2 lit. b DSGVO i.V.m. § 22 BDSG');
$activity->setPersonalDataCategories(['Gesundheitsdaten']);
$activity->setPurposes(['Lohnfortzahlung', 'Fehlzeitenverwaltung']);
```

### § 23 BDSG - Verarbeitung zu anderen Zwecken durch öffentliche Stellen

**Öffnungsklausel zu**: Art. 6 Abs. 4 DSGVO (Zweckänderung)

**Regelt**: Wann öffentliche Stellen Daten für andere Zwecke nutzen dürfen als ursprünglich erhoben.

**Für nicht-öffentliche Stellen**: Gilt die Zweckbindung nach Art. 6 Abs. 4 DSGVO strenger!

### § 24 BDSG - Verarbeitung zu im öffentlichen Interesse liegenden Archivzwecken

**Öffnungsklausel zu**: Art. 89 Abs. 3 DSGVO (Archivierung)

**Erlaubt**: Einschränkungen der Betroffenenrechte (Art. 15, 16, 18, 21 DSGVO) bei:
- Archivierung im öffentlichen Interesse
- Wissenschaftlichen/historischen Forschungszwecken
- Statistischen Zwecken

**Bedingungen**: Geeignete Garantien (Anonymisierung, Zugangskontrollen)

### § 25 BDSG - Verarbeitung für wissenschaftliche oder historische Forschungszwecke

**Öffnungsklausel zu**: Art. 89 DSGVO (Forschung)

**Erlaubt**: Verarbeitung ohne Einwilligung für Forschungszwecke, wenn:
1. **Öffentliches Interesse** überwiegt
2. **Zweck nicht anders erreichbar**
3. **Daten anonymisiert**, soweit möglich

**Best Practice in App**: ProcessingActivity mit Zweck "Wissenschaftliche Forschung" + Controls für Anonymisierung

### § 26 BDSG - Datenverarbeitung für Zwecke des Beschäftigungsverhältnisses

**WICHTIGSTE VORSCHRIFT für HR/Personalwesen!**

**Öffnungsklausel zu**: Art. 6 Abs. 1 lit. b DSGVO und Art. 88 DSGVO (Beschäftigtendaten)

#### § 26 Abs. 1 BDSG - Erforderlichkeit

**Erlaubt**: Verarbeitung von Beschäftigtendaten, wenn:
1. **Erforderlich** für die **Begründung** des Beschäftigungsverhältnisses (Bewerberdaten)
2. **Erforderlich** für die **Durchführung** des Beschäftigungsverhältnisses (Lohn, Arbeitszeit)
3. **Erforderlich** für die **Beendigung** des Beschäftigungsverhältnisses (Zeugnis)
4. Zur Ausübung oder Erfüllung der Rechte und Pflichten der Interessenvertretung

**"Erforderlich"**: Nicht "nützlich" oder "wünschenswert", sondern objektiv notwendig!

**Beispiele erlaubt**:
- Name, Adresse, Geburtsdatum (Vertragserfüllung)
- Bankverbindung (Lohnzahlung)
- Qualifikationen (Eignung, Beförderung)
- Arbeitszeitnachweise (Lohnabrechnung)
- Krankheitstage (Lohnfortzahlung)

**Beispiele NICHT erlaubt** (ohne Einwilligung):
- Private Social-Media-Profile
- Gesundheitsdaten ohne Bezug zum Arbeitsverhältnis
- Mitarbeiterfotos für Werbezwecke
- Videoüberwachung von Arbeitsplätzen ohne konkreten Anlass

#### § 26 Abs. 2 BDSG - Einwilligung

**Besonderheit**: Einwilligung im Beschäftigungsverhältnis ist **besonders kritisch** wegen Abhängigkeit!

**Zulässig nur**, wenn:
1. **Freiwilligkeit** gewährleistet (schwierig bei Abhängigkeitsverhältnis!)
2. **Rechtlicher Vorteil** für Beschäftigten oder
3. **Gleichgelagertes Interesse** von Arbeitgeber und Beschäftigtem

**Rechtsfolge bei Unwirksamkeit**: Einwilligung gilt als nicht erteilt!

**Best Practice in App**:
```php
// ProcessingActivity für Beschäftigtendaten
$activity = new ProcessingActivity();
$activity->setName('Personalverwaltung');
$activity->setLegalBasis('Art. 6 Abs. 1 lit. b DSGVO i.V.m. § 26 Abs. 1 BDSG');
$activity->setDataSubjectCategories(['Beschäftigte']);
$activity->setPersonalDataCategories([
    'Stammdaten',
    'Vertragsdaten',
    'Arbeitszeitdaten',
    'Entgeltdaten'
]);
$activity->setPurposes(['Vertragsdurchführung', 'Lohnabrechnung']);
```

#### § 26 Abs. 3 BDSG - Kollektivvereinbarungen

**Erlaubt**: Betriebsvereinbarungen und Tarifverträge als Rechtsgrundlage für Datenverarbeitung.

**Beispiele**: Regelungen zur Arbeitszeiterfassung, Zutrittskontrolle, Videoüberwachung

#### § 26 Abs. 8 BDSG - Scoring

**Verbot**: Vollautomatisierte Einzelfallentscheidungen bei:
- Begründung eines Beschäftigungsverhältnisses
- Durchführung eines Beschäftigungsverhältnisses
- Beendigung eines Beschäftigungsverhältnisses

**Ausnahme**: Kollektivvereinbarung (Betriebsvereinbarung) mit geeigneten Garantien

**Konsequenz**: KI-basierte Bewerbermanagement-Systeme müssen **immer** menschliche Entscheidung beinhalten!

**Verweis auf ISO 27701:2025**: Clause 6.4 (AI Controls) - Human-in-the-loop mandatory!

---

## Teil 2: Durchführungsbestimmungen für Verarbeitungen zu bestimmten Zwecken (§§ 27-37)

### § 27 BDSG - Datenverarbeitung zu wissenschaftlichen oder historischen Forschungszwecken

**Erlaubt**: Erhebung, Verarbeitung und Nutzung zu Forschungszwecken ohne Einwilligung, wenn:
1. **Öffentliches Interesse** überwiegt
2. **Forschungszweck nicht anders erreichbar**
3. **Interessen des Verantwortlichen** überwiegen

**Garantien**: Anonymisierung, technische und organisatorische Maßnahmen

### § 28 BDSG - Datenverarbeitung zu Zwecken des Beschäftigtendatenschutzes

**Erlaubt**: Verarbeitung für Überwachungsmaßnahmen, wenn:
1. **Tatsächliche Anhaltspunkte** für Rechtsverletzungen
2. **Art und Umfang** verhältnismäßig
3. **Andere Mittel** wären unverhältnismäßig oder erfolglos

**Beispiel**: Videoüberwachung bei konkretem Diebstahlsverdacht

### § 29 BDSG - Verarbeitung zu Zwecken der Videoüberwachung

**Erlaubt**: Videoüberwachung öffentlich zugänglicher Räume, wenn:
1. **Erforderlich** zur:
   - Wahrnehmung des Hausrechts
   - Wahrnehmung berechtigter Interessen für konkret festgelegte Zwecke
2. **Keine Anhaltspunkte**, dass Interessen der Betroffenen überwiegen

**Transparenz-Pflichten**:
- **Hinweispflicht**: Deutlich erkennbare Hinweise vor Betreten des Bereichs
- **Kontaktdaten** des Verantwortlichen
- **Zweck der Überwachung**

**Best Practice**:
```php
// ProcessingActivity für Videoüberwachung
$activity = new ProcessingActivity();
$activity->setName('Videoüberwachung Eingangsbereich');
$activity->setLegalBasis('Art. 6 Abs. 1 lit. f DSGVO i.V.m. § 4 BDSG (neu)');
$activity->setPersonalDataCategories(['Bilddaten']);
$activity->setPurposes(['Schutz des Hausrechts', 'Schutz vor Diebstahl']);
$activity->setRecipients(['Geschäftsführung', 'Sicherheitsdienst']);
$activity->setRetentionPeriod('72 Stunden (rollierend)');

// Controls hinzufügen (Data Reuse!)
$control = $controlRepository->findOneBy(['identifier' => 'ORG-08']);
$activity->addControl($control); // Physische Sicherheit
```

### § 30 BDSG - Verarbeitung zu Zwecken der Durchführung vorvertraglicher Maßnahmen

**Öffnungsklausel zu**: Art. 6 Abs. 1 lit. b DSGVO (Vertragsanbahnung)

**Erlaubt**: Bonitätsprüfung und Scoringverfahren bei Vertragsanbahnung, wenn:
1. **Erforderlich** zur Entscheidung über Begründung, Durchführung oder Beendigung
2. **Keine Anhaltspunkte** für überwiegende Interessen

---

## Teil 3: Rechte der betroffenen Person und Aufsichtsbehörde (§§ 32-37)

### § 32 BDSG - Informationspflicht bei Erhebung von personenbezogenen Daten bei der betroffenen Person

**Einschränkung zu**: Art. 13 DSGVO (Informationspflichten)

**Ausnahme**: Informationspflicht entfällt, wenn betroffene Person bereits informiert wurde.

### § 34 BDSG - Auskunftsrecht der betroffenen Person

**Einschränkung zu**: Art. 15 DSGVO (Auskunftsrecht)

**Verweigerung erlaubt**, wenn:
1. **Gefährdung** der öffentlichen Sicherheit oder Ordnung
2. **Gefährdung** laufender Ermittlungen
3. **Unverhältnismäßiger Aufwand** (muss dokumentiert werden!)

**Für nicht-öffentliche Stellen**: Gilt primär Art. 15 DSGVO - Einschränkungen nur in Ausnahmefällen!

### § 35 BDSG - Widerspruchsrecht

**Konkretisierung zu**: Art. 21 DSGVO (Widerspruchsrecht)

**Regelt**: Widerspruchsrecht bei öffentlichen Stellen

### § 37 BDSG - Rechte der betroffenen Person und aufsichtsbehördliche Befugnisse

**Öffnungsklausel zu**: Art. 23 DSGVO (Beschränkungen)

**Erlaubt Einschränkungen** der Betroffenenrechte (Art. 15, 16, 17, 18, 21 DSGVO) bei:
- **Staatlicher und öffentlicher Sicherheit**
- **Strafverfolgung**
- **Geheimhaltungspflichten**

**Für nicht-öffentliche Stellen**: Gilt nur in Ausnahmefällen!

---

## Teil 4: Datenschutzbeauftragter und Aufsichtsbehörden (§§ 38-40)

### § 38 BDSG - Benennung von Datenschutzbeauftragten

**WICHTIGSTE VORSCHRIFT für DSB-Pflicht in Deutschland!**

**Öffnungsklausel zu**: Art. 37 DSGVO (Pflicht zur Benennung eines DSB)

#### § 38 Abs. 1 BDSG - Benennungspflicht

**Pflicht zur Benennung eines DSB**, wenn:

1. **In der Regel mindestens 20 Personen** ständig mit der automatisierten Verarbeitung personenbezogener Daten beschäftigt sind

**ODER**

2. **Datenschutz-Folgenabschätzung erforderlich** (Art. 35 DSGVO)

**ODER**

3. **Geschäftsmäßige Datenverarbeitung** zum Zweck der:
   - Übermittlung (Adresshandel)
   - Anonymisierten Übermittlung (Marktforschung)
   - Markt- oder Meinungsforschung

**Wichtig**:
- **"20 Personen"**: Zählen nur Personen, die **ständig** mit Datenverarbeitung beschäftigt sind, nicht alle Mitarbeiter!
- **"Ständig"**: Nicht nur gelegentlich (z.B. HR, IT-Admin, Marketing zählen; Produktionsmitarbeiter meist nicht)
- **DSGVO Art. 37**: Zusätzliche Benennungspflicht bei umfangreicher systematischer Überwachung oder Verarbeitung besonderer Kategorien

**Beispielrechnung**:
```
Unternehmen mit 100 Mitarbeitern:
- 2 HR-Mitarbeiter (ständig mit Personaldaten beschäftigt)
- 3 IT-Admins (ständig mit Systemdaten beschäftigt)
- 5 Marketing-Mitarbeiter (ständig mit Kundendaten beschäftigt)
- 2 Vertrieb (ständig mit Kundendaten beschäftigt)
- 88 Produktionsmitarbeiter (keine ständige Datenverarbeitung)

→ 12 Personen ständig mit Datenverarbeitung beschäftigt
→ KEINE DSB-Pflicht nach § 38 BDSG (< 20 Personen)

ABER: Prüfung DSGVO Art. 37 erforderlich!
```

**Best Practice in App**:
```php
// Tenant-Attribut für DSB-Pflicht
$tenant->setDpoRequired(true);
$tenant->setDpoName('Max Mustermann');
$tenant->setDpoEmail('dpo@example.com');
$tenant->setDpoPhone('+49 30 12345678');
```

#### § 38 Abs. 2 BDSG - Aufgaben des DSB

**Verweist auf**: Art. 39 DSGVO (Aufgaben des DSB)

**Wichtig**: DSB hat **Beratungs- und Überwachungsfunktion**, nicht Entscheidungsfunktion!

### § 39 BDSG - Stellung des Datenschutzbeauftragten

**Konkretisierung zu**: Art. 38 DSGVO (Stellung des DSB)

**Garantien**:
1. **Weisungsfreiheit** bei der Erfüllung seiner Aufgaben
2. **Kündigungsschutz**: Abberufung/Kündigung nur aus wichtigem Grund
3. **Fortbildungsanspruch** (§ 39 Abs. 1 Satz 2 BDSG neu)

### § 40 BDSG - Akkreditierung

**Regelt**: Anforderungen an Zertifizierungsstellen nach Art. 43 DSGVO

---

## Teil 5: Aufsichtsbehörden (§§ 8-21, 51-57)

### § 51 BDSG - Aufsichtsbehörden

**Struktur**: Deutschland hat **17 Datenschutzaufsichtsbehörden**:

1. **Bundesbeauftragte für Datenschutz und Informationsfreiheit (BfDI)** - für Bundesbehörden, Telekommunikation, Post
2. **16 Landesbeauftragte** - für alle sonstigen Stellen im jeweiligen Bundesland

**Zuständigkeit für nicht-öffentliche Stellen**:
- Nach **Hauptsitz** des Unternehmens (Niederlassungsprinzip)

**Wichtige Aufsichtsbehörden**:
- **Bayern**: Bayerisches Landesamt für Datenschutzaufsicht (BayLDA)
- **Baden-Württemberg**: Landesbeauftragter für Datenschutz und Informationsfreiheit Baden-Württemberg (LfDI BW)
- **Nordrhein-Westfalen**: Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen (LDI NRW)
- **Hessen**: Hessischer Beauftragter für Datenschutz und Informationsfreiheit (HBDI)
- **Berlin**: Berliner Beauftragte für Datenschutz und Informationsfreiheit (BlnBDI)

**Best Practice in App**:
```php
// DataBreach mit Aufsichtsbehörde
$breach = new DataBreach();
$breach->setAuthorityName('Bayerisches Landesamt für Datenschutzaufsicht (BayLDA)');
$breach->setAuthorityEmail('poststelle@lda.bayern.de');
$breach->setAuthorityNotified(true);
$breach->setAuthorityNotifiedAt(new \DateTime());
```

---

## Teil 6: Strafvorschriften und Bußgeldvorschriften (§§ 41-43)

### § 41 BDSG - Strafvorschriften

**Straftat**: Unbefugte Verarbeitung personenbezogener Daten gegen Entgelt oder in Bereicherungs- oder Schädigungsabsicht.

**Strafe**: Freiheitsstrafe bis zu **3 Jahren** oder Geldstrafe

**Beispiele**:
- Verkauf von Kundendaten an Dritte
- Identitätsdiebstahl mit Daten aus dem Unternehmen
- Erpressung mit personenbezogenen Daten

### § 42 BDSG - Bußgeldvorschriften

**Regelt**: Zusätzliche Bußgeldtatbestände zu Art. 83 DSGVO.

**Wichtig**: DSGVO-Bußgelder (bis €20M oder 4% Jahresumsatz) gehen vor!

### § 43 BDSG - Befugnisse der Aufsichtsbehörden

**Regelt**: Durchsetzungsbefugnisse der Aufsichtsbehörden nach Art. 58 DSGVO.

---

## Wichtige Öffnungsklauseln im Überblick

**BDSG nutzt folgende DSGVO-Öffnungsklauseln**:

| DSGVO-Artikel | BDSG-Paragraph | Thema |
|---------------|----------------|-------|
| Art. 6 Abs. 2, 3 | § 23 BDSG | Zweckänderung öffentliche Stellen |
| Art. 6 Abs. 4 | § 24 BDSG | Archivierung |
| Art. 9 Abs. 2 lit. b | § 22 BDSG | Besondere Kategorien im Beschäftigungskontext |
| Art. 23 | § 32-37 BDSG | Beschränkungen der Betroffenenrechte |
| Art. 37 | § 38 BDSG | DSB-Benennungspflicht (20 Personen!) |
| Art. 88 | § 26 BDSG | Beschäftigtendatenschutz |
| Art. 89 | § 27 BDSG | Forschung und Statistik |

---

## Häufige Fehler und Best Practices

### ❌ Fehler 1: "BDSG gilt statt DSGVO"

**FALSCH**: BDSG gilt **zusätzlich** zur DSGVO, nicht anstelle!

**RICHTIG**: Immer beide Rechtsgrundlagen prüfen:
```php
$activity->setLegalBasis('Art. 6 Abs. 1 lit. b DSGVO i.V.m. § 26 Abs. 1 BDSG');
```

### ❌ Fehler 2: "Wir haben 30 Mitarbeiter → DSB-Pflicht"

**FALSCH**: Es zählen nur die **ständig mit Datenverarbeitung beschäftigten** Personen!

**RICHTIG**: Zähle nur HR, IT, Marketing, Vertrieb → wenn ≥ 20 → DSB-Pflicht

### ❌ Fehler 3: "Einwilligung löst alle Probleme im Arbeitsverhältnis"

**FALSCH**: Einwilligung ist im Arbeitsverhältnis **besonders problematisch** wegen Abhängigkeit!

**RICHTIG**: Prüfe zuerst § 26 Abs. 1 BDSG (Erforderlichkeit), nur wenn nicht greift → Einwilligung (mit hohen Hürden!)

### ❌ Fehler 4: "Videoüberwachung ist immer erlaubt"

**FALSCH**: Videoüberwachung braucht **konkreten Zweck** und **verhältnismäßig**!

**RICHTIG**:
- Nur öffentlich zugängliche Räume (§ 4 BDSG)
- Deutliche Hinweise vor Betreten
- Kurze Speicherfrist (72h)
- Keine Überwachung von Sanitärräumen, Pausenräumen

### ✅ Best Practice 1: Immer Rechtsgrundlage mit DSGVO + BDSG angeben

```php
// Gut dokumentierte ProcessingActivity
$activity = new ProcessingActivity();
$activity->setName('Personalverwaltung');
$activity->setLegalBasis('Art. 6 Abs. 1 lit. b DSGVO i.V.m. § 26 Abs. 1 BDSG');
$activity->setDescription(
    'Verarbeitung von Beschäftigtendaten zur Durchführung des ' .
    'Arbeitsverhältnisses (Lohnabrechnung, Zeiterfassung, Urlaubsverwaltung)'
);
```

### ✅ Best Practice 2: DSB-Pflicht dokumentieren

```php
// Tenant-Check für DSB-Pflicht
if ($tenant->getEmployeeCount() >= 20) {
    // Prüfung: Wie viele davon ständig mit Datenverarbeitung?
    $dataProcessingEmployees = $this->countDataProcessingEmployees($tenant);

    if ($dataProcessingEmployees >= 20) {
        $tenant->setDpoRequired(true);
        $this->auditLogger->log('DSB-Pflicht nach § 38 Abs. 1 BDSG erkannt');
    }
}
```

### ✅ Best Practice 3: Data Reuse für Beschäftigtendaten

```php
// Control → ProcessingActivity (ISO 27001:2022 A.5.10 - Acceptable Use)
$activity = $processingActivityService->createFromControl(
    $controlRepository->findOneBy(['identifier' => 'ORG-10']), // Acceptable Use Policy
    [
        'name' => 'Nutzung von IT-Systemen durch Beschäftigte',
        'legalBasis' => 'Art. 6 Abs. 1 lit. b DSGVO i.V.m. § 26 Abs. 1 BDSG',
        'dataSubjectCategories' => ['Beschäftigte'],
    ],
    $tenant
);
```

---

## Integration mit GDPR und ISO 27701

### BDSG + GDPR Mapping

| BDSG-Thema | GDPR-Artikel | ISO 27701:2025 |
|------------|--------------|----------------|
| § 22 BDSG - Besondere Kategorien Beschäftigte | Art. 9 Abs. 2 lit. b | 6.2.1.4 (Purpose limitation) |
| § 26 BDSG - Beschäftigtendaten | Art. 88 | 6.2.1.1 (Lawfulness), 6.2.1.5 (Employment) |
| § 29 BDSG - Videoüberwachung | Art. 6 Abs. 1 lit. f | 6.2.5.3 (Surveillance) |
| § 38 BDSG - DSB-Pflicht | Art. 37 | 5.2.2 (DPO/PIMS roles) |

### Workflow: BDSG-konforme ProcessingActivity erstellen

```php
// 1. Prüfung: Beschäftigtendaten?
$isEmployeeData = in_array('Beschäftigte', $activity->getDataSubjectCategories());

if ($isEmployeeData) {
    // 2. Rechtsgrundlage: § 26 Abs. 1 BDSG prüfen
    if ($this->isNecessaryForEmployment($activity)) {
        $activity->setLegalBasis('Art. 6 Abs. 1 lit. b DSGVO i.V.m. § 26 Abs. 1 BDSG');
    } else {
        // 3. Einwilligung erforderlich - hohe Hürden!
        $activity->setLegalBasis('Art. 6 Abs. 1 lit. a DSGVO (Einwilligung nach § 26 Abs. 2 BDSG)');
        $this->auditLogger->log('WARNUNG: Einwilligung im Arbeitsverhältnis - Freiwilligkeit prüfen!');
    }

    // 4. Besondere Kategorien? (Gesundheitsdaten, etc.)
    $specialCategories = array_intersect(
        $activity->getPersonalDataCategories(),
        ['Gesundheitsdaten', 'Gewerkschaftszugehörigkeit', 'Ethnische Herkunft']
    );

    if (!empty($specialCategories)) {
        // § 22 BDSG anwendbar
        $activity->setLegalBasis('Art. 9 Abs. 2 lit. b DSGVO i.V.m. § 22 BDSG');
    }
}

// 5. DPIA-Pflicht? (§ 38 Abs. 1 Nr. 2 BDSG → DSB-Pflicht!)
if ($activity->requiresDPIA()) {
    $tenant->setDpoRequired(true);
}

// 6. Controls zuordnen (Data Reuse!)
$activity->addControl($controlRepository->findOneBy(['identifier' => 'ORG-10'])); // HR policies
```

---

## Schnellreferenz: BDSG für DPO

### Top 5 BDSG-Vorschriften für DPO:

1. **§ 38 BDSG** - DSB-Benennungspflicht (≥ 20 Personen ständig mit Datenverarbeitung)
2. **§ 26 BDSG** - Beschäftigtendatenschutz (Erforderlichkeit, Einwilligung problematisch)
3. **§ 22 BDSG** - Besondere Kategorien im Beschäftigungskontext (Gesundheitsdaten)
4. **§ 29 BDSG** - Videoüberwachung (Hinweispflicht, Verhältnismäßigkeit)
5. **§ 51 BDSG** - Aufsichtsbehörden (17 in Deutschland, Zuständigkeit nach Hauptsitz)

### Faustregel Rechtsgrundlage:

```
Beschäftigtendaten → § 26 BDSG (Erforderlichkeit) > Einwilligung
Besondere Kategorien + Beschäftigung → § 22 BDSG
Videoüberwachung → § 4 BDSG (neu: § 29 BDSG alte Zählung)
DSB-Pflicht → § 38 BDSG (20 Personen!) + Art. 37 DSGVO
```

### App-Check BDSG-Konformität:

```bash
# 1. Alle ProcessingActivities mit Beschäftigtendaten finden
SELECT * FROM processing_activity
WHERE data_subject_categories LIKE '%Beschäftigte%'
AND legal_basis NOT LIKE '%§ 26%';
# → Manuell prüfen ob Rechtsgrundlage korrekt!

# 2. DSB-Pflicht prüfen
SELECT tenant_id, dpo_required, dpo_name
FROM tenant
WHERE dpo_required = false;
# → Manuelle Prüfung: Anzahl Beschäftigte mit ständiger Datenverarbeitung?

# 3. Videoüberwachung ohne Hinweis?
SELECT * FROM processing_activity
WHERE name LIKE '%Video%'
AND description NOT LIKE '%Hinweis%';
# → Warnung: Transparenzpflicht nach § 4 BDSG!
```

---

## Zusammenfassung

Das **BDSG** ist die **deutsche Ergänzung zur DSGVO**, nicht deren Ersatz. Es nutzt die Öffnungsklauseln der DSGVO, um in bestimmten Bereichen (v.a. Beschäftigtendatenschutz, Videoüberwachung, DSB-Pflicht) **spezifischere nationale Regelungen** zu treffen.

**Für den DPO Agent wichtig**:
1. **§ 26 BDSG** ist die zentrale Vorschrift für Beschäftigtendaten
2. **§ 38 BDSG** senkt die DSB-Schwelle auf **20 Personen** (statt Art. 37 DSGVO)
3. Immer **beide Rechtsgrundlagen** angeben: DSGVO + BDSG
4. **Data Reuse** aus Control/Asset für ProcessingActivity nutzen
5. BDSG gilt **zusätzlich**, nicht anstelle der DSGVO!

---

**Version**: 1.0 (November 2025)
**Letztes Update**: Basierend auf BDSG (neu) in Kraft seit 25.05.2018, Stand November 2025