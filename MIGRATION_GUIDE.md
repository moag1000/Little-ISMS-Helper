# Migration Guide: Tenant Multi-Tenancy Setup

## Problem

Die Anwendung zeigt Fehler wie:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 't0.tenant_id' in 'SELECT'
```

Dies passiert, weil 31 Entities `tenant` Associations definiert haben, aber die Datenbank-Spalten `tenant_id` noch nicht existieren.

## Lösung

### Schritt 1: Code aktualisieren

```bash
git pull origin claude/admin-portal-review-011CV4VjhDpeSMneFoUvvKxL
```

### Schritt 2: Migrationen ausführen

**Wichtig:** Die umfassende Migration `Version20251113130000` fügt `tenant_id` zu allen 31 Tabellen hinzu.

```bash
# Migrationen ausführen
php bin/console doctrine:migrations:migrate --no-interaction

# Cache clearen
php bin/console cache:clear --env=prod

# Doctrine Metadata Cache clearen
php bin/console cache:pool:clear doctrine.result_cache_pool
php bin/console cache:pool:clear doctrine.system_cache_pool
```

### Schritt 3: Prüfen ob Migration erfolgreich war

```bash
# Prüfe eine Beispiel-Tabelle
mysql -u <user> -p <database> -e "DESCRIBE isms_objective;"
```

Die Ausgabe sollte eine Spalte `tenant_id` enthalten:

```
+------------------+--------------+------+-----+---------+----------------+
| Field            | Type         | Null | Key | Default | Extra          |
+------------------+--------------+------+-----+---------+----------------+
| ...              | ...          | ...  | ... | ...     | ...            |
| tenant_id        | int          | YES  |     | NULL    |                |
+------------------+--------------+------+-----+---------+----------------+
```

### Schritt 4: ISMSContext Entity reparieren

Nach erfolgreicher Migration muss die temporäre Änderung in `src/Entity/ISMSContext.php` rückgängig gemacht werden:

```php
// VORHER (auskommentiert):
// TODO: Re-enable after migration Version20251113120000 is successfully executed
// use App\Entity\Tenant;
// ...
// #[ORM\ManyToOne(targetEntity: Tenant::class)]
// #[ORM\JoinColumn(nullable: true)]
// private ?Tenant $tenant = null;

// NACHHER (wieder aktiviert):
use App\Entity\Tenant;
...
#[ORM\ManyToOne(targetEntity: Tenant::class)]
#[ORM\JoinColumn(nullable: true)]
private ?Tenant $tenant = null;
```

**Oder einfach:**

```bash
git revert 4bbd630  # Revert "Temporarily disable tenant association in ISMSContext entity"
```

### Schritt 5: Finale Überprüfung

```bash
# Cache clearen
php bin/console cache:clear --env=prod

# Anwendung testen
# Die Fehler sollten verschwunden sein!
```

## Welche Tabellen werden modifiziert?

Die Migration `Version20251113130000` fügt `tenant_id` Spalten zu folgenden 31 Tabellen hinzu:

```
asset                       incident                    risk_appetite
audit_checklist             interested_party            risk_treatment_plan
bc_exercise                 internal_audit              supplier
business_continuity_plan    isms_context                threat_intelligence
business_process            isms_objective              training
change_request              location                    users
control                     management_review           vulnerabilities
crisis_teams                patches                     workflows
cryptographic_operation     person                      workflow_instances
document                    physical_access_log         workflow_steps
                            risk
```

## Troubleshooting

### Migration schlägt fehl

Wenn die Migration fehlschlägt:

1. **Prüfe, welche Tabellen existieren:**
   ```bash
   php bin/console doctrine:schema:validate
   ```

2. **Prüfe Migration-Status:**
   ```bash
   php bin/console doctrine:migrations:status
   ```

3. **Führe Migration einzeln aus:**
   ```bash
   php bin/console doctrine:migrations:execute --up Version20251113130000
   ```

### Spalte existiert bereits

Wenn die Spalte bereits existiert, wird sie automatisch übersprungen. Die Migration ist **idempotent** und kann mehrfach ausgeführt werden.

### Fehler bleiben bestehen

1. **Cache clearen:**
   ```bash
   rm -rf var/cache/*
   php bin/console cache:clear --env=prod
   ```

2. **Doctrine Metadata Cache clearen:**
   ```bash
   php bin/console cache:pool:clear doctrine.result_cache_pool
   php bin/console cache:pool:clear doctrine.system_cache_pool
   ```

3. **Webserver neu starten:**
   ```bash
   sudo systemctl restart apache2
   # oder
   sudo systemctl restart nginx
   sudo systemctl restart php-fpm
   ```

## Support

Bei Problemen bitte folgende Informationen bereitstellen:

```bash
# Migration Status
php bin/console doctrine:migrations:status

# Spalten einer betroffenen Tabelle
mysql -u <user> -p <database> -e "DESCRIBE isms_objective;"

# Doctrine Schema Validierung
php bin/console doctrine:schema:validate

# Log-Ausgabe der Migration
php bin/console doctrine:migrations:execute --up Version20251113130000 2>&1
```
