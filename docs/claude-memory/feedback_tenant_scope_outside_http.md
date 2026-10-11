---
name: feedback_tenant_scope_outside_http
description: "Doctrine tenant_filter wird NUR auf kernel.request scharf gestellt — Cron/Worker/Jobs muessen den Mandanten selbst setzen, sonst lesen sie ueber alle Tenants."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-16T11:27:45.529Z
---

`TenantFilterSubscriber` setzt den `tenant_id`-Parameter des Doctrine-
`tenant_filter` **nur auf `kernel.request`**. Ueberall ohne HTTP-Request —
Cron-Commands, Messenger-Worker, Async-Admin-Jobs — bleibt der Parameter leer,
`TenantFilter` gibt `''` zurueck und filtert NICHTS. Jedes `findAll()` /
`findBy()` liest dann ueber alle Mandanten.

Zwei belegte Vorfaelle (beide 2026-08-14/16 gefixt):

1. **Geplante Berichte** (PR #1023): `ScheduledReportService` setzte den
   Mandanten zwar via `TenantContext`, aber der Setter merkte ihn sich nur
   intern. `ManagementReportService::getExecutiveSummary()` baut aus
   `riskRepository->findAll()` → Mandant A sah 3 statt 2 Risiken.
   Fix: beide `TenantContext`-Setter schieben die ID jetzt in den Filter.
2. **Messenger-Worker** (PR #1026): `ExecuteJobHandler` fasste weder
   TenantContext noch Filter an → ExportUsersJob/ExportAnalyticsJob/
   ExportDataJob exportierten unter `APP_ASYNC_JOB_RUNNER=messenger` ueber
   alle Mandanten. Fix: `ExecuteJobMessage` traegt die tenantId (beim Dispatch
   eingefangen, solange der Request noch existiert), Handler stellt sie her.

**Why:** Der Default-Runner `in_request` maskiert das komplett — dort ist der
Filter aus dem Ursprungs-Request noch scharf. Der Defekt zeigt sich erst in
Messenger-/Cron-Deployments, also genau dort wo niemand hinschaut.

**Seit 2026-08-17 typgegated (PR #1027):**
`tests/PHPStan/Rule/NoUnscopedTenantQueryInBackgroundRule.php` meldet
`findAll()` auf tenant-behafteten Entities in `src/{Command,Job,MessageHandler}`.
Typgetrieben (Rueckgabetyp + `tenant`-Property), nicht textuell — Grep flaggt
35 Dateien Rauschen, die Regel findet 12 echte. Bewusst NUR `findAll()`:
`findBy()` mitzunehmen wurde gemessen und verworfen (7 weitere Treffer, kein
einziges Leck — alle per id/Relation gescoped oder instanzweit by design).

**How to apply:**
- Neuer Command/Job/Worker der Repositories anfasst → entweder Mandant
  explizit als Query-Parameter durchreichen (wie `CapturePortfolioSnapshotCommand`
  mit `computeCellAggregate($framework, $tenant, ...)`) ODER `TenantContext`
  setzen. Beides ist ok, „gar nichts" nicht.
- Nicht aufloesbare tenantId NIE auf den `'null'`-Sentinel zurueckfallen lassen —
  das heisst „unscoped/Instanz-Admin" und ist im Hintergrundjob eine stille
  Rechteausweitung. Lieber `TenantNotFoundException`.
- Verifikation immer empirisch mit ZWEI Mandanten, die beide eigene Daten haben —
  mit nur einem Mandanten ist Leckage unsichtbar. Siehe
  [[feedback_run_it_dont_assume]], [[feedback_local_test_db_setup]].
