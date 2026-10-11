---
name: Alva-Hint Foundation
description: Architektur und Konventionen für proaktive in-App-Hint-Cards via AlvaHintService.
type: project
originSessionId: 51278c80-8f53-4e22-ae77-1c110a277764
---
Alva-Hint = proaktive UI-Cards die "hey, wir haben da was..." zuflüstern. Foundation komplett (Sprint A bis F + Follow-ups), 17 Rules live in 14 Modulen.

**Why:** Data-Reuse-Prinzip + UX ohne Bruch. Bündelt regulatorische Pflicht-Hinweise (DSGVO 72h, Drittland), Audit-Gap-Closer (BSI 3.6, ISO Cl. 10.1) und Effizienz-Vorschläge (Re-Consent, BCP-Test) in einem Service. Pro Page-Render max 1 Hint, Tier-1-Pflicht-Hinweise sind nicht-dismissible.

**How to apply:**

Neue Hint-Rule hinzufügen:
1. Klasse extends `AbstractAlvaHintRule` (default Tier 3, no module gate) implementiert `key()/appliesTo()/build()`. Auto-discovery via `_instanceof: App\AlvaHint\AlvaHintRuleInterface tags: ['alva.hint_rule']` in `config/services.yaml`.
2. Tier-Konventionen: 1 = Pflicht (Bußgeld-Hebel, non-dismissible per Constructor-Validation), 2 = Audit-Lücke, 3 = Effizienz.
3. `requiredModules()` liefert Modul-Keys (`'compliance'`, `'bcm'`, etc.) — Service skipt Rule wenn Modul inaktiv.
4. `requiredRoles` im DTO filtert per `Security::isGranted` — verhindert Action-Buttons ohne Berechtigung.
5. Bei Logik-Änderung `version` im `AlvaHint`-Constructor bumpen → Token wird `key@version`, alte Dismissals greifen automatisch nicht mehr.

Show-Template-Integration:
```twig
{% import '_components/_fa_alva_hint.html.twig' as _fa_alva_hint %}
{% set hint = alva_hint(entity) %}
{% if hint %}{{ _fa_alva_hint.render(hint) }}{% endif %}
```

Persistenz:
- `AlvaHintDismissal` (user + tenant + key + entity_type + entity_id + dismissed_until) für Cross-Device-Snooze
- `AlvaHintRenderCount` (tenant + key + render_count) via INSERT...ON DUPLICATE KEY UPDATE für Telemetrie

Stats-Dashboard: `/admin/alva-hint/stats` (ROLE_ADMIN) zeigt Renders/Dismissals/Snooze/Distinct-Users/Dismiss-Rate per Hint-Key + Chart.

Translations: domain `alva`, keys folgen `<module>.<rule_key>.{title,body,action}` — DTO setzt `translationDomain: 'alva'` per default.

Test-Pattern: pro Rule eine `*RuleTest.php` — buildEntity + 3-5 appliesTo-Cases (true/false-Permutationen). 76 Cases insgesamt grün.

Mood-Bus: Macro setzt `data-alva-mood`, Stimulus emittiert auf `window.alvaBus.emit({mood, ttlMs:8000})` on connect, `'happy'` (3s) on dismiss-success.
