# Audit Logging - Revisionssicheres Protokollierungssystem

## Übersicht

Das Audit Logging System stellt eine umfassende, revisionssichere Protokollierung aller Aktivitäten im ISMS-Tool sicher. Dies ist eine wichtige Anforderung für ISO 27001 und andere Compliance-Standards.

## Funktionen

### Automatische Protokollierung

Das System protokolliert automatisch folgende Aktivitäten:

- **CREATE**: Erstellung neuer Entitäten (Assets, Risiken, Controls, etc.)
- **UPDATE**: Änderungen an bestehenden Entitäten (inkl. alter und neuer Werte)
- **DELETE**: Löschung von Entitäten (inkl. finaler Werte)
- **VIEW**: Anzeige sensibler Daten (optional)
- **EXPORT**: Export von Daten
- **IMPORT**: Import von Daten

### Protokollierte Entitäten

Das System protokolliert Änderungen an folgenden Entitäten:

- Asset
- Risk
- Control
- Incident
- InternalAudit
- ManagementReview
- ISMSContext
- ISMSObjective
- Training
- BusinessProcess
- AuditChecklist
- ComplianceRequirement
- ComplianceFramework
- ComplianceMapping
- Supplier
- InterestedParty
- BusinessContinuityPlan
- BCExercise
- RecurringDuty
- ChangeRequest
- IdentityProvider
- Vulnerability
- IsmsRegisterEntry

Maßgeblich ist die Liste in `AuditLogSubscriber::shouldAudit()`. Weitere Einträge schreiben Services
direkt über den `AuditLogger` (Lifecycle-Übergänge, Bulk-Aktionen, Quick-Fix, Backups, Downloads,
Datentransfers, MCP-Aufrufe).

### Erfasste Informationen

Für jeden Protokolleintrag werden folgende Informationen erfasst:

- **Zeitstempel**: Wann die Aktion stattfand
- **Aktion**: Art der Aktion (create, update, delete, etc.)
- **Entitätstyp**: Welcher Typ von Entität betroffen war
- **Entitäts-ID**: ID der betroffenen Entität
- **Benutzer**: E-Mail des angemeldeten Benutzers, sonst `system` (CLI, Scheduler)
- **IP-Adresse**: Von welcher IP-Adresse die Aktion kam
- **User Agent**: Welcher Browser/Client verwendet wurde
- **Alte Werte**: Werte vor der Änderung (bei Updates und Deletes)
- **Neue Werte**: Werte nach der Änderung (bei Creates und Updates)
- **Beschreibung**: Beschreibende Notiz zur Aktion

## Verwendung

### Automatische Protokollierung (Standard)

Die meisten Änderungen werden automatisch protokolliert durch den `AuditLogSubscriber`, der auf Doctrine-Events hört:

```php
// Änderungen werden automatisch protokolliert
$asset = new Asset();
$asset->setName('Server XY');
// ... weitere Properties setzen
$entityManager->persist($asset);
$entityManager->flush(); // CREATE wird automatisch protokolliert
```

### Manuelle Protokollierung

Für spezielle Fälle können Sie den `AuditLogger`-Service direkt verwenden:

```php
use App\Service\AuditLogger;

class MyController extends AbstractController
{
    public function __construct(
        private AuditLogger $auditLogger
    ) {}

    public function exportData(): Response
    {
        // Ihre Export-Logik hier

        // Protokollieren Sie den Export
        $this->auditLogger->logExport(
            'Asset',
            null,
            'Export aller Assets als CSV'
        );

        return $response;
    }
}
```

### Verfügbare Methoden des AuditLogger

Konsolenbefehle und Cron-Läufe haben keinen TenantContext; ihre Einträge würden
ohne Mandant (`tenant = null`) geschrieben. Dort den Mandanten explizit setzen:
`$auditLogger->withTenant($tenant, fn () => $auditLogger->logBulk(...))`
(Retention-Sweep, Benachrichtigungs-Digest, versendete geplante Berichte).
HTTP-Aufrufer brauchen das nicht.

```php
// Erstellung protokollieren
$auditLogger->logCreate(
    string $entityType,
    int $entityId,
    array $newValues,
    ?string $description = null
): void

// Änderung protokollieren
$auditLogger->logUpdate(
    string $entityType,
    int $entityId,
    array $oldValues,
    array $newValues,
    ?string $description = null
): void

// Löschung protokollieren
$auditLogger->logDelete(
    string $entityType,
    int $entityId,
    array $oldValues,
    ?string $description = null
): void

// Anzeige sensibler Daten protokollieren
$auditLogger->logView(
    string $entityType,
    ?int $entityId,
    ?string $description = null
): void

// Export protokollieren
$auditLogger->logExport(
    string $entityType,
    ?int $entityId = null,
    ?string $description = null
): void

// Import protokollieren
$auditLogger->logImport(
    string $entityType,
    int $count,
    ?string $description = null
): void

// Benutzerdefinierte Aktion protokollieren
$auditLogger->logCustom(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?string $description = null
): void
```

## Abfragen und Analysen

### UI-Zugriff

Alle Seiten unter `/admin/audit-log` (Rolle `ROLE_ADMIN`; mit Sprachpräfix `/{locale}/admin/audit-log`):

- **Hauptübersicht**: `/admin/audit-log` — alle Einträge mit Filtern (`entityType`, `action`, Benutzer, Zeitraum)
- **Entitätsverlauf**: `/admin/audit-log/entity/{entityType}/{entityId}`
- **Benutzeraktivität**: `/admin/audit-log/user/{userName}`
- **Statistiken**: `/admin/audit-log/statistics`
- **Details**: `/admin/audit-log/{id}`
- **Integrität prüfen**: Button "HMAC-Kette verifizieren" (`POST /admin/audit-log/verify`) — läuft als Hintergrundjob
  mit Fortschrittsseite über die Kette der eigenen Organisation (Super-Admin ohne Organisation: alle Ketten);
  eine gebrochene Kette endet rot mit den ersten betroffenen Einträgen
- **Aufbewahrung**: `/admin/audit-log/retention`

Detailseiten zeigen den Verlauf ihres Datensatzes im Abschnitt "Historie" (`show_history(entity)`).

### Filtermöglichkeiten

In der Hauptübersicht können Sie nach folgenden Kriterien filtern:

- Entitätstyp
- Aktion
- Benutzer
- Datumszeitraum

### Programmatischer Zugriff

Sie können auch programmatisch auf die Audit Logs zugreifen:

```php
use App\Repository\AuditLogRepository;

// Alle Logs für eine Entität abrufen
$logs = $auditLogRepository->findByEntity('Asset', 123);

// Alle Logs eines Benutzers abrufen
$logs = $auditLogRepository->findByUser('john.doe');

// Logs in einem Datumszeitraum abrufen
$logs = $auditLogRepository->findByDateRange(
    new \DateTime('2024-01-01'),
    new \DateTime('2024-12-31')
);

// Suche mit mehreren Kriterien
$logs = $auditLogRepository->search([
    'entityType' => 'Asset',
    'action' => 'update',
    'dateFrom' => new \DateTime('2024-01-01'),
    'limit' => 100
]);

// Statistiken abrufen
$actionStats = $auditLogRepository->getActionStatistics();
$entityTypeStats = $auditLogRepository->getEntityTypeStatistics();
$recentActivity = $auditLogRepository->getRecentActivity(24); // Letzte 24 Stunden
```

## Sicherheit und Datenschutz

### Schutz sensibler Daten

Der AuditLogger sanitiert automatisch sensible Daten:

- Felder, deren Name `password` oder `token` enthält, werden als `***` gespeichert
- Bei Datenschutz-Entitäten (`DataBreach`, `ProcessingActivity`, `DataSubjectRequest`, `Consent`,
  `Person`, `DataProtectionImpactAssessment`) werden Werte personenbezogener Felder geschwärzt;
  der Feldname bleibt erhalten
- Sehr lange Strings werden abgeschnitten (max. 1000 Byte)

### Unveränderlichkeit

- Es gibt keine UI oder API zum Bearbeiten von Logs
- **HMAC-Kette:** Mit `APP_AUDIT_HMAC_KEY` (≥ 32 Byte Zufall, in `.env.local` bzw. Container-Umgebung)
  wird jeder Eintrag signiert und mit dem vorherigen Eintrag **derselben Organisation** verkettet
  (`AuditLogIntegrityService`; Einträge ohne Organisation bilden die Instanzkette). Die Signatur deckt den
  Zeitpunkt in ganzen Sekunden ab, so wie die Datenbank ihn speichert. Einträge vor diesem Stand (bis
  `system_settings` `audit_log.hmac_legacy_until_id`) deckten Sekundenbruchteile ab, die nie gespeichert wurden:
  bei ihnen prüft die Kontrolle nur die Verkettung (Löschung erkennbar), nicht den Inhalt. Der älteste
  verbliebene Eintrag einer Kette ist ihr Anker, weil die Aufbewahrung alte Einträge löscht. Ohne den
  Schlüssel wird nicht signiert und die Prüfung meldet "deaktiviert". Den Schlüssel nicht wechseln und
  bei einem Serverumzug mitnehmen ([DISASTER_RECOVERY.md](../operations/DISASTER_RECOVERY.md)).
- **Aufbewahrung:** Standard 730 Tage, Minimum 365 (NIS-2), einstellbar unter
  `/admin/audit-log/retention`. Gelöscht wird nachts um 02:45 durch `app:audit-log:cleanup`
  (Scheduler, [SYMFONY_SCHEDULER.md](../SYMFONY_SCHEDULER.md)).

### Indizierung

Die Tabelle ist optimiert für schnelle Abfragen durch Indizes auf:

- `entity_type` und `entity_id` (zusammengesetzt)
- `user_name`
- `action`
- `created_at`

## Compliance-Anforderungen

Das Audit Logging System erfüllt folgende Compliance-Anforderungen:

### ISO 27001

- **A.8.15**: Protokollierung (ISO 27001:2022 — umfasst Ereignisprotokollierung, Schutz von Protokollinformationen, Administrator- und Betreiberprotokolle sowie Zeitsynchronisation)

### DSGVO

- **Art. 5 Abs. 2**: Nachweis der Einhaltung (Rechenschaftspflicht)
- **Art. 30**: Verzeichnis von Verarbeitungstätigkeiten
- **Art. 32**: Sicherheit der Verarbeitung

### Weitere Standards

- TISAX: Anforderungen an Nachvollziehbarkeit
- BSI IT-Grundschutz: Protokollierung sicherheitsrelevanter Ereignisse

## Erweiterte Konfiguration

### Neue Entitäten hinzufügen

Weitere Entitäten in `shouldAudit()` in `src/EventSubscriber/AuditLogSubscriber.php` ergänzen:

```php
private function shouldAudit(object $entity): bool
{
    $className = $this->auditLogger->getEntityTypeName($entity);

    $auditedEntities = [
        'Asset',
        'Risk',
        // ... bestehende Entitäten
        'YourNewEntity', // Fügen Sie hier neue Entitäten hinzu
    ];

    return in_array($className, $auditedEntities);
}
```

## Performance-Überlegungen

- Indizes optimieren Abfragen
- Bulk-Aktionen schreiben über `AuditLogger::logBulk()` einen Batch-Eintrag plus je einen Eintrag pro
  Datensatz (gemeinsame `batch_id`)
- Alte Einträge entfernt die Aufbewahrungsregel (siehe oben)

## Best Practices

1. **Regelmäßige Überprüfung**: Überprüfen Sie die Audit-Logs regelmäßig auf ungewöhnliche Aktivitäten
2. **Backup**: Das Audit-Log ist standardmäßig im Backup enthalten (`--include-audit-log`)
3. **Retention**: Definieren Sie eine klare Aufbewahrungsrichtlinie gemäß gesetzlicher Anforderungen
4. **Monitoring**: Implementieren Sie Alarme für kritische Ereignisse
5. **Training**: Schulen Sie Mitarbeiter über die Bedeutung von Audit-Logs

## Fehlerbehebung

### Logs werden nicht erstellt

Überprüfen Sie:

1. Ist die Entität in `shouldAudit()` aufgeführt?
2. Gibt es Datenbankfehler im Log?
3. Fehlt bei CLI-Einträgen die Organisation: `withTenant()` verwenden (siehe oben)

### Performance-Probleme

Bei Performance-Problemen:

1. Überprüfen Sie die Indizes
2. Implementieren Sie Paginierung
3. Archivieren Sie alte Logs
4. Optimieren Sie Datenbankabfragen
