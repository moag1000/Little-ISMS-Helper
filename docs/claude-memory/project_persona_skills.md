---
name: Persona-Skills für Tool-Feedback
description: 7 Persona-Skills in .claude/skills/persona-* zur Simulation realistischer Nutzerrückmeldungen auf Little-ISMS-Helper, angelegt/erweitert 2026-04-17.
type: project
originSessionId: 0e01194e-c97f-4c98-916e-8dd416392614
---
7 Persona-Skills unter `.claude/skills/persona-*/SKILL.md` angelegt für realistisches Tool-Feedback:

- `persona-implementer-junior` — Junior-Umsetzer, 9001/IT-Background, normunsicher
- `persona-isb-practitioner` — ISB, norm- und praxisfest, compliance-getrieben (Detail-Ebene)
- `persona-compliance-manager` — Head of GRC / Compliance-Manager, dem CISO unterstellt, Effizienz + Effektivität + Business-Compliance, Data-Reuse-obsessiv, Framework-Portfolio (27001 → NIS2/DORA/TISAX), denkt in FTE-Tagen, Consultant-affin (holt Methodik, setzt intern um)
- `persona-ciso-executive` — CISO, Risk/Cost/Benefit, Management-Sicht, Budget (€)
- `persona-auditor-external` — Externer ISO-19011-Auditor, evidence-fokussiert
- `persona-consultant-senior` — GRC-Berater, Markt-Benchmark, Multi-Framework
- `persona-risk-owner-business` — Fachbereichsleiter, non-IT, Business-Sprache

**Why:** User will realistisches Feedback zum Little-ISMS-Helper aus unterschiedlichen Rollen- und Erfahrungsebenen, nicht nur fachliche Expertise. Rollenpyramide organisatorisch: CISO → Compliance-Manager → ISB → Implementer. Risk-Owner quer dazu, Auditor/Consultant extern.

**How to apply:** Bei Trigger-Formulierungen wie "aus dem Blickwinkel eines …", "als CISO/ISB/Compliance-Manager/GRC-Lead/Auditor/…" entsprechende Persona via Skill-Tool aktivieren. Personas ergänzen sich mit vorhandenen Domain-Skills (isms-/dpo-/bcm-/risk-management-specialist), ersetzen sie nicht.

**Abgrenzung `persona-compliance-manager` vs verwandte Rollen:**
- vs ISB-Practitioner: ISB detail-/control-pflege; Compliance-Manager Portfolio/Cross-Framework-Steuerung.
- vs CISO: CISO strategisch/Euro/Board; Compliance-Manager taktisch-operativ/FTE-Tage/Monat-Quartal.
- vs Consultant-Senior: Consultant extern/Benchmark/Vorlagen; Compliance-Manager intern/Umsetzung/Consultant-Konsument.
