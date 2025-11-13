# Deployment-Anleitung für Login-Fix

## Problem
Admin-Login funktioniert nicht wegen fehlender `user_sessions` Tabelle.

## Lösung 1: Code-Änderungen deployen (Empfohlen)

```bash
# 1. Neueste Änderungen vom Branch pullen
git pull origin claude/fix-extension-port-errors-01DodgcmLQ1Hqsys8zoCLFhT

# 2. Dependencies aktualisieren (falls nötig)
composer install --no-dev --optimize-autoloader

# 3. Cache leeren
php bin/console cache:clear --env=prod
# oder für Docker:
docker-compose exec php bin/console cache:clear --env=prod

# 4. Jetzt sollte Login funktionieren!
```

## Lösung 2: Migration ausführen (Für vollständiges Session-Management)

Wenn Sie das vollständige Session-Management-Feature nutzen möchten:

```bash
# Migration ausführen um user_sessions Tabelle zu erstellen
php bin/console doctrine:migrations:migrate --no-interaction
# oder für Docker:
docker-compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

## Was wurde gefixt?

Die Änderungen in `src/Service/SecurityEventLogger.php` fügen try-catch Blöcke hinzu,
sodass Login/Logout auch funktionieren, wenn die `user_sessions` Tabelle nicht existiert.

**Vorher:** Exception → Login schlägt fehl
**Nachher:** Exception wird geloggt → Login funktioniert trotzdem

## Überprüfung

Nach dem Deployment sollten Sie sich normal einloggen können.
Session-Tracking-Fehler werden in den Logs erscheinen, aber den Login nicht blockieren.
