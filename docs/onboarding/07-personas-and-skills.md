# 07 — Personas and Skills

## Overview

The project uses two related but distinct "persona" concepts:

1. **RBAC persona-roles** — application-level roles that gate access to
   persona-specific dashboards and features within the running application.
2. **Claude persona-skills** — AI-assistant personas stored in `.claude/skills/`
   that simulate how a human in that role would react to a proposed change.

---

## RBAC Persona-Roles (Application Level)

Six persona-roles exist alongside the main privilege hierarchy:

| Role | Dashboard route | Typical holder |
|---|---|---|
| `ROLE_CISO` | `/dashboards/ciso` | Chief Information Security Officer |
| `ROLE_RISK_MANAGER` | `/dashboards/risk-manager` | Risk Management lead |
| `ROLE_DPO` | `/dashboards/dpo` | Data Protection Officer |
| `ROLE_COMPLIANCE_MANAGER` | `/dashboards/compliance-manager` | Head of GRC |
| `ROLE_ISB` | `/dashboards/isb` | Informationssicherheitsbeauftragter |
| `ROLE_BCM_OFFICER` | `/dashboards/bcm-officer` | Business Continuity Manager |

These roles are additive — a user can hold multiple persona-roles. The dashboards
aggregate KPIs, pending approvals, and action items relevant to each role.

### Assigning persona-roles

Via the admin panel: `/admin/users/{id}/roles` — tick the persona checkboxes.

Via console (for demo/test users):
```bash
php bin/console app:create-screenshot-user
# Creates one user per persona with appropriate roles
```

### Persona-gated features

Some features are visible only when the user holds a specific persona-role:

```php
// Controller check (trait method)
if (!$this->isGranted('ROLE_CISO')) {
    throw $this->createAccessDeniedException();
}
```

```twig
{# Template check #}
{% if is_granted('ROLE_DPO') %}
    {# GDPR Art. 37 DPO workspace #}
{% endif %}
```

---

## Claude Persona-Skills (AI Feedback)

Located in `.claude/skills/persona-*/`, these skills allow the AI assistant to
simulate how a specific role would evaluate a proposed feature or change.

### Available Persona-Skills

| Skill name | Simulated role |
|---|---|
| `persona-ciso-executive` | CISO evaluating strategic security impact |
| `persona-compliance-manager` | Head of GRC reviewing framework coverage and data reuse |
| `persona-isb-practitioner` | Operational ISB reviewing norm compliance |
| `persona-risk-owner-business` | Business-side risk owner assessing operational impact |
| `persona-auditor-external` | External ISO 27001 auditor reviewing evidence quality |
| `persona-implementer-junior` | Junior implementer with IT/9001 background |
| `persona-consultant-senior` | Senior GRC consultant with cross-tool experience |
| `persona-tool-tester` | QA perspective on UX and reliability |

### Invoking a Persona-Skill

Use the Skill tool (or `/` command syntax in the Claude Code CLI):

```
/persona-isb-practitioner
```

Then describe the change you want reviewed. The skill responds in character,
typically in German, citing specific ISO clauses, compliance concerns, or
usability issues that the persona would raise.

### When to Use Persona-Skills

Use persona-skills for:

- **Pre-PR review** of new features that affect compliance workflows
- **UX decisions** that impact end-users with compliance responsibilities
- **Framework additions** — ask the compliance-manager persona about data-reuse
  opportunities before implementing
- **Documentation drafts** — the junior-implementer persona reveals gaps in
  clarity that experts miss

### Multi-Agent Persona Review Pattern

For significant changes, dispatch parallel persona-audits:

1. Start three separate Claude Code sessions (or use `superpowers:dispatching-parallel-agents`).
2. Each session adopts a different persona-skill.
3. Each session reviews the same PR diff independently.
4. Collect feedback, resolve conflicts, address blockers before merging.

Example parallel dispatch:
```
Session A: /persona-ciso-executive  → strategic + risk view
Session B: /persona-isb-practitioner → operational norm compliance
Session C: /persona-auditor-external → evidence and audit trail quality
```

This pattern catches 80% of domain-specific issues before human review.

---

## Specialist Skills

Beyond persona-skills, several specialist skills provide deep domain knowledge:

| Skill | Domain |
|---|---|
| `isms-specialist` | ISO 27001:2022, BaFin, EU-DORA, NIS2 |
| `bsi-specialist` | BSI IT-Grundschutz 200-1/2/3/4, BSI C5:2020/2026 |
| `ux-specialist` | WCAG 2.2 AA, FairyAurora v4, Bootstrap 5.3, Stimulus |
| `risk-management-specialist` | ISO 27005 risk management methodology |
| `bcm-specialist` | ISO 22301 business continuity |
| `dpo-specialist` | GDPR, BDSG, Art. 37 DPO obligations |
| `pentester-specialist` | Application security review |

These activate automatically on keyword detection (e.g. typing "ISO 27001" or
"BSI IT-Grundschutz" triggers the relevant specialist).

---

## Memory and House Rules

The `.claude/` directory also contains project memory files that persist
across sessions:

| Path | Contents |
|---|---|
| `.claude/MEMORY.md` (via user memory) | Learned patterns, feedback, project decisions |
| `.claude/skills/` | Persona and specialist skill definitions |
| `CLAUDE.md` (project root) | Authoritative project guidelines and anti-patterns |

New contributors should read `CLAUDE.md` in full before their first PR.
It is the canonical source for coding standards, commit format, migration
rules, and the quality gate pre-commit checklist.
