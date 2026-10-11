---
name: feedback_inrequest_runner_failonwarning
description: "In-request job runner runs SYNC under test SAPI; phpunit failOnWarning fails CI on \"Undefined array key\"; phpstan shape-change cascades through consumers."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 6854d1c7-c141-43e2-b8fd-bd0170dbdcad
---

Drei zusammenhängende Fallen, die beim QuickFix-Schema-Hardening (PR #927) je einen CI-Roundtrip kosteten:

**1. In-request job runner läuft im Test SYNCHRON.** Unter CLI/test-SAPI fällt `InRequestJobRunner` auf synchrone Ausführung zurück (kein `fastcgi_finish_request`). Jeder Functional-Test, der einen Job triggert (z.B. POST `/quick-fix/repair/all`), führt die Job-`run()` INLINE aus. Job, der ein Subprozess spawnt (mysqldump für DB-Snapshot) oder schwere Arbeit macht → Test hängt 0% CPU bis Process-Timeout. → Mutating-Jobs, die externe Tools aufrufen, per `%kernel.environment%`-Gate im `test`-Env zu No-Op machen. War KEIN Regress meinerseits am Hang von `QuickFixControllerTest::testRepairAllChains` (identisch auf origin/main reproduziert — pre-existing in-request-runner-Artefakt; CI grün in eigener Env), aber Snapshot-mysqldump hätte es verschärft.

**2. phpunit `failOnWarning=true` → "Undefined array key" killt CI** (Job grün-mit-Notices lokal, aber Job-Exit non-zero). Return-Array-Shapes präziser typen + defensive `?? default` entfernen ist gefährlich: Lock-aware Pfade (`withSchemaLock` liefert `{success,blocked,error}` OHNE `executed`/`statements_executed`) und Test-Mocks liefern PARTIAL arrays. Direkter Key-Zugriff ohne `??` → Undefined-key-Warning zur Laufzeit → CI rot. → Lock-aware Result-Keys `optional` halten (`success?`, `executed?`, `statements_executed?`, `blocked?`) + defensive `?? `-Reads behalten. **PHPStan level-5 grün GARANTIERT dann no-undefined-key** (optional offset gelesen ohne Guard = phpstan-Fehler), also phpstan-grün = laufzeitsicher.

**3. PHPStan Shape-Cascade.** Einen Key (z.B. `blocked?`) zu einem SHARED `@return array{...}` hinzufügen reworded den Shape-String → ALLE baselined `nullCoalesce.offset`-Einträge in JEDEM Consumer (SchemaExceptionSubscriber, ExecutePendingMigrationsJob, ...) matchen die Baseline nicht mehr → als NEU gemeldet → rot. Repo hat `phpstan-baseline.neon` + config toleriert unmatched-Einträge aber failt auf NEUE. Lösung die ohne Baseline-Churn auskommt: Keys optional machen → defensive `??` in Consumern wird gültig (kein Fehler) statt "always exists". Voller `--generate-baseline`-Regen ist riskant (lokal PHP 8.5 vs CI 8.4 → version-abhängige Einträge driften). Siehe [[feedback_controller_500_traps]].

Lehre: bei Linter-revert im Working-Tree (mehrfach „modified by linter" reminder) — HEAD/Commit ist Truth, `git checkout HEAD -- <file>` restored. Verifizieren via gerendertem phpstan/test, nicht grep.
