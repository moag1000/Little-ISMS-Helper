---
name: json-fields-use-builder
description: "Für jedes JSON/JsonStructured-Feld in Entities IMMER einen Builder-Pattern (Twig-Macro + Stimulus-Controller) verwenden, nie nackte JSON-Textarea-Direct-Edit."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: df58c764-60e6-489c-9ac8-c65766309351
---

JSON-strukturierte Entity-Felder bekommen IMMER einen Builder. Nie textarea-direct-JSON.

**Why:** Direct-JSON-Edit ist Junior-unfreundlich (Syntax-Errors verschlucken Daten, Validierung erst beim Speichern, kein Auto-Complete). Builder-Pattern liefert strukturierte UI, Inline-Validation, Tom-Select-Picker für References, sauberes Stimulus-Lifecycle.

**How to apply:** Beim Entity-Refactor mit JSON-Feld (z.B. `nonconformityDetails`, neue Schema-Felder) IMMER:
1. Sub-Schema definieren (TypeScript-style: 3-5 strukturierte Felder)
2. Twig-Macro `_fa_<feature>_builder.html.twig` analog `_fa_team_role_builder.html.twig` (bc_plans.team_role) oder `_fa_condition_builder.html.twig` (notification-rules)
3. Stimulus-Controller `<feature>_builder_controller.js` analog `team_role_builder_controller.js`
4. Hidden form-field für die JSON-Repräsentation; Builder serialisiert zur Submit-Time
5. Twig-Render im Show-Template gespiegelt (kein RAW-JSON anzeigen)

**Existing reference implementations:**
- `templates/_components/_fa_team_role_builder.html.twig` + `assets/controllers/team_role_builder_controller.js`
- `templates/_components/_fa_condition_builder.html.twig` + `assets/controllers/condition_builder_controller.js` + `notification_condition_builder_controller.js`
- `templates/workflow/_steps_builder.html.twig` + `assets/controllers/workflow_builder_controller.js`
- `templates/report_builder/` (gröbere Variante)

**Anti-Pattern (nicht tun):**
```twig
{# DON'T #}
<textarea name="my_form[nonconformityDetails]">{{ raw_json|json_encode }}</textarea>
```

**Memory-Trigger:** User-Feedback 2026-05-25: nach S17 B4 Agent baute nur Textarea-Approach ohne Builder, User korrigierte explizit "für json felder immer den builder benutzen".
