---
name: Sprint 1-7 Reapply Incident
description: Linter/Rector hat Sprint-1-7 Entity-Felder stillschweigend revertiert; manuell reappliziert via Commit 18063f1a am 2026-04-18.
type: project
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Fakt:** Am 2026-04-18 musste Commit `18063f1a` "re-apply all reverted Sprint 1-7 changes" nachträglich manuelle Reapply der Sprint-1-7 Entity-Felder durchführen (`absicherungsStufe`/`anforderungsTyp` auf ComplianceRequirement, `mbco` auf BusinessProcess, `businessContinuityPhase`, Supplier-BCM-Felder). Ursache: Linter/Rector-Durchlauf hatte Felder gelöscht.

**Why:** `rector.php` im Projekt aggressiv; entfernt ggf. "unbenutzte" Entity-Felder wenn keine Getter/Setter-Referenzen im Commit-Scope sichtbar. Risiko bei künftigen Refactor-Runs erneut.

**How to apply:**
- Vor Rector-Runs prüfen ob Entity-Felder in `src/Command/Load*RequirementsCommand` oder Service-Layer referenziert werden
- Neue Entity-Felder stets mit Getter+Setter+Repository-Nutzung in einem Commit einführen
- Nach Linter-Durchläufen `git diff src/Entity/` explizit auf verlorene `#[ORM\Column]` prüfen
- Bei Verdacht auf stille Entity-Änderung: `git log --all --diff-filter=D -- src/Entity/` als Detektion
