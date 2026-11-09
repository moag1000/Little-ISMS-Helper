# Audit Logging — Kurzüberblick

Das Audit-Log ist fest eingebaut; es gibt nichts einzurichten außer optional dem Signaturschlüssel.

1. Optional `APP_AUDIT_HMAC_KEY` setzen (≥ 32 Byte Zufall, z. B. `openssl rand -hex 32`) — dann wird
   jeder Eintrag signiert und verkettet.
2. Einträge ansehen: **Admin → Audit-Log** (`/admin/audit-log`, `ROLE_ADMIN`).
3. Aufbewahrung einstellen: `/admin/audit-log/retention` (Standard 730 Tage, Minimum 365).

Protokolliert werden Create/Update/Delete der Kern-Entitäten automatisch (`AuditLogSubscriber`),
dazu Exporte, Importe, Bulk-Aktionen, Lifecycle-Übergänge und Admin-Aktionen über den `AuditLogger`.

Alles Weitere — erfasste Felder, Schwärzung, Methoden, Filter, Compliance-Bezüge:
[AUDIT_LOGGING.md](AUDIT_LOGGING.md).
