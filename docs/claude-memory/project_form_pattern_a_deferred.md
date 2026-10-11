---
name: Form Pattern A Dual-State Owner umgesetzt
description: 7 Free-Text-Owner auf User-Relation im Dual-State umgezogen, inkl. Backfill-Migration 2026-04-19.
type: project
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Fakt:** FORM_UX Pattern A ist umgesetzt (Dual-State). 7 Entities haben zusätzlich zum Legacy-String jetzt eine optionale `ManyToOne User`-Relation:
- `Asset.ownerUser`, `BusinessContinuityPlan.planOwnerUser`, `BusinessProcess.processOwnerUser`
- `Control.responsiblePersonUser`, `Incident.reportedByUser`, `Risk.acceptanceApprovedByUser`, `Training.trainerUser`

**Implementation:**
- Commit `f27689af` — Entities + Forms + Migration `Version20260418150000` (neue `_user_id` Spalten)
- Commit `6b626be6` — `getEffectiveX()` Helper Fix (User.fullName ?? Legacy-String)
- Migration `Version20260419120000` — Backfill per Name/Email-Match zu `users` Tabelle (tenant-scoped, case-insensitive), nicht-destruktiv
- Templates (asset/show/index/bcm/qr_label, business_process/*, bcm/index, incident-preview, training/show) nutzen `effectiveX`

**Noch offen (nicht dringend):**
- Legacy-String-Felder entfernen nach Stabilisierungsphase (Breaking Change — eigener Sprint)
- Admin-Report "Nicht-gematchte Legacy-Strings" auflisten für manuelle Zuordnung

**How to apply:** Beim Anzeigen von Owner-Informationen `entity.effectiveX` nutzen. Bei neuen FormTypes EntityType(User) bevorzugen; Legacy-String nur für externe Personen (kein User-Account).
