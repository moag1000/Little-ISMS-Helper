---
name: Targeted Tests statt Full Suite
description: Agents sollen nur betroffene Tests laufen lassen, nicht die gesamte Suite (2850+). TaskStop nutzen wenn Agent Full Suite startet.
type: feedback
originSessionId: caac3636-fc90-4b50-8d9c-695d3d4b66af
---
Agents und Sub-Agents sollen bei Verifikation NICHT `php bin/phpunit` komplett laufen lassen (dauert 30-50s, 2850+ Tests). Stattdessen gezielt testen:

- Nur die Tests die zu geänderten Dateien gehören (z.B. `tests/Service/FooServiceTest.php` wenn `src/Service/FooService.php` geändert)
- Entity-Tests wenn Entity-Felder geändert wurden
- Controller-Tests nur wenn Controller-Logik geändert wurde
- Full Suite nur am Ende eines gesamten Feature-Blocks, nicht pro Agent

**Why:** Spart Token, Zeit, und Context-Window. Full Suite ist nur am Ende relevant, nicht bei jedem Zwischen-Schritt. User hat einen Agent gestoppt der die Full Suite starten wollte — das war Verschwendung.

**How to apply:** In Agent-Prompts explizit sagen: "Run only affected tests, NOT the full suite. Example: `php bin/phpunit tests/Service/FooTest.php tests/Entity/BarTest.php`". Falls ein Agent trotzdem Full Suite startet: `TaskStop` nutzen.
