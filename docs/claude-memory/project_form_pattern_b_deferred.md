---
name: Form Pattern B TomSelect umgesetzt
description: FORM_UX Pattern B (6 Native-Multi-Selects auf TomSelect) wurde 2026-04-18 umgesetzt.
type: project
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Fakt:** FORM_UX Pattern B ist umgesetzt. 6 Felder nutzen `data-controller="tom-select"`:
- `IncidentType.affectedAssets`
- `BusinessProcessType.supportingAssets`, `identifiedRisks`
- `ControlType.protectedAssets`
- `TrainingType.coveredControls`, `complianceRequirements`

**Implementation:**
- `tom-select` + Dependencies via `importmap:require` in importmap.php
- `assets/controllers/tom_select_controller.js` (Stimulus) mit remove_button-Plugin für multi-selects, Turbo-aware, maxOptions=500
- CSS via `tom-select/dist/css/tom-select.default.min.css` import im Controller

**How to apply:** Neue Multi-Selects mit >20 Optionen bekommen `'attr' => ['data-controller' => 'tom-select']` im FormType. Controller initialisiert automatisch.
