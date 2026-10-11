---
name: feedback_controller_500_traps
description: "Harte 500 in Controllern — protected getEntityManager() extern + catch(Exception) fängt kein \\TypeError/\\Error. EM injizieren, catch(\\Throwable) bei AJAX."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

Zwei wiederkehrende Quellen für **harte 500** in Controllern (gehit PR #840, ComplianceMappingAdminController):

1. **`$repo->getEntityManager()` extern aufgerufen** — diese Doctrine-Methode ist **protected** (auch via ServiceEntityRepository). Extern aufrufen = `\Error: Call to protected method` → deterministischer 500 sobald die Zeile erreicht wird. **Fix:** `EntityManagerInterface $em` in den Konstruktor injizieren, `$this->em` nutzen. NIE `$repo->getEntityManager()`.

2. **`catch (Exception $e)` fängt KEIN `\TypeError`/`\Error`** (die extends `\Error`, nicht `\Exception`). Ein `getId() on null` (orphaned Relation) oder der protected-Call oben escapen → harter HTML-500. **Fix bei AJAX/JsonResponse-Endpoints:** `catch (\Throwable $e)` → immer JSON-500, nie harter 500 der das Frontend (batch fetch) bricht. Plus null-guards vor `->getId()` auf nullable Relationen (auch wenn FK `nullable:false` — orphaned Daten möglich).

**Why:** AJAX-Batch-Loops erwarten JSON; ein harter 500 bricht sie (+ Browser-Extensions werfen dann Folge-Errors wie `renderAjaxRequests null.style` — NICHT unser Bug).

**Test-Env-Falle (dabei gelernt):** PHPUnit/WebTestCase rendert manchmal **stale kompilierte Twig** → Markup-Assertions failen obwohl Source korrekt. Vor funktionalen Tests die Markup prüfen: `rm -rf var/cache/test`. War transient, kein Code-Bug. [[feedback_native_form_silent_block]] [[feedback_gate26_line_baseline_shift]]
