---
name: feedback_local_test_env_modules
description: "Lokale Controller-Tests scheitern massenhaft, wenn config/active_modules.yaml nicht der .dist entspricht — Modul-Gating leitet aufs Dashboard um."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-31T16:23:37.536Z
---

`config/active_modules.yaml` ist **gitignored** und enthaelt lokal typischerweise
nur eine kleine Auswahl (hier 9 Module gegen 36 in der `.dist`). Fehlen Module,
gated `ModuleConfigurationService` die Seite und der Controller antwortet mit
302 auf `/dashboard`. Der Test meldet dann ein nacktes
„Failed asserting that the Response is successful" — das sieht wie ein
Codedefekt aus, ist aber Konfiguration.

Wirkung gemessen (2026-08-31, PR #1032):
`tests/Controller` + `tests/Smoke` **99 Failures / 10 Errors** → nach
`cp config/active_modules.yaml.dist config/active_modules.yaml`
**2 Failures / 0 Errors**. Einzelne Dateien liefen zudem 3 s statt 46 s.

Vollstaendiges lokales Setup (steht jetzt auch in CLAUDE.md unter `# Testing`):
1. `.env.test.local` mit echten Credentials + DB mit `_test`-Suffix
   (siehe [[feedback_local_test_db_setup]])
2. `cp config/active_modules.yaml.dist config/active_modules.yaml`
3. `config/setup_complete.lock` muss existieren (sonst Setup-Wizard-Redirect)
4. Bei seltsamen Container-/Twig-Fehlern `rm -rf var/cache/test`

**Why:** Ich habe diese 99 Failures dreimal als „lokale Seeding-Luecke"
abgetan, ohne nachzusehen — und dadurch jede Messung an dieser Suite
verwaessert (u. a. eine angebliche Regression von 99 auf 196, die keine war).
Die Voraussetzung stand nur im CI-Yaml, nirgends in der Doku.

**How to apply:** Bei Massen-Failures in Controller-Tests IMMER zuerst
`active_modules.yaml` gegen `.dist` diffen, bevor man Code verdaechtigt.
Fehlerbild „302 auf /dashboard" ist die Signatur. Und: Zahlenvergleiche
zwischen Testlaeufen sind wertlos — Namenslisten diffen
([[feedback_run_it_dont_assume]]).
