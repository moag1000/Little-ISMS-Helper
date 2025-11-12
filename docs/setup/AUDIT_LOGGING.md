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

### Erfasste Informationen

Für jeden Protokolleintrag werden folgende Informationen erfasst:

- **Zeitstempel**: Wann die Aktion stattfand
- **Aktion**: Art der Aktion (create, update, delete, etc.)
- **Entitätstyp**: Welcher Typ von Entität betroffen war
- **Entitäts-ID**: ID der betroffenen Entität
- **Benutzer**: Wer die Aktion ausgeführt hat
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
    int $entityId,
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

Das System stellt eine benutzerfreundliche Web-Oberfläche zur Verfügung:

- **Hauptübersicht**: `/audit-log/` - Zeigt alle Protokolleinträge mit Filterfunktionen
- **Entitätsverlauf**: `/audit-log/entity/{entityType}/{entityId}` - Zeigt alle Änderungen einer bestimmten Entität
- **Benutzeraktivität**: `/audit-log/user/{userName}` - Zeigt alle Aktivitäten eines Benutzers
- **Statistiken**: `/audit-log/statistics` - Zeigt Statistiken und Diagramme
- **Details**: `/audit-log/{id}` - Zeigt Details eines einzelnen Protokolleintrags

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

- Passwortfelder werden als `***` gespeichert
- Token werden ebenfalls maskiert
- Sehr lange Strings werden abgeschnitten (max. 1000 Zeichen)

### Unveränderlichkeit

- Audit-Log-Einträge verwenden `DateTimeImmutable` für Zeitstempel
- Es gibt keine UI oder API zum Bearbeiten oder Löschen von Logs
- Nur Lesezugriff ist über die UI möglich

### Indizierung

Die Tabelle ist optimiert für schnelle Abfragen durch Indizes auf:

- `entity_type` und `entity_id` (zusammengesetzt)
- `user_name`
- `action`
- `created_at`

## Compliance-Anforderungen

Das Audit Logging System erfüllt folgende Compliance-Anforderungen:

### ISO 27001

- **A.12.4.1**: Ereignisprotokollierung
- **A.12.4.2**: Schutz von Protokollinformationen
- **A.12.4.3**: Administrator- und Betreiberprotokolle
- **A.12.4.4**: Zeitsynchronisation

### DSGVO

- **Art. 5 Abs. 2**: Nachweis der Einhaltung (Rechenschaftspflicht)
- **Art. 30**: Verzeichnis von Verarbeitungstätigkeiten
- **Art. 32**: Sicherheit der Verarbeitung

### Weitere Standards

- TISAX: Anforderungen an Nachvollziehbarkeit
- BSI IT-Grundschutz: Protokollierung sicherheitsrelevanter Ereignisse

## Migration

Die Datenbanktabelle wird durch die Migration `Version20251105000004` erstellt:

```bash
# Migration ausführen
php bin/console doctrine:migrations:migrate
```

## Erweiterte Konfiguration

### Neue Entitäten hinzufügen

Um weitere Entitäten zur Protokollierung hinzuzufügen, bearbeiten Sie die Methode `shouldAudit()` in `src/EventSubscriber/AuditLogSubscriber.php`:

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

### Benutzererkennung anpassen

Derzeit verwendet das System einen einfachen Mechanismus zur Benutzererkennung. Um die tatsächliche Benutzer-ID aus dem Security-System zu verwenden, passen Sie die Methode `getCurrentUserName()` in `src/Service/AuditLogger.php` an:

```php
use Symfony\Bundle\SecurityBundle\Security;

public function __construct(
    private EntityManagerInterface $entityManager,
    private RequestStack $requestStack,
    private Security $security // Neu hinzufügen
) {}

private function getCurrentUserName(): string
{
    $user = $this->security->getUser();

    if ($user) {
        return $user->getUserIdentifier();
    }

    return 'system';
}
```

## Performance-Überlegungen

- Audit-Logs werden in einer separaten Transaktion gespeichert
- Indizes optimieren Abfragen
- Für sehr große Datenmengen kann eine Archivierungsstrategie implementiert werden
- Erwägen Sie regelmäßige Bereinigung sehr alter Logs (z.B. > 7 Jahre)

## Best Practices

1. **Regelmäßige Überprüfung**: Überprüfen Sie die Audit-Logs regelmäßig auf ungewöhnliche Aktivitäten
2. **Backup**: Sichern Sie die Audit-Logs separat von den Anwendungsdaten
3. **Retention**: Definieren Sie eine klare Aufbewahrungsrichtlinie gemäß gesetzlicher Anforderungen
4. **Monitoring**: Implementieren Sie Alarme für kritische Ereignisse
5. **Training**: Schulen Sie Mitarbeiter über die Bedeutung von Audit-Logs

## Fehlerbehebung

### Logs werden nicht erstellt

Überprüfen Sie:

1. Ist der `AuditLogSubscriber` registriert?
2. Ist die Entität in der `shouldAudit()`-Methode aufgeführt?
3. Gibt es Datenbankfehler im Log?

### Performance-Probleme

Bei Performance-Problemen:

1. Überprüfen Sie die Indizes
2. Implementieren Sie Paginierung
3. Archivieren Sie alte Logs
4. Optimieren Sie Datenbankabfragen

## Support und Weiterentwicklung

Für Fragen oder Verbesserungsvorschläge zum Audit Logging System:

- Erstellen Sie ein Issue im Repository
- Kontaktieren Sie das Entwicklerteam
- Konsultieren Sie die Symfony- und Doctrine-Dokumentation
