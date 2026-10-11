---
name: feedback_backup_enum_roundtrip
description: Backup/Restore Enum-Felder brechen nur über echten JSON-File-Pfad; in-memory-Tests maskieren es; TypeError ≠ Exception.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 5f89d394-84e9-4154-94a3-bf677b9fc53d
---

Doctrine `enumType:`-Spalten (Risk.status, Incident.status/severity, User.menuDensity,
AssetDependency*, CorporateGovernance, Tenant.status) hydrieren als BackedEnum.
BackupService serialisiert das Enum-Objekt → beim Schreiben als JSON-Datei kollabiert
es zum Backing-Scalar (`"identified"`). Restore wies den Scalar direkt auf die
Enum-Property → **TypeError**.

**Why:** `TypeError` ist `\Error`, NICHT `\Exception` → ein `catch (Exception)` verfehlt
ihn (gleiche Wurzel wie [[feedback_controller_500_traps]]). Der Fehler eskalierte zur
Row-Ebene → ganze Row gedroppt (best-effort) bzw. kompletter Restore-Rollback (strict).
Quasi jeder echte datei-basierte Restore verlor Status/Severity. Fix in #882:
`hydrateScalarFields()` coerced Scalar→Enum via `tryFrom()` (unbekannt→Default+Warn),
`resolveEnumType()` liest enumType vom FieldMapping, catch auf `\Throwable` erweitert,
BackupService kollabiert Enum→Scalar schon bei Serialisierung.

**How to apply:** (1) Bei Backup/Restore/Serialisierung von Entities IMMER Enum-Felder
explizit scalar↔Enum konvertieren. (2) Round-Trip-Tests MÜSSEN durch JSON
(`json_encode`→`json_decode`) gehen — in-memory-Tests behalten das Enum-Objekt und
maskieren den Bug komplett. (3) In Restore/Hydration-Catch-Blöcken `\Throwable` statt
`\Exception`. (4) Backup-Vollständigkeit ist by-construction
(`getClassMetadata()->getFieldNames()` erfasst alle Felder); fehlende Felder in alten
Backups = reine Vorwärts-Schema-Evolution, restore-graceful via PHP-Default. Gate 43
erzwingt Entity-Typ-Coverage (120/120).
