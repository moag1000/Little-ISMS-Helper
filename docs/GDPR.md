# DSGVO / GDPR — Compliance-Dokumentation

Stand: v3.5 (2026-05). Gilt fuer das Modul `privacy` (Aktivierungskey in `config/modules.yaml`).

---

## 1. Ueberblick

Das Tool bildet die datenschutzrechtlichen Pflichten der DSGVO (Verordnung (EU) 2016/679)
prozessual in einer ISMS-Umgebung ab. Alle Entitaeten sind tenant-isoliert (`tenant_id`).
Sicherheitsrelevante Ereignisse werden ueber den `AuditLogger` protokolliert
(ISO 27001:2022 Kl. 7.5.3).

Aktivierungsvoraussetzung: Tenant-Modul `privacy` muss in
`config/active_modules.yaml` aktiviert sein. Alle GDPR-Felder und -Routen sind
hinter `is_module_active('privacy')` gesperrt.

---

## 2. Einwilligungsverwaltung (Art. 6, Art. 7 DSGVO)

### 2.1 Entitaet `Consent`

Datei: `src/Entity/Consent.php`

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `purpose` | string | Art. 13 Abs. 1 lit. c |
| `legalBasis` | string | Art. 6 Abs. 1 |
| `givenAt` | DateTimeImmutable | Art. 7 Abs. 1 |
| `withdrawnAt` | ?DateTimeImmutable | Art. 7 Abs. 3 |
| `withdrawalReason` | ?string | Art. 7 Abs. 3 (Nachweis) |
| `withdrawalChannel` | ?string | Art. 7 Abs. 3 (Nachweis) |

### 2.2 Widerruf-Tracking (Art. 7 Abs. 3 DSGVO) — neu in v3.5

Seit v3.5 werden Widerrufe vollstaendig dokumentiert. Validierungsregel:
Wenn `withdrawnAt` gesetzt ist, sind `withdrawalReason` und `withdrawalChannel`
Pflichtfelder (Constraint-Klasse in `Consent::validate()`).

Die Hilfsmethode `Consent::isWithdrawn()` prueft `withdrawnAt !== null`.
Die Methode `Consent::isActive()` kehrt den Wert um und prueft zusaetzlich das
Ablaufdatum.

---

## 3. Betroffenenrechte (Art. 12–22 DSGVO)

### 3.1 Entitaet `DataSubjectRequest`

Datei: `src/Entity/DataSubjectRequest.php`

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `type` | string (enum) | Art. 15–22 (Auskunft/Loeschung/Portabilitaet usw.) |
| `requestedAt` | DateTimeImmutable | Art. 12 Abs. 3 (Fristbeginn) |
| `deadlineAt` | DateTimeImmutable | Art. 12 Abs. 3 (1-Monats-Frist) |
| `responseAt` | ?DateTimeImmutable | Art. 12 Abs. 3 (Beantwortung) |
| `extendedDeadlineAt` | ?DateTimeImmutable | Art. 12 Abs. 3 (Fristverlängerung bis 3 Monate) |
| `extensionReason` | ?string | Art. 12 Abs. 3 S. 2 (Begruendungspflicht) |
| `responseDocument` | ?string | Art. 12 Abs. 1 (Transparenz, Dateipfad) |
| `responseMethod` | ?string | Art. 12 Abs. 1 (Uebermittlungsweg) |
| `rejectionReason` | ?string | Art. 12 Abs. 4 (Ablehnungsbegruendung) |

### 3.2 Frist-Tracking (Art. 12 Abs. 3 DSGVO) — neu in v3.5

Das effektive Antwortdatum ergibt sich aus:

```
DataSubjectRequest::getEffectiveDeadline() = extendedDeadlineAt ?? deadlineAt
```

Validierungsregeln (Constraint in `DataSubjectRequest::validate()`):

- `extendedDeadlineAt` gesetzt → `extensionReason` Pflichtfeld
- `responseAt` gesetzt → `responseMethod` Pflichtfeld
- `rejectionReason` gesetzt → `responseAt` Pflichtfeld (Ablehnung ist selbst eine Antwort)

### 3.3 Hilfsmethoden

| Methode | Beschreibung |
|---|---|
| `isAnswered()` | `responseAt !== null` |
| `isDeadlineExtended()` | `extendedDeadlineAt !== null` |
| `getEffectiveDeadline()` | `extendedDeadlineAt ?? deadlineAt` |

---

## 4. Verarbeitungsverzeichnis (Art. 30 DSGVO)

### 4.1 Entitaet `ProcessingActivity`

Datei: `src/Entity/ProcessingActivity.php`

Seit v3.5 besteht eine Many-to-Many-Beziehung zwischen `ProcessingActivity` und
`Asset` (V3 W2-Bug3). Die Verknuepfung erlaubt die normative Zuordnung von
Verarbeitungstaetigkeiten zu den verarbeitenden IT-Assets gemaess Art. 30 Abs. 1
lit. a und d DSGVO.

```
ProcessingActivity ---[ManyToMany]--> Asset
(owning side)                        (inverse: Asset::$processingActivities)
```

Tabelle: `processing_activity_asset` (Join-Table, autogeneriert durch Doctrine).

---

## 5. Datenpannen-Management (Art. 33/34 DSGVO)

### 5.1 Entitaet `DataBreach`

Datei: `src/Entity/DataBreach.php`

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `severity` | string | Art. 33 Abs. 1 (Risikobewertung) |
| `dataCategories` | array | Art. 33 Abs. 3 lit. b |
| `affectedDataSubjectsCount` | ?int | Art. 33 Abs. 3 lit. a |
| `notificationRequired` | bool | Art. 33 Abs. 1 / Art. 34 Abs. 1 |
| `reportedAt` | ?DateTimeImmutable | Art. 33 Abs. 1 (72-h-Frist) |

Die Entitaet ist mit `Incident` verknuepft fuer Datenwiederverwertung
(Incident-Data-Reuse-Muster).

### 5.2 Workflow — GDPR Data Breach (Art. 33/34 DSGVO)

Out-of-the-Box-Workflow, generiert ueber:

```bash
php bin/console app:generate-regulatory-workflows --workflow=data-breach
```

**6 Schritte mit Auto-Progression:**

| Schritt | Verantwortlich | Auto-Trigger |
|---|---|---|
| 1. DPO-Bewertung | DPO | `severity` + `dataCategories` + `affectedDataSubjectsCount` gesetzt |
| 2. Technische Bewertung | CISO | `notificationRequired` gesetzt |
| 3. Meldepflicht-Entscheidung | DPO + CISO | AND-Logik: severity >= high AND count > 100 |
| 4. Meldung an Aufsicht (BSB/LDA) | DPO | `reportedAt` innerhalb 72h |
| 5. Benachrichtigung Betroffene | DPO | Art. 34 Abs. 1 — nur bei hohem Risiko |
| 6. Abschluss + Dokumentation | CISO | Alle Pflichtfelder abgeschlossen |

**72-h-Frist-Monitoring:** `IncidentSlaConfig` haelt die GDPR-72h-SLA. Der Cron-Job
`app:process-timed-workflows` prueft timed workflows inkl. Fristablauf.

### 5.3 BSI-MUS Export (Art. 33 DSGVO / Art. 23 NIS2-Cross-Reporting)

Datenpannen mit NIS2-Relevanz (z.B. bei kritischen Infrastrukturen) koennen
ueber den BSI-MUS-Export nach Art. 23 NIS2 gemeldet werden:

```
src/Controller/Nis2MusExportController.php
src/Service/Nis2MusExportService.php
```

Cross-Reporting-Pfad: `DataBreach` → `Incident` → NIS2-MUS-Meldung.

---

## 6. Datenschutz-Folgenabschaetzung (Art. 35/36 DSGVO)

### 6.1 Entitaet `DataProtectionImpactAssessment`

Datei: `src/Entity/DataProtectionImpactAssessment.php`

### 6.2 SDM 3.1 Schutzziele-Mapping

Seit v3.5 unterstuetzt das Tool das Standard-Datenschutzmodell (SDM 3.1) der
Datenschutzkonferenz (DSK) nativ. Die 7 Gewaehrleistungsziele sind als
Konstantenliste in der Entitaet definiert:

```php
DataProtectionImpactAssessment::SDM_PROTECTION_GOALS = [
    'data_minimisation',
    'availability',
    'integrity',
    'confidentiality',
    'unlinkability',
    'transparency',
    'intervenability',
]
```

| Feld | Typ | Beschreibung |
|---|---|---|
| `sdmAssessment` | ?array | Pro-Ziel-Bewertung (Shape: goal => status/severity) |
| `sdmAssessmentSummary` | ?string | Freitext-Gesamtbewertung SDM-3.1 |

**Coverage-Berechnung:**

```
DataProtectionImpactAssessment::getSdmCoveragePercent()
= (Anzahl bewerteter Ziele / 7) * 100
```

Hoechste SDM-Risikostufe: `getHighestSdmRiskSeverity()` — gibt `low/medium/high` zurueck.

### 6.3 DPIA-Review-Zyklus

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `nextReviewDate` | ?DateTimeInterface | Art. 35 Abs. 11 (periodische Ueberpruefung) |

Der Cron-Job `app:process-timed-workflows` prueft faellige DPIA-Reviews und
erzeugt Reminder-Notifications.

### 6.4 DPIA Auto-Trigger (Art. 35 Abs. 1 DSGVO)

Zwei Mechanismen triggern automatisch eine DPIA-Pruefung:

**Trigger 1 — Asset.dataClassification (V3 W2-H5):**

Wenn `Asset::$dataClassification` auf einen sensiblen Wert gesetzt wird
(besondere Kategorien nach Art. 9 DSGVO), loest der EventListener eine
DPIA-Pruefung aus.

```
src/Entity/Asset.php — $dataClassification (Zeile ~205)
```

**Trigger 2 — Asset-Schutzbedarf-Aenderung (V3 W2-WS-10):**

Aenderungen am BSI-Schutzbedarf eines Assets koennen eine DPIA-Pruefung
ausloesen, wenn der Asset personenbezogene Daten verarbeitet.

Beide Trigger setzen auf den `WorkflowAutoProgressionService`:

```bash
# DPIA-Workflow generieren
php bin/console app:generate-regulatory-workflows --workflow=dpia
```

### 6.5 Workflow — DPIA (Art. 35/36 DSGVO)

**6 Schritte:**

| Schritt | Verantwortlich | Beschreibung |
|---|---|---|
| 1. Screening | DPO | Notwendigkeitspruefung nach Art. 35 Abs. 1 |
| 2. Beschreibung | Fachbereich | Verarbeitungsvorgang Art. 35 Abs. 7 lit. a |
| 3. Notwendigkeit/Verhaeltnismaessigkeit | DPO | Art. 35 Abs. 7 lit. b |
| 4. Risikobewertung + SDM | CISO + DPO | Art. 35 Abs. 7 lit. c + SDM 3.1 |
| 5. Massnahmenplan | CISO | Art. 35 Abs. 7 lit. d |
| 6. DPO-Abnahme / Vorabkonsultation | DPO | Art. 36 (bei verbleibendem Risiko) |

---

## 7. Modul-Aktivierung und Routen

Alle GDPR-Funktionen sind hinter dem Modul `privacy` gesperrt:

```twig
{% if is_module_active('privacy') %}
    {# GDPR-Felder sichtbar #}
{% endif %}
```

Controller-Pattern:

```php
if ($redirect = $this->checkModuleActive('privacy')) return $redirect;
```

Compliance-Wizard-Route: `/{locale}/compliance/wizard/gdpr`

---

## 8. Audit-Trail

Alle Datenschutz-relevanten Ereignisse (Einwilligungen, Widerrufe, DSR-Bearbeitung,
Datenpannen, DPIA-Erstellung) werden ueber `AuditLogger` protokolliert. Massenoperationen
verwenden `AuditLogger::logBulk()` (Batch-ID als UUIDv4 gemaess ISO 27001:2022 Kl. 7.5.3).

---

## 9. Referenzen

| Norm | Artikel | Implementierung |
|---|---|---|
| DSGVO | Art. 6 Abs. 1 | `Consent::$legalBasis` |
| DSGVO | Art. 7 Abs. 3 | `Consent::$withdrawnAt`, `$withdrawalReason`, `$withdrawalChannel` |
| DSGVO | Art. 12 Abs. 3 | `DataSubjectRequest::$responseAt`, `$extendedDeadlineAt`, `$extensionReason` |
| DSGVO | Art. 12 Abs. 4 | `DataSubjectRequest::$rejectionReason` |
| DSGVO | Art. 30 | `ProcessingActivity` + M2M `Asset` |
| DSGVO | Art. 33/34 | `DataBreach` + Workflow `data-breach` |
| DSGVO | Art. 35 Abs. 1 | DPIA Auto-Trigger via `Asset::$dataClassification` |
| DSGVO | Art. 35 Abs. 7 | `DataProtectionImpactAssessment` + SDM 3.1 |
| DSGVO | Art. 35 Abs. 11 | `DataProtectionImpactAssessment::$nextReviewDate` |
| DSGVO | Art. 36 | DPIA-Workflow Schritt 6 |
| SDM 3.1 (DSK) | Gewaehrleistungsziele | `DataProtectionImpactAssessment::SDM_PROTECTION_GOALS` |
