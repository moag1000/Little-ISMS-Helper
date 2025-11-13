# Rollback Guide: Tenant Multi-Tenancy Changes

Dieser Guide beschreibt, wie Sie die tenant_id Migration und alle zugehörigen Änderungen rückgängig machen können.

## Schneller Rollback (Komplett)

Wenn Sie **alle Änderungen** rückgängig machen möchten:

```bash
# 1. Migrationen zurückrollen
php bin/console doctrine:migrations:migrate prev --no-interaction

# 2. ISMSContext Entity auf ursprünglichen Zustand zurücksetzen
git checkout main -- src/Entity/ISMSContext.php

# 3. Cache clearen
php bin/console cache:clear
php bin/console cache:pool:clear doctrine.result_cache_pool
php bin/console cache:pool:clear doctrine.system_cache_pool

# 4. Auf vorherigen Branch wechseln
git checkout main
```

## Schrittweiser Rollback

### Schritt 1: Migration zurückrollen

Die Migration `Version20251113130000` kann mit dem `down()`-Befehl rückgängig gemacht werden:

```bash
# Einzelne Migration zurückrollen
php bin/console doctrine:migrations:execute --down Version20251113130000

# Oder: Zur vorherigen Version zurück
php bin/console doctrine:migrations:migrate prev --no-interaction
```

**Was passiert:**
- Entfernt `tenant_id` Spalten aus allen 31 Tabellen
- Entfernt Foreign Keys
- Entfernt Indexes

**Verifizieren:**
```bash
# Prüfe eine Beispiel-Tabelle
mysql -u <user> -p <database> -e "DESCRIBE isms_objective;"
# Die Spalte tenant_id sollte NICHT mehr vorhanden sein
```

### Schritt 2: ISMSContext Entity Status prüfen

Die `ISMSContext` Entity wurde temporär geändert (Commit `4bbd630`). Prüfen Sie den aktuellen Status:

```bash
# Zeige Änderungen
git diff main src/Entity/ISMSContext.php
```

**Option A: Auf ursprünglichen Zustand zurücksetzen**
```bash
git checkout main -- src/Entity/ISMSContext.php
```

**Option B: Commit reverten**
```bash
git revert 4bbd630
```

### Schritt 3: Cache clearen

```bash
php bin/console cache:clear --env=prod
php bin/console cache:pool:clear doctrine.result_cache_pool
php bin/console cache:pool:clear doctrine.system_cache_pool
```

### Schritt 4: Webserver neu starten (optional)

```bash
sudo systemctl restart apache2
# oder
sudo systemctl restart nginx
sudo systemctl restart php-fpm
```

## Partielle Rollbacks

### Nur bestimmte Tabellen rückgängig machen

Wenn Sie nur bestimmte Tabellen rückgängig machen möchten:

```sql
-- Beispiel: isms_objective Tabelle
ALTER TABLE isms_objective DROP FOREIGN KEY FK_isms_objective_tenant;
DROP INDEX IDX_isms_objective_tenant ON isms_objective;
ALTER TABLE isms_objective DROP COLUMN tenant_id;
```

### ISMSContext Entity manuell wiederherstellen

Falls das Script nicht funktioniert, manuell in `src/Entity/ISMSContext.php` ändern:

1. **Uncomment import:**
   ```php
   use App\Entity\Tenant;  // Zeile 6: // entfernen
   ```

2. **Uncomment property:**
   ```php
   #[ORM\ManyToOne(targetEntity: Tenant::class)]
   #[ORM\JoinColumn(nullable: true)]
   private ?Tenant $tenant = null;
   ```

3. **Uncomment methods:**
   ```php
   public function getTenant(): ?Tenant
   {
       return $this->tenant;
   }

   public function setTenant(?Tenant $tenant): static
   {
       $this->tenant = $tenant;
       return $this;
   }
   ```

4. **Entfernen Sie TODO-Kommentare**

## Automatischer Rollback mit Script

### Vorab-Check durchführen

```bash
# Prüfe Migration-Status
php bin/console doctrine:migrations:status

# Prüfe welche Tabellen tenant_id haben
mysql -u <user> -p <database> <<'SQL'
SELECT TABLE_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'tenant_id';
SQL
```

### Rollback-Script erstellen

Erstellen Sie `rollback_tenant_migration.sh`:

```bash
#!/bin/bash
set -e

echo "🔄 Starting tenant migration rollback..."

# 1. Migrationen zurückrollen
echo "1️⃣ Rolling back migrations..."
php bin/console doctrine:migrations:execute --down Version20251113130000 --no-interaction

# 2. ISMSContext Entity zurücksetzen
echo "2️⃣ Restoring ISMSContext entity..."
if [ -f "src/Entity/ISMSContext.php.bak" ]; then
    cp src/Entity/ISMSContext.php.bak src/Entity/ISMSContext.php
else
    git checkout main -- src/Entity/ISMSContext.php
fi

# 3. Cache clearen
echo "3️⃣ Clearing cache..."
php bin/console cache:clear --env=prod
php bin/console cache:pool:clear doctrine.result_cache_pool
php bin/console cache:pool:clear doctrine.system_cache_pool

echo "✅ Rollback complete!"
echo ""
echo "📝 Next steps:"
echo "   1. Restart web server"
echo "   2. Test application"
echo "   3. If needed: git checkout main"
```

Dann ausführen:
```bash
chmod +x rollback_tenant_migration.sh
./rollback_tenant_migration.sh
```

## Troubleshooting

### Migration kann nicht zurückgerollt werden

**Fehler:** `Migration ... was not found`

**Lösung:**
```bash
# Prüfe welche Migrationen ausgeführt wurden
php bin/console doctrine:migrations:status

# Rollback zur spezifischen Version
php bin/console doctrine:migrations:migrate <PREVIOUS_VERSION> --no-interaction
```

### Foreign Key Constraints verhindern Rollback

**Fehler:** `Cannot drop column 'tenant_id': needed in a foreign key constraint`

**Lösung:**
```bash
# Foreign Keys manuell entfernen
mysql -u <user> -p <database> <<'SQL'
-- Für jede betroffene Tabelle
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE isms_objective DROP FOREIGN KEY FK_isms_objective_tenant;
ALTER TABLE isms_objective DROP COLUMN tenant_id;
SET FOREIGN_KEY_CHECKS = 1;
SQL
```

### Cache-Probleme nach Rollback

**Symptom:** Fehler wie "Unknown column" oder "Class not found"

**Lösung:**
```bash
# Komplettes Cache-Clearing
rm -rf var/cache/*
php bin/console cache:warmup --env=prod

# OPcache leeren (falls aktiv)
sudo systemctl restart php-fpm
```

### ISMSContext Entity im falschen Zustand

**Symptom:** Fehler beim Zugriff auf ISMSContext

**Lösung:**
```bash
# Von main-Branch wiederherstellen
git checkout main -- src/Entity/ISMSContext.php

# Cache clearen
php bin/console cache:clear
```

## Nach dem Rollback

### Verifizierung

```bash
# 1. Doctrine Schema prüfen
php bin/console doctrine:schema:validate

# 2. Migrations-Status prüfen
php bin/console doctrine:migrations:status

# 3. Datenbank prüfen
mysql -u <user> -p <database> -e "SHOW TABLES;"

# 4. Application testen
curl -I https://your-domain.com/
```

### Commit-Historie

Nach erfolgreichem Rollback:

```bash
# Falls Sie den Branch behalten möchten
git checkout -b backup/tenant-migration

# Zurück zu main
git checkout main

# Oder: Branch löschen
git branch -D claude/admin-portal-review-011CV4VjhDpeSMneFoUvvKxL
```

## Support

Bei Problemen während des Rollbacks:

1. **Sichern Sie die Datenbank:**
   ```bash
   mysqldump -u <user> -p <database> > backup_before_rollback.sql
   ```

2. **Dokumentieren Sie Fehler:**
   ```bash
   php bin/console doctrine:migrations:execute --down Version20251113130000 2>&1 | tee rollback.log
   ```

3. **Prüfen Sie den Status:**
   ```bash
   php bin/console doctrine:migrations:status > status.txt
   mysql -u <user> -p <database> -e "SELECT * FROM migration_versions;" >> status.txt
   ```

## Wichtige Hinweise

⚠️ **ACHTUNG:**
- Rollbacks sollten nur in nicht-produktiven Umgebungen durchgeführt werden
- Erstellen Sie immer ein Datenbank-Backup vor dem Rollback
- Testen Sie den Rollback zuerst in einer Test-Umgebung
- Informieren Sie Benutzer über mögliche Ausfallzeiten

✅ **EMPFEHLUNG:**
- Führen Sie den Rollback während Wartungsfenstern durch
- Halten Sie die Migration-Historie (Backup-Branch erstellen)
- Dokumentieren Sie, warum der Rollback notwendig war
- Testen Sie die Anwendung vollständig nach dem Rollback
