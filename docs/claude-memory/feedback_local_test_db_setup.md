---
name: feedback_local_test_db_setup
description: Lokale phpunit-DB-Tests brauchen .env.test.local plus _test-Suffix-DB; .env.local wird im Test-Env ignoriert.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-14T08:51:44.277Z
---

DB-gebundene phpunit-Tests laufen lokal nur mit eigener `.env.test.local` —
Symfony ignoriert `.env.local` im Test-Env, daher greifen sonst die Default-
Credentials (`app@localhost`) und alles endet in `Access denied`. Zusaetzlich
haengt phpunit `_test` an den DB-Namen: bei `DB_NAME=lih_verify` braucht es
`lih_verify_test`, sonst 100+ Errors mit `Unknown database`.

Rezept:
1. `.env.test.local` mit echten Credentials anlegen (ist git-ignored).
2. `php bin/console --env=test doctrine:database:create`
3. `php bin/console --env=test doctrine:schema:update --force --complete`

**Why:** Ohne das sehen alle DB-Tests nach Massen-Fehlschlag aus, obwohl der
Code gesund ist — kostet sonst mehrere Debug-Runden an der falschen Stelle.

**How to apply:** Fehlerbild `Access denied` oder `Unknown database '*_test'`
sofort als Env-Problem lesen, nicht als Regression. Danach Baseline-Vergleich
mit zurueckgedrehtem Patch, um echte von umgebungsbedingten Failures zu trennen
(siehe [[feedback_run_it_dont_assume]], [[feedback_ci_test_discipline]]).

`DeploymentWizardControllerTest` schlaegt in einer voll installierten Test-DB
by-design fehl (6 Failures) — braucht eine nicht-installierte Instanz.
