# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records for Little ISMS Helper using the
[MADR format](https://adr.github.io/madr/) (Markdown Any Decision Record).

## What is an ADR?

An ADR records a significant architectural decision: the context that led to it, the decision
itself, and the consequences (positive and negative). ADRs are immutable once accepted —
if a decision changes, a new ADR supersedes the old one. This creates an audit trail of
*why* the codebase looks the way it does.

## Index

| # | Title | Status |
|---|---|---|
| [0001](0001-symfony-7-4-lts-pin.md) | Symfony 7.4 LTS Pin | Accepted |
| [0002](0002-multi-tenant-tenant-id-pattern.md) | Multi-Tenancy via `tenant_id` + Doctrine Filter | Accepted |
| [0003](0003-fairyaurora-v4-design-system.md) | FairyAurora v4 Custom Design System | Accepted |
| [0004](0004-yaml-workflows-vs-db-workflows.md) | YAML as Source of Truth for Regulatory Workflows | Accepted |
| [0005](0005-agpl-v3-licensing.md) | AGPL-3.0 Copyleft Licensing | Accepted |
| [0006](0006-shared-hosting-async-job-runner.md) | Shared-Hosting Async Job Runner | Accepted |
| [0007](0007-module-gating-pattern.md) | 40+ Module Gating Architecture | Accepted |
| [0008](0008-quality-gates-baseline-ratchet.md) | 48 CI Quality Gates with Baseline Ratchet | Accepted |
| [0009](0009-persona-driven-dashboards.md) | Dedicated Per-Persona Dashboard Templates | Accepted |
| [0010](0010-tisax-byo-vs-pre-seeded.md) | TISAX BYO-Import Wizard (vs Pre-Seeded Controls) | Accepted |
| [0011](0011-hmac-chained-audit-log.md) | HMAC-Chained Tamper-Evident Audit Log | Accepted |
| [0012](0012-godclass-baseline-ratchet.md) | God-Class Baseline Ratchet | Accepted |

## Related Decisions

The `docs/decisions/` directory contains sprint-specific ADRs (narrower scope, implementation
details):

- [2026-05-17-lifecycle-state-machine.md](../decisions/2026-05-17-lifecycle-state-machine.md)
- [2026-05-17-workflow-yaml-unification.md](../decisions/2026-05-17-workflow-yaml-unification.md)
- [2026-05-23-capa-canonical-process.md](../decisions/2026-05-23-capa-canonical-process.md)
- [2026-05-27-nonconformity-modeling.md](../decisions/2026-05-27-nonconformity-modeling.md)
- [2026-05-27-tenant-organization-terminology.md](../decisions/2026-05-27-tenant-organization-terminology.md)

## Adding a New ADR

1. Copy the template below.
2. Number it sequentially (`0013-…`).
3. Fill in all sections.
4. Open a PR — ADRs are reviewed like code.
5. Once merged to `main`, do not edit the ADR — create a new one that supersedes it.

### MADR Template

```markdown
# ADR-XXXX: Title

**Status:** Draft | Accepted | Superseded by ADR-YYYY  
**Date:** YYYY-MM-DD  
**Deciders:** name(s)  
**Tags:** tag1, tag2

---

## Context

[Describe the situation and forces that led to this decision]

## Decision

[Describe the decision made]

## Consequences

### Positive

- …

### Negative

- …

## References

- file path or URL
```
