# Migration Guide - Corporate Structure Management

## Überblick

Diese Anleitung beschreibt die Migration von der alten `governance_model` Spalte in der `tenant` Tabelle zur neuen `corporate_governance` Tabelle.

---

## Pre-Migration Checklist

### 1. Backup erstellen

**WICHTIG:** Erstelle immer ein Backup vor der Migration!

```bash
# Datenbank-Backup
php bin/console doctrine:schema:dump > backup_schema_$(date +%Y%m%d).sql
mysqldump -u username -p database_name > backup_data_$(date +%Y%m%d).sql

# Code-Backup
git tag -a pre-corporate-migration-$(date +%Y%m%d) -m "Before corporate structure migration"
```

### 2. Bestehende Daten prüfen

```sql
-- Prüfe aktuelle governance_model Daten
SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN governance_model IS NOT NULL THEN 1 END) as with_governance,
    COUNT(CASE WHEN parent_id IS NOT NULL THEN 1 END) as with_parent
FROM tenant;

-- Zeige alle Tenants mit Governance
SELECT id, code, name, governance_model, parent_id
FROM tenant
WHERE governance_model IS NOT NULL;
```

### 3. Inkonsistenzen beheben

```sql
-- Finde Tenants mit Governance aber ohne Parent (sollte nicht sein)
SELECT id, code, name, governance_model, parent_id
FROM tenant
WHERE governance_model IS NOT NULL
  AND parent_id IS NULL;

-- Finde Tenants mit Parent aber ohne Governance (normal, wird migriert)
SELECT id, code, name, governance_model, parent_id
FROM tenant
WHERE parent_id IS NOT NULL
  AND governance_model IS NULL;
```

---

## Migration durchführen

### Schritt 1: Migrations-Status prüfen

```bash
php bin/console doctrine:migrations:status
```

### Schritt 2: Migration ausführen

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

**Oder interaktiv:**

```bash
php bin/console doctrine:migrations:migrate
```

**Erwartete Ausgabe:**

```
Migrating up to DoctrineMigrations\Version20250113000002
++ migrating Version20250113000002
   -> CREATE TABLE corporate_governance...
   -> CREATE INDEX...
   -> ALTER TABLE corporate_governance ADD CONSTRAINT...
   -> INSERT INTO corporate_governance...
   -> ALTER TABLE tenant DROP COLUMN governance_model
++ migrated (took 1234ms, used 20M memory)
```

### Schritt 3: Verifizierung

```sql
-- Prüfe neue Tabelle
SELECT COUNT(*) FROM corporate_governance;

-- Prüfe dass governance_model Spalte weg ist
SHOW COLUMNS FROM tenant LIKE 'governance_model';
-- Sollte leer sein

-- Prüfe migrierte Daten
SELECT
    t.code,
    t.name,
    cg.scope,
    cg.governance_model,
    p.name as parent_name
FROM tenant t
JOIN corporate_governance cg ON t.id = cg.tenant_id
JOIN tenant p ON cg.parent_id = p.id
ORDER BY t.code;
```

---

## Troubleshooting

### Problem 1: Migration schlägt fehl - Tabelle existiert bereits

**Fehlermeldung:**
```
Table 'corporate_governance' already exists
```

**Lösung:**

```bash
# Option A: Migration-Tabelle existiert aber ist leer
php bin/console doctrine:query:sql "DROP TABLE IF EXISTS corporate_governance"
php bin/console doctrine:migrations:migrate

# Option B: Migration war teilweise erfolgreich
# Prüfe ob Daten vorhanden sind
php bin/console doctrine:query:sql "SELECT COUNT(*) FROM corporate_governance"

# Wenn Daten vorhanden: Migration als ausgeführt markieren
php bin/console doctrine:migrations:version --add Version20250113000002
```

### Problem 2: Foreign Key Constraint Fehler

**Fehlermeldung:**
```
Cannot add foreign key constraint
```

**Ursache:** Parent-IDs referenzieren nicht-existierende Tenants

**Lösung:**

```sql
-- Finde kaputte Referenzen
SELECT t.id, t.code, t.name, t.parent_id
FROM tenant t
LEFT JOIN tenant p ON t.parent_id = p.id
WHERE t.parent_id IS NOT NULL
  AND p.id IS NULL;

-- Bereinige kaputte Referenzen
UPDATE tenant
SET parent_id = NULL
WHERE parent_id NOT IN (SELECT id FROM tenant);

-- Dann Migration erneut ausführen
```

### Problem 3: Datenverlust - governance_model Daten fehlen

**Symptom:** Nach Migration sind keine Governance-Rules vorhanden

**Diagnose:**

```sql
-- Prüfe ob governance_model Spalte noch existiert
SHOW COLUMNS FROM tenant LIKE 'governance_model';

-- Wenn Spalte noch da ist: Migration nicht vollständig
-- Wenn Spalte weg aber keine Daten: Migration-Check fehlgeschlagen
```

**Lösung - Manuelle Datenmigration:**

```sql
-- Restore governance_model Spalte aus Backup
ALTER TABLE tenant ADD COLUMN governance_model VARCHAR(20) DEFAULT NULL;

-- Import aus Backup (anpassen an dein Backup)
-- Dann Migration erneut ausführen
php bin/console doctrine:migrations:migrate
```

---

## Rollback

### Automatischer Rollback

```bash
# Zurück zur vorherigen Version
php bin/console doctrine:migrations:migrate prev

# Oder zu spezifischer Version
php bin/console doctrine:migrations:migrate Version20250112000001
```

**Was passiert:**

1. `governance_model` Spalte wird wieder zur `tenant` Tabelle hinzugefügt
2. Default-Governance-Rules werden zurück in `tenant.governance_model` migriert
3. `corporate_governance` Tabelle wird gelöscht

**⚠️ ACHTUNG:** Granulare Rules (mit `scopeId`) gehen beim Rollback **verloren**!

### Manueller Rollback mit Datenrettung

```sql
-- Schritt 1: Backup der granularen Rules
CREATE TABLE corporate_governance_backup AS
SELECT * FROM corporate_governance
WHERE scope != 'default' OR scope_id IS NOT NULL;

-- Schritt 2: Rollback ausführen
-- (siehe automatischer Rollback oben)

-- Schritt 3: Nach Rollback granulare Rules wiederherstellen
-- (erfordert erneute Migration zur neuen Version)
```

---

## Post-Migration Tasks

### 1. Cache leeren

```bash
php bin/console cache:clear
php bin/console doctrine:cache:clear-metadata
php bin/console doctrine:cache:clear-result
```

### 2. Tests ausführen

```bash
# Unit Tests
php bin/phpunit

# Functional Tests
php bin/phpunit tests/Functional/CorporateStructureTest.php

# Manual Test
# - Öffne /admin/tenants/corporate-structure
# - Prüfe ob alle Hierarchien korrekt angezeigt werden
# - Teste Set-Parent-Funktion
# - Teste Granulare Governance
```

### 3. Monitoring

**Erste 24h nach Migration:**

- [ ] Logs auf Fehler prüfen: `tail -f var/log/prod.log`
- [ ] Performance monitoren (Ladezeiten)
- [ ] User-Feedback sammeln

---

## Datenmigration - Spezialfälle

### Fall 1: Bestehende governance_model Spalte ist NULL

**Situation:** Tenant hat Parent, aber kein governance_model

**Migration-Verhalten:**
- Wird **nicht** migriert (WHERE Clause filtert NULL-Werte)
- Muss **nach Migration manuell** gesetzt werden

**Lösung:**

```bash
# Via UI: Tenant-Detail-Seite öffnen → Update Governance

# Via API:
curl -X PATCH http://localhost/api/corporate-structure/governance-model/2 \
  -H "Content-Type: application/json" \
  -d '{"governanceModel":"shared"}'
```

### Fall 2: Mehrere Tenants mit gleichem Parent

**Situation:** 5 Subsidiaries unter 1 Parent

**Migration-Verhalten:**
- Jeder Subsidiary bekommt eigene Default-Governance-Rule
- Alle Rules können unterschiedliche Modelle haben
- Granulare Rules können nachträglich hinzugefügt werden

### Fall 3: Tiefe Hierarchien (3+ Ebenen)

**Situation:** GrandParent → Parent → Child

**Migration-Verhalten:**
- Jede Ebene bekommt eigene Default-Rule
- Vererbung funktioniert kaskadierend
- `getRootParent()` findet oberste Ebene

**Test:**

```php
$child = $tenantRepository->find(10);
$root = $child->getRootParent(); // Sollte GrandParent sein
```

---

## Performance-Optimierung nach Migration

### Index-Analyse

```sql
-- Prüfe Index-Nutzung
EXPLAIN SELECT cg.*
FROM corporate_governance cg
WHERE cg.tenant_id = 1;

-- Sollte INDEX verwenden: IDX_9815E5739033212A
```

### Query-Performance

```sql
-- Langsame Queries identifizieren
SELECT * FROM mysql.slow_query_log
WHERE sql_text LIKE '%corporate_governance%'
ORDER BY query_time DESC
LIMIT 10;
```

### Caching aktivieren

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        result_cache_driver:
            type: pool
            pool: doctrine.result_cache_pool
        query_cache_driver:
            type: pool
            pool: doctrine.query_cache_pool
```

---

## Häufige Fragen (FAQ)

### Q: Werden bestehende Parent-Child-Beziehungen beibehalten?

**A:** Ja, die `parent_id` Spalte in `tenant` bleibt unverändert.

### Q: Was passiert mit Tenants ohne Parent?

**A:** Sie bleiben standalone und bekommen keine Governance-Rules.

### Q: Können Governance-Rules nachträglich hinzugefügt werden?

**A:** Ja, über UI oder API jederzeit möglich.

### Q: Gehen Daten beim Rollback verloren?

**A:** Nur granulare Rules (mit scopeId). Default-Rules werden zurückmigriert.

### Q: Wie lange dauert die Migration?

**A:** Ca. 1 Sekunde pro 1000 Tenants (abhängig von Hardware).

---

## Support-Checkliste

Wenn du Hilfe brauchst, stelle folgende Informationen bereit:

- [ ] Symfony Version: `php bin/console --version`
- [ ] PHP Version: `php -v`
- [ ] Doctrine Version: `composer show doctrine/orm`
- [ ] Migration-Status: `php bin/console doctrine:migrations:status`
- [ ] Fehler aus Logs: `tail -100 var/log/prod.log`
- [ ] SQL-Dump der betroffenen Tabellen
- [ ] Schritte zur Reproduktion

---

**Version:** 1.0.0
**Letzte Aktualisierung:** 2025-01-13
