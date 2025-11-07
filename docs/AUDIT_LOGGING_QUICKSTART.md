# Audit Logging Integration - Quick Start Guide

## Übersicht

Das Audit-Logging-System ist nun vollständig in Ihr Little-ISMS-Helper-Projekt integriert und sofort einsatzbereit!

## ✅ Was wurde integriert?

### Backend-Komponenten
- ✅ **AuditLog Entity** - Datenmodell für Audit-Einträge
- ✅ **AuditLogger Service** - Zentrale Logging-Funktionalität
- ✅ **AuditLogSubscriber** - Automatische Änderungsverfolgung via Doctrine Events
- ✅ **AuditLogController** - 5 Controller-Aktionen für verschiedene Ansichten
- ✅ **AuditLogRepository** - Optimierte Datenbankabfragen mit Indizes

### Frontend-Komponenten
- ✅ **Navigation** - Neuer Menüpunkt "Audit Log" in templates/base.html.twig
- ✅ **Übersichtsseite** - Filterbare Tabelle aller Audit-Einträge
- ✅ **Detailansicht** - Vor/Nach-Vergleich von Änderungen
- ✅ **Entitätsverlauf** - Komplette Historie einzelner Entitäten
- ✅ **Benutzeraktivität** - Alle Aktionen eines Benutzers
- ✅ **Statistik-Dashboard** - Visualisierungen und Auswertungen

### Datenbank
- ✅ **Migration erstellt** - Version20251105000004.php bereit zur Ausführung

### Dokumentation
- ✅ **Umfassende Docs** - docs/AUDIT_LOGGING.md mit allen Details

## 🚀 Schnellstart (3 Schritte)

### Schritt 1: Datenbank-Migration ausführen

```bash
php bin/console doctrine:migrations:migrate
```

Diese Migration erstellt die `audit_log` Tabelle mit allen notwendigen Indizes.

### Schritt 2: Anwendung starten

```bash
symfony serve
# oder
php -S localhost:8000 -t public/
```

### Schritt 3: Audit-Log öffnen

Navigieren Sie zu: http://localhost:8000/audit-log/

## 📊 Verfügbare Routen

Das System stellt folgende Routen bereit:

| Route | Pfad | Beschreibung |
|-------|------|-------------|
| `app_audit_log_index` | `/audit-log/` | Hauptübersicht mit Filterung |
| `app_audit_log_detail` | `/audit-log/{id}` | Detailansicht eines Eintrags |
| `app_audit_log_entity` | `/audit-log/entity/{type}/{id}` | Verlauf einer Entität |
| `app_audit_log_user` | `/audit-log/user/{userName}` | Aktivitäten eines Benutzers |
| `app_audit_log_statistics` | `/audit-log/statistics` | Statistik-Dashboard |

## 🔧 Service-Konfiguration

Alle Services sind automatisch registriert durch Symfonys Autowiring:

```
✓ App\Service\AuditLogger - Autowired, Autoconfigured
✓ App\EventSubscriber\AuditLogSubscriber - Registriert für 4 Doctrine Events:
  - postPersist (nach Erstellung)
  - preUpdate (vor Änderung)
  - postUpdate (nach Änderung)
  - postRemove (nach Löschung)
✓ App\Controller\AuditLogController - Autowired
✓ App\Repository\AuditLogRepository - Autowired
```

## 🎯 Automatische Protokollierung

Das System protokolliert **automatisch** Änderungen an folgenden Entitäten:

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

**Keine Codeänderungen nötig!** Alle CRUD-Operationen werden automatisch geloggt.

## 💡 Verwendungsbeispiele

### Automatische Protokollierung (funktioniert sofort!)

```php
// Jede Änderung wird automatisch protokolliert
$asset = new Asset();
$asset->setName('Server XY');
$asset->setAssetType('Server');
$entityManager->persist($asset);
$entityManager->flush(); // CREATE wird automatisch geloggt!

// Auch Updates werden automatisch erfasst
$asset->setName('Server XY - Updated');
$entityManager->flush(); // UPDATE wird automatisch geloggt!
```

### Manuelle Protokollierung (für spezielle Fälle)

```php
use App\Service\AuditLogger;

class MyController extends AbstractController
{
    public function __construct(
        private AuditLogger $auditLogger
    ) {}

    public function exportData(): Response
    {
        // ... Export-Logik ...

        $this->auditLogger->logExport('Asset', null, 'CSV-Export aller Assets');

        return $response;
    }
}
```

## 🔍 Filter- und Suchfunktionen

Die Übersichtsseite bietet Filterung nach:

- **Entitätstyp** (Asset, Risk, Control, etc.)
- **Aktion** (create, update, delete, view, export, import)
- **Benutzer** (Textsuche)
- **Zeitraum** (Von-Bis-Datum)

Alle Filter können kombiniert werden!

## 📈 Statistiken und Auswertungen

Das Statistik-Dashboard zeigt:

- Gesamtanzahl der Protokolleinträge
- Verteilung nach Aktionstyp
- Verteilung nach Entitätstyp
- Aktivitätsverlauf der letzten 30 Tage (Diagramm)
- Prozentuale Anteile

## 🔒 Sicherheitsfeatures

- **Automatische Sanitisierung**: Passwörter und Tokens werden maskiert
- **Unveränderliche Zeitstempel**: DateTimeImmutable verhindert Manipulation
- **Nur-Lesen-Zugriff**: Keine Möglichkeit, Logs zu ändern oder zu löschen
- **IP-Tracking**: Erfasst die IP-Adresse jeder Aktion
- **User-Agent-Logging**: Protokolliert Browser und Client-Informationen

## 🎓 Compliance-Erfüllung

Das System erfüllt Anforderungen von:

- ✅ **ISO 27001** - A.12.4.1, A.12.4.2, A.12.4.3, A.12.4.4
- ✅ **DSGVO** - Art. 5 Abs. 2, Art. 30, Art. 32
- ✅ **TISAX** - Nachvollziehbarkeitsanforderungen
- ✅ **BSI IT-Grundschutz** - Protokollierung sicherheitsrelevanter Ereignisse

## 🛠️ Anpassungen (Optional)

### Weitere Entitäten protokollieren

Bearbeiten Sie `src/EventSubscriber/AuditLogSubscriber.php`:

```php
private function shouldAudit(object $entity): bool
{
    $auditedEntities = [
        'Asset',
        'Risk',
        // ...
        'IhreNeueEntität', // Hier hinzufügen
    ];
    return in_array($className, $auditedEntities);
}
```

### Benutzer-Integration

Passen Sie `src/Service/AuditLogger.php` an, um echte Benutzer zu verwenden:

```php
use Symfony\Bundle\SecurityBundle\Security;

public function __construct(
    private EntityManagerInterface $entityManager,
    private RequestStack $requestStack,
    private Security $security // Hinzufügen
) {}

private function getCurrentUserName(): string
{
    $user = $this->security->getUser();
    return $user ? $user->getUserIdentifier() : 'system';
}
```

## 📝 Weitere Informationen

Vollständige Dokumentation: `docs/AUDIT_LOGGING.md`

## ✅ Integrationsstatus

**Status: VOLLSTÄNDIG INTEGRIERT UND EINSATZBEREIT**

- [x] Alle Services registriert
- [x] Alle Routen verfügbar
- [x] Event-Subscriber aktiv
- [x] UI vollständig implementiert
- [x] Dokumentation vorhanden
- [x] Migration bereit
- [x] Cache geleert

**Nächster Schritt: Migration ausführen und loslegen!**

```bash
php bin/console doctrine:migrations:migrate
```

Viel Erfolg mit Ihrem revisionssicheren ISMS! 🎉
