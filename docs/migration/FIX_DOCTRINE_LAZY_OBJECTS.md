# Fix: Symfony 7.3 Lazy Ghost Objects Deprecation

## Problem
Symfony 7.3 zeigt folgende Deprecation-Warnung:
```
User Deprecated: Since symfony/var-exporter 7.3: Using ProxyHelper::generateLazyGhost() is deprecated, use native lazy objects instead.
```

## Root Cause
**Doctrine ORM 3.x** erfordert zwingend `enable_lazy_ghost_objects: true` und kann nicht deaktiviert werden:
```
Lazy ghost objects cannot be disabled for ORM 3.
```

Die alte Implementierung (`ProxyHelper::generateLazyGhost()`) ist in Symfony 7.3 deprecated.

## Solution (Implemented)

**PHP 8.4+ verwenden mit Native Lazy Objects**

`config/packages/doctrine.yaml`:
```yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true   # Pflicht für Doctrine ORM 3
        enable_native_lazy_objects: true  # Löst Deprecation (PHP 8.4+)
```

### Warum diese Lösung?

1. ✅ **Doctrine ORM 3 Kompatibilität**: `enable_lazy_ghost_objects: true` ist Pflicht
2. ✅ **Keine Deprecation-Warnung**: Native lazy objects verwenden moderne PHP-Features
3. ✅ **Beste Performance**: PHP 8.4 native lazy objects sind optimiert
4. ✅ **Zukunftssicher**: PHP 8.4 ist seit November 2024 stable und produktionsreif

### Voraussetzungen

- **PHP 8.4+** auf allen Umgebungen (lokal, CI/CD, Produktion)
- Falls noch PHP 8.2/8.3: Upgrade auf PHP 8.4 erforderlich

## Alternative: Temporäre Lösung (nicht empfohlen)

Falls PHP 8.4 Upgrade noch nicht möglich:
```yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true
        enable_native_lazy_objects: false
```

**Nachteile:**
- ⚠️ Deprecation-Warnung bleibt bestehen (nur INFO-Level)
- ⚠️ Verwendet veraltete Implementierung
- ⚠️ Muss später trotzdem zu native lazy objects migriert werden

## Implementation History

**2025-11-11**: Native lazy objects aktiviert
```bash
git commit -m "fix: Enable native lazy objects for PHP 8.4+ (fixes deprecation)"
```

**Ergebnis:**
- Deprecation-Warnung eliminiert
- PHP 8.4 native lazy objects aktiv
- Volle Doctrine ORM 3 Kompatibilität

## Referenzen

- [Symfony 7.3 Release Notes](https://symfony.com/blog/symfony-7-3-0-released)
- [Doctrine ORM 3.0 Migration](https://www.doctrine-project.org/projects/doctrine-orm/en/3.0/reference/annotations-reference.html)
- [PHP 8.4 Lazy Objects RFC](https://wiki.php.net/rfc/lazy-objects)
