---
name: Schutzbedarfsvererbung umgesetzt
description: BSI-Plan 3.6 Maximumprinzip entlang Asset-Dependencies — umgesetzt 2026-04-18.
type: project
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Fakt:** BSI 3.6 Schutzbedarfsvererbung ist umgesetzt:
- `Asset.dependsOn` Self-ManyToMany (`asset_dependencies` Join-Tabelle)
- `Asset.dependentAssets` inverse
- `AssetDependencyService::calculateInheritedProtectionNeed(Asset)` walks upstream graph breadth-first, cycle-safe, returns max CIA + driver-asset pro Dimension
- `AssetType` FormType: `dependsOn` EntityType (multiple, TomSelect)
- Migration `Version20260418160000.php` (idempotent)

**How to apply:** Bei Fragen zu Asset-Schutzbedarf den Service nutzen. UI-Widget ("geerbter Schutzbedarf höher als eigener") kann via `AssetDependencyService::hasInheritedIncrease()` aufgeführt werden. Noch offen: Templates zeigen Werte.
