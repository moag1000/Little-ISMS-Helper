---
name: ISO 27001 Zertifizierungs-Readiness
description: Stand der ISO-27001-Zertifizierungs-Blocker aus dem Persona-Audit 2026-04-18. Aktualisiert 2026-04-19.
type: project
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Fakt:** Alle drei Blocker aus dem Persona-Audit 2026-04-18 sind aufgelöst. Stand 2026-04-19:

1. **C-02 Multi-Tenancy-Bug** — widerlegt. `ComplianceFramework` ist absichtlich globale Reference-Entity (siehe Inline-Kommentar `ComplianceFrameworkController.php:80`). Tenant-Aktivierung läuft über `ComplianceFrameworkActivationService`. Kein Fix.
2. **H-01 AuditFinding/CorrectiveAction** — umgesetzt:
   - `src/Entity/{AuditFinding,CorrectiveAction}.php` + Repos + Migration `Version20260418130000` (Commit `01e30c36`)
   - CRUD-UI: `src/Controller/{AuditFinding,CorrectiveAction}Controller.php` + Forms + Templates (Commit `4845a9dd`)
   - Routen `/audit-finding/*` und `/corrective-action/*`, Tenant-Isolation + AuditLogger-Einbindung
3. **H-04 ISO 27001 Clauses 4-10** — umgesetzt:
   - Seeder `app:load-iso27001-clauses` (28 Requirements: Context, Leadership, Planning, Support, Operation, Performance Evaluation, Improvement) in `LoadIso27001ClausesCommand.php` (Commit `9ec4018d`)
   - `ManagementReview` + `ISMSObjective` Entities existierten bereits und sind nun gemappt

**Why:** Zertifizierungs-Readiness ist damit auf Tool-Ebene hergestellt; offen bleibt nur operative Umsetzung (Daten erfassen, Management-Review durchführen, Findings abarbeiten) durch den Betreiber.

**How to apply:** Bei Readiness-Fragen: Tool-Side sagt "bereit", Datenbestand im jeweiligen Tenant ist zu prüfen (z.B. `app_audit_finding_index` + KPI `implementation_readiness`).
