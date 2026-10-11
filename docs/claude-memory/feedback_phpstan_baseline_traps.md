---
name: feedback_phpstan_baseline_traps
description: PHPStan-Baseline nie pauschal regenerieren (verwirft 8.4-only-Einträge) und nie nach /tmp generieren (absolute Pfade brechen CI); stale Result-Cache meldet 0 Treffer.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-17T20:20:17.850Z
---

Drei Fallen, alle 2026-08-17 in PR #1027 real eingetreten:

**1. `--generate-baseline` pauschal = CI-Bruch.** Regeneration lokal (PHP 8.5)
verwarf ~70 Einträge, die nur auf dem 8.4-CI-Runner auftreten → 770 auf 700.
Die wären auf CI unbaselined gewesen. Genau davor warnt der Kommentar
`reportUnmatchedIgnoredErrors: false` in `phpstan.dist.neon`.
**Richtig:** neue Blöcke chirurgisch an die bestehende Baseline anhängen,
Zahl vorher/nachher vergleichen (770 → 782 = nur die eigenen 12).

**2. Baseline nach `/tmp` generieren = absolute Pfade.** PHPStan schreibt Pfade
relativ zu der Datei, die es anlegen soll. Bei `--generate-baseline /tmp/x.neon`
entstehen `path: /private/tmp/<worktree>/src/...`. Lokal passt das (gleicher
Checkout), auf dem Runner (`/home/runner/work/...`) nie → Baseline greift nicht,
Befunde schlagen als neue Fehler durch. Bestehende Einträge sind repo-relativ
(`path: src/...`) — daran orientieren.

**3. Stale Result-Cache meldet 0 Treffer.** Nach Änderung einer Rule-Klasse
unter `tests/PHPStan/Rule/` (nicht in `paths:`) cached PHPStan die alten
Ergebnisse und meldet fälschlich „nichts gefunden". `vendor/bin/phpstan
clear-result-cache` vor jeder Rule-Messung. Erst danach kamen die echten
7 Treffer.

**Why:** Alle drei sehen wie „grün" bzw. „sauber" aus und sind es nicht — die
gefaehrlichste Fehlerform, weil sie Sicherheit vortaeuschen.

**How to apply:** Bei jeder neuen PHPStan-Regel: Baseline anhaengen statt
regenerieren, Pfadformat gegen bestehende Eintraege pruefen, Result-Cache
leeren vor dem Messen, und die Regel per injiziertem Verstoss gegenpruefen
(sonst weiss man nicht, ob sie ueberhaupt feuert). Siehe
[[feedback_run_it_dont_assume]], [[feedback_ci_gate_on_success]].

Bestehende Custom-Rules: `NoDirectSetStatusRule` (25 Lifecycle-Entities),
`NoNewWorkflowOrWorkflowStepRule`, `NoUnscopedTenantQueryInBackgroundRule`
(#1027) — alle unter `tests/PHPStan/Rule/`, verdrahtet in `phpstan.dist.neon`.
