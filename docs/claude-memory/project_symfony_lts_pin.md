---
name: Symfony auf 7.4 LTS gepinnt
description: Symfony bleibt vorerst auf 7.4 LTS — keine 8.0-Updates ohne expliziten Auftrag.
type: project
originSessionId: 0e01194e-c97f-4c98-916e-8dd416392614
---
Symfony bleibt vorerst auf 7.4 LTS. Keine 8.0-Bumps ohne expliziten Auftrag — auch nicht "nebenbei" beim Updaten anderer Pakete (composer require muss `^7.4` Constraint behalten).

**Why:** 7.4 ist LTS bis 2028. 8.0-Sprung ist breaking (typed properties, return types, removed deprecations). Aktuell gibt es kein Business-Need für 8.0, und die Migration würde umfangreiche Test-Adjustments erfordern.

**How to apply:**
- Bei `composer outdated` Major-Bumps von Symfony-Paketen ignorieren
- Andere Major-Bumps (PHPUnit, Doctrine, Chart.js etc.) sind separat zu bewerten und betreffen das Pin nicht
- Sicherheits-Patches innerhalb 7.4.x sind immer OK
- Falls 8.0 doch geplant wird: separater Worktree, eigener PR
