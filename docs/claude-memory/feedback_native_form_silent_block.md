---
name: feedback_native_form_silent_block
description: "Plain POST-Form mit required + off-screen invalid → HTML5 blockt Submit lautlos. novalidate + JS-Guard nutzen, der :invalid sichtbar markiert."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

Plain server-rendered POST-Form mit `required`-Controls: wenn ein Pflichtfeld invalid UND nicht fokussierbar ist (off-screen, lange Tabelle, collapsed-Block), blockt natives HTML5 den Submit **lautlos** — Submit-Event feuert gar nicht, kein JS kann eingreifen, Button „tut nix". Hit im policy-wizard Bestandsaufnahme (PR #810 + #833).

**Why:** native constraint validation kann die Bubble nicht an einem unfokussierbaren Feld zeigen → silent block.

**How to apply:** `novalidate` auf die Form + Stimulus-Guard (`wizard_form_guard_controller.js`): on submit `querySelectorAll(':invalid')` (deckt required + Format trotz novalidate), jedes markieren (`.is-invalid` + Zeile), zum ersten scrollen + fokussieren + `window.faToast` mit Anzahl. Button reagiert IMMER + User sieht was fehlt. Zusätzlich server-seitig: select-Default gegen die GÜLTIGE Options-Menge prüfen (`not in [...]`), nicht nur `== ''` — ein persistierter Legacy-Wert ohne matchende `<option>` rendert sonst leeres Placeholder = required-invalid.

**Bonus-Lehre:** Translation-Keys oben in eine `<domain>.yaml` einfügen verschiebt baselined Keys → trippt **Gate 26** (translation_nesting, Line-Baseline) UND **Gate 44** (translation_issues). Plus neue `|trans` brauchen expliziten Domain-Param (Gate 44), `trans_default_domain` reicht dem Gate nicht. Beide Baselines via `--write-baseline` regenerieren bzw. Domain ergänzen. [[feedback_gate26_line_baseline_shift]] [[feedback_css_import_barrel_fouc]]
