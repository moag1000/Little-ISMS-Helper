---
name: Migration-Konsolidierung bei Pattern-Rollouts
description: Bei Multi-Entity Pattern-Rollouts EINE Migration fuer die gesamte Welle erstellen, nicht eine pro Entity. Per-Entity-Commits sind ok, aber Migration-Datei zentral.
type: feedback
originSessionId: f9f6b4bc-9a59-429d-a457-d9b45d40d0f5
---
Bei Multi-Entity Pattern-Rollouts (z.B. Tri-State Person ueber 18 Entities,
Pattern A Dual-State, Schutzbedarfsvererbung) NICHT eine Migration pro
Entity erstellen. EINE Migration fuer die ganze Welle reicht.

**Why:** Tri-State Person Rollout (Plan A+B+C) erzeugte 20 Migrationen
fuer 18 Entities. User-Feedback 2026-04-30: „braucht es wirklich 20 (!)
migrationen?". Zu Recht. 20 doctrine_migration_versions-Rows clutter,
20 separate ALTER TABLE round-trips beim Upgrade, kein realer Benefit.
Setup-Skip ist via SchemaTool sowieso migration-frei, also bringt
Granularitaet nichts. Partial-Rollback wuerde ein Pattern-Feature
ohnehin nie als „nur 5 von 18" zurueckdrehen.

**How to apply:**

- Bei Pattern-Rollouts pro Welle nur EINE Migration mit allen ALTER TABLE +
  CREATE TABLE Statements zusammen.
- Per-Entity-Commits fuer Entity/Form/Translation-Code OK (Git-Blame bleibt
  sauber); Migration-Datei wird zum Schluss in einem Final-Task zentral
  generiert via `php bin/console doctrine:migrations:diff` oder von Hand
  geschrieben.
- Implementer-Subagent-Prompt fuer Welt-Tasks: explizit instruieren
  „NICHT mit eigenem Migration-File pro Entity arbeiten — stattdessen
  Entity-Code committen, Migration kommt im Final-Task".
- Plan-Templates: Migration-Schritt nur EINMAL im Plan, nicht pro
  Entity-Task wiederholen.
- Bei parallelen Subagents (was wir wegen Skill-Rule eh nicht machen)
  vermeiden Timestamp-Clashes — single Final-Task ist deterministisch.

**Ausnahme:** Wenn ein Pattern-Rollout schemaaendernd-irreversibel ist
(z.B. column rename mit Daten-Migration), dann pro Entity eine Migration
weil Backfill-Schritte entity-spezifisch sein koennen. Tri-State Person
war additiv (nullable FK + leere Join-Tables) — also Welle-Migration
voll OK gewesen.

Wurzel-Komplikation 2026-04-30: bereits gepushte 20 Migrationen sind
nicht mehr konsolidierbar ohne destruktive force-push + Schema-Reset.
Lesson: Migration-Konsolidierung ist BEFORE-Push-Disziplin.
