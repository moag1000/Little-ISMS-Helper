# Maintainer Handoff Document

This document records everything the current maintainer holds in their head that is NOT
fully captured in code, documentation, or CI. It is written for the person taking over
maintenance — whether that is a new co-maintainer, a community contributor stepping up,
or a future self returning after a long absence.

**Last updated:** 2026-05-28  
**Current maintainer:** moag1000 (moag2000s@gmail.com)

---

## Production Deployment Topology

The reference deployment is a single-server setup:

```
Nginx (reverse proxy, TLS termination)
  └── PHP-FPM 8.4 pool (www-data, pm.dynamic, max_children=20)
      └── Symfony application (var/cache/prod, var/log/prod.log)
Database: MySQL 8.0 (local socket or managed DB service)
Object storage: local filesystem (var/uploads/, var/exports/) or S3-compatible
```

**Multi-tenant:** All tenants share one PHP process pool and one database schema. There is
no per-tenant process isolation. Tenant isolation is application-enforced (Doctrine filter +
voter). If a privilege-escalation bug allowed one tenant to read another tenant's data, the
only recovery is a code fix + DB audit. Consider this risk in any security review.

**Worker process:** By default, async jobs run in-request (`APP_ASYNC_JOB_RUNNER=in_request`).
No worker process is needed. If a customer switches to Messenger mode (`APP_ASYNC_JOB_RUNNER=messenger`),
they need a persistent `messenger:consume async` worker (systemd unit template in CLAUDE.md).

**Cron jobs recommended for production:**

```cron
# Process timed regulatory workflows (NIS2 SLA escalation, DORA 24h notifications)
*/15 * * * * /usr/bin/php /var/www/isms/bin/console app:process-timed-workflows --no-interaction

# Clean up job status files older than 7 days
0 3 * * * find /var/www/isms/var/jobs/ -name "*.json" -mtime +7 -delete

# Send scheduled compliance reports (if module enabled)
0 8 * * 1 /usr/bin/php /var/www/isms/bin/console app:send-scheduled-reports --no-interaction
```

---

## Database Backup Schedule and Restore Procedure

### Backup

The `BackupService` (`src/Service/BackupService.php`) implements application-level backup (JSON
export of all tenant data). This is NOT a replacement for a MySQL `mysqldump` backup.

**Recommended dual backup strategy:**

1. **MySQL-level backup** (infrastructure responsibility): nightly `mysqldump` with `--single-transaction`
   to a separate storage location (off-site or object storage). Retain 30 days.
2. **Application-level backup** (via Operator UI at `/quick-fix` → "Backup"): generates a
   `var/exports/backup-<date>.json.gz` file. Useful for tenant-specific restore. Retain 7 days.

### Restore procedure

**MySQL-level restore** (fastest, for disaster recovery):
```bash
mysql -u root -p isms_production < /backup/isms-2026-05-28.sql
php bin/console doctrine:migrations:migrate --no-interaction  # apply any unapplied migrations
```

**Application-level restore** (for tenant data fix, not full DR):
1. Upload backup JSON to `/quick-fix` → "Restore" tab.
2. Select the tenant to restore.
3. Review the preview (entities affected, conflict warnings).
4. Confirm restore — this overwrites tenant data to the backup snapshot.

See `src/Service/RestoreService.php` and `docs/user-guide/QUICK_FIX.md` for details.

### Critical: backup the HMAC key

`APP_AUDIT_HMAC_KEY` is the key for the HMAC-chained audit log. If you restore the database
but use a different key, the `app:audit:verify-chain` command will report chain breaks.
The HMAC key must be backed up separately from the database (store in a secrets manager,
not in the database backup file itself).

---

## Release-Please Weekly Cadence and Rollback

### Normal cadence

1. Conventional commits (`feat:`, `fix:`, `chore:`) accumulate on `main`.
2. `release-please` bot opens a PR titled "chore(main): release X.Y.Z" with the changelog.
3. On Monday 09:00 UTC, `.github/workflows/release-please-auto-merge.yml` squash-merges the
   release PR automatically.
4. CI tags `vX.Y.Z`, builds Docker image, publishes `:vX.Y.Z`, `:X.Y`, `:latest`.

### Blocking a release

Add label `release-blocked` or `do-not-merge` to the release PR before Monday 09:00 UTC.

### Forcing an urgent release

Go to GitHub Actions → "Release Please Auto-Merge" → "Run workflow" (workflow_dispatch).

### Rollback procedure

There is no automated rollback mechanism. Manual rollback:

```bash
# 1. Identify the last good tag
git log --tags --oneline | head -10

# 2. Revert the database if migration was applied
php bin/console doctrine:migrations:execute --down <migration-version>

# 3. Deploy the previous Docker image tag
docker pull ghcr.io/moag1000/little-isms-helper:v3.5.1  # previous known-good
docker-compose up -d  # or equivalent deployment command

# 4. Verify the application starts
php bin/console cache:clear --env=prod
```

For migration rollbacks: not all migrations support `down()` cleanly. Data-destructive migrations
have `down()` stubbed to throw. Check the migration file before executing `--down`.

---

## CI Access and Secrets

### GitHub Actions secrets required

| Secret name | Purpose | Where to get it |
|---|---|---|
| `APP_SECRET` | Symfony app secret (32+ hex chars) | Generate: `openssl rand -hex 32` |
| `APP_AUDIT_HMAC_KEY` | HMAC chain key for audit log | Generate: `openssl rand -base64 32` |
| `DATABASE_URL` | MySQL DSN for CI test database | CI MySQL service; see `.github/workflows/ci.yml` |
| `MAILER_DSN` | Email transport (dev: `null://`) | `null://localhost` for CI |
| `GHCR_TOKEN` | GitHub Container Registry push token | GitHub → Settings → Developer settings → PAT (write:packages) |

### Adding a new secret

1. Generate the value locally.
2. Add to GitHub repo: Settings → Secrets and variables → Actions → New repository secret.
3. Reference in `.github/workflows/ci.yml` as `${{ secrets.SECRET_NAME }}`.
4. Add to this table for the next maintainer.

### CI failure triage

Common CI failure patterns:

| Symptom | Likely cause | Fix |
|---|---|---|
| `PHPUnit: 2850 tests, N failures` | New migration not applied in fixtures | Check `tests/bootstrap.php` runs migrations |
| `lint:container error: service X not found` | New service with wrong autowiring | Check `config/services.yaml` |
| `check_twig_macro_scope.py: BROKEN` | Macro import inside `{% embed %}` missing | Add re-import inside embed block |
| `God-class LOC exceeded baseline` | File grew past ratchet | Refactor or bump baseline with justification |
| `PHPStan: X errors not in baseline` | New code introduced type errors | Fix type errors or add to baseline with justification |

---

## Domain Expertise Gaps — Wary Areas

These areas require DACH-specific or specialist regulatory knowledge that is NOT fully documented
in code comments. A new maintainer should treat these with extra caution and consult authoritative
sources before making changes.

### BSI IT-Grundschutz

- **Schutzbedarfsvererbung (Protection Need Inheritance):** BSI 200-2 §3.6 defines the Maximum
  Principle: an asset inherits the highest protection need from all assets that depend on it.
  The `AssetDependencyService` implements this. Any change to the `Asset.dependsOn` relationship
  or to `AssetDependencyService` must be validated against BSI 200-2 §3.6, not just tested for
  unit correctness.
- **BSI Baustein catalogue:** The BSI Kompendium (IT-Grundschutz-Kompendium) is updated annually.
  Baustein codes (e.g., ORP.4, SYS.1.1, APP.3.2) must match the current Kompendium edition. The
  application references BSI 200-x document numbers frequently — always verify the edition year.
- **NIS2-UmsuCG:** The German NIS2 transposition law (NIS2-Umsetzungs- und Cybersicherheitsstärkungs-
  gesetz) was not yet fully in force at the time of writing. KRITIS-related provisions have
  specific BSI notification deadlines (24h initial, 72h detailed) that differ slightly from the
  EU directive. Check the BSI website for current UmsuCG implementation status.

### VDA-ISA / TISAX Navigation

The VDA-ISA workbook has a hierarchical question structure with maturity levels 1/2/3. Maturity
level 3 questions are a superset of level 2, which is a superset of level 1. The TISAX import
wizard preserves this hierarchy. When a new VDA-ISA version is published:
1. Download the new workbook from ENX portal.
2. Compare column structure against `config/tisax_column_maps.yaml`.
3. Add a new version entry to the column map if columns changed.
4. Test with a sample import before releasing.

The TISAX assessment types (AL1/AL2/AL3, with/without prototype protection, with/without
connected cars) are separate assessment scopes. The application treats these as module flags
(`tisax`, `prototype_protection`). Do not merge these scope flags.

### DORA Level-2 RTS/ITS Interpretation

The EU DORA regulation (2022/2554) is supplemented by Level-2 regulatory technical standards
(RTS) and implementing technical standards (ITS) published by the ESAs (EBA, ESMA, EIOPA).
The most compliance-relevant for this application:
- **RTS on ICT risk management tools** (Article 15) — defines what the ICT risk register must contain
- **RTS on incident classification** (Article 18) — major incident thresholds (financial, operational)
- **ITS on incident reporting templates** (Article 20) — the specific form structure for BaFin/NCA reporting

These RTS/ITS are published in the EU Official Journal and referenced by CELEX number. The
application's DORA module implements the incident reporting workflow per these RTS but the
threshold values (e.g., "10% of clients affected") are configurable per tenant — do not hardcode
regulatory thresholds in PHP constants. BaFin may issue supplementary national guidance.

### ISO 27001:2022 Internal Audit Defensibility

The internal audit module (`src/Controller/Audits/`, `templates/audits/`) implements:
- Audit programme (ISO 27001 Cl. 9.2.1)
- Audit plan per programme
- Audit findings with NC classification (major/minor/observation)
- Corrective action tracking (CAPA)

For audit defensibility during ISO certification:
- Every audit finding must link to a specific ISO 27001:2022 clause or Annex A control.
- The CAPA must have a root cause, planned action, responsible person, and due date.
- Finding closure requires evidence upload — the `Document` entity is the evidence store.
- Audit reports must be exportable as PDF for submission to the certification body.

The `AuditFinding.type` enum (Nonconformity/Observation/Opportunity) and the separate
`CorrectiveAction` entity are documented in
[docs/decisions/2026-05-27-nonconformity-modeling.md](../decisions/2026-05-27-nonconformity-modeling.md).
This design is ISO-audit-defensible per the ADR.

---

## Authoritative Sources

### BSI

- **IT-Grundschutz-Kompendium (annual edition):**
  [https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html](https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html)
- **BSI 200-x standards (200-1/2/3/4):**
  [https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/BSI-Standards/bsi-standards_node.html](https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/BSI-Standards/bsi-standards_node.html)
- **KRITIS notification registry:** [https://www.bsi.bund.de/KRITIS](https://www.bsi.bund.de/KRITIS)

### ENX (TISAX)

- **ENX member portal:** [https://enx.com](https://enx.com) (login required for VDA-ISA workbook)
- **TISAX participant portal:** [https://portal.enx.com](https://portal.enx.com)

### EU Regulations

- **GDPR (EU 2016/679):** [https://eur-lex.europa.eu/eli/reg/2016/679/oj](https://eur-lex.europa.eu/eli/reg/2016/679/oj) — CELEX: 32016R0679
- **NIS2 (EU 2022/2555):** [https://eur-lex.europa.eu/eli/dir/2022/2555/oj](https://eur-lex.europa.eu/eli/dir/2022/2555/oj) — CELEX: 32022L2555
- **DORA (EU 2022/2554):** [https://eur-lex.europa.eu/eli/reg/2022/2554/oj](https://eur-lex.europa.eu/eli/reg/2022/2554/oj) — CELEX: 32022R2554
- **DORA Level-2 RTS/ITS:** Search EUR-Lex for "32022R2554" as base act

### ISO Standards

ISO standards require purchase. The following are the authoritative editions:
- ISO/IEC 27001:2022 — Information security, cybersecurity and privacy protection
- ISO/IEC 27002:2022 — Information security controls (guidance)
- ISO 22301:2019 — Business continuity management systems

---

## What I Hold in My Head (That Is NOT in the Code or Docs)

These are the things I know that I have not yet written down anywhere. Future maintainer:
treat these as your first documentation tasks.

### Production environment specifics

- The reference production deployment runs on a 4-core / 8 GB VPS with a managed MySQL 8.0 instance.
  PHP-FPM is configured with `pm.max_children=20` and `pm.max_requests=500`. The `pm.max_requests`
  setting prevents memory leaks from accumulating — do not remove it.
- The Nginx config includes a special location block for `var/jobs/` that denies direct web access
  (these JSON files contain job state, potentially including tenant names).
- The production `APP_AUDIT_HMAC_KEY` is stored in a Bitwarden vault (shared to co-maintainers
  if there are any). If you take over and don't have access, generate a new key and run
  `app:audit:verify-chain` — this will show all existing entries as "unverifiable" (expected after
  key rotation). New entries from that point forward will form a valid chain.

### Technical decisions made but not ADR'd

- **Why PhpSpreadsheet for TISAX/compliance import:** LibreOffice-based CLI parsing was evaluated
  but requires a server binary (not available on shared hosting). PhpSpreadsheet is pure PHP and
  handles all the VDA-ISA XLSX column-map variants we have seen.
- **Why `var/jobs/*.json` instead of a DB table for job status:** Job status files are written
  after `fastcgi_finish_request()` when the DB transaction may be closed. File writes are atomic
  on Linux/ext4 with `file_put_contents(..., LOCK_EX)`. A DB write after detach would require
  a new `EntityManager` with a fresh connection — possible but adds complexity. Revisit this if
  a Messenger strategy becomes primary.
- **Why no Redis for session storage:** Target customers use shared hosting without Redis access.
  DB-backed sessions (`session_handler_id: session.handler.pdo`) are more portable. For
  dedicated-infra deployments, Redis session storage can be switched via `config/packages/framework.yaml`.
- **Why the `AlvaHintService` is rule-based, not LLM-powered:** LLM API costs and latency are
  unsuitable for an on-premise compliance tool. The 17-rule rule base covers 90% of common
  first-week setup guidance. If you want AI-assisted hints, build a separate module that users
  opt into with an API key — do not put an LLM call in the hot path.

### Regulatory knowledge

- **MRIS-v1.5** is an internal framework name (`mythos-resistente Informationssicherheit`) with
  13 custom requirements. It is NOT a recognised standard. Do not reference it as "MRIS" in user-
  facing text without the full name — it will confuse auditors. See memory note `feedback_mris_not_marisk.md`.
- **VAIT/BAIT/KAIT/ZAIT** (BaFin sector-specific IT requirements) were superseded by EU DORA
  in January 2025. Do not suggest implementing these as separate modules — they are now DORA-
  covered. See memory note `feedback_dora_replaces_vait_bait.md`.
- **NIS2-UmsuCG §21 Meldepflicht:** The German transposition has a 24h/72h/30-day notification
  cascade to BSI. The GDPR Art. 33 72h deadline for data breaches is a different clock, different
  recipient (Datenschutzaufsichtsbehörde). The application tracks these as separate workflows
  (`gdpr_data_breach.yaml` vs the NIS2 incident workflow). Do not conflate them.

### Naming and terminology

- UI uses "Organisation" (not "Mandant", not "Tenant") for the user-facing term. Code uses `tenant`.
  See [docs/decisions/2026-05-27-tenant-organization-terminology.md](../decisions/2026-05-27-tenant-organization-terminology.md).
- Competitor product names (Verinice, Vanta, Drata, HiScout, etc.) must never appear in code,
  docs, or CHANGELOG. Standards references (ISO, BSI, NIST, ENX) are fine.
- "ISMS Helper" (two words, no hyphen) is the display name. "Little-ISMS-Helper" (hyphenated)
  is the repository slug. Both are acceptable in prose. "lih" is an acceptable abbreviation in
  informal contexts.

### Dev workflow shortcuts

- `php bin/phpunit --filter TestClassName` is faster than running the suite for targeted checks.
- `php bin/console cache:clear --env=test` before running tests if you see "cached container"
  errors after entity changes.
- The Playwright screenshot tool (`npm run screenshots`) requires Chrome installed. On headless
  servers, use `npx playwright install chromium` first.
- The `app:create-screenshot-user` command creates a temporary user with all persona roles for
  screenshot purposes. This user should not exist in production.

---

## Handoff Checklist

If you are the incoming maintainer, work through this list:

- [ ] Obtain `APP_AUDIT_HMAC_KEY` from the outgoing maintainer and store in your secrets manager
- [ ] Add yourself to `CITATION.cff` as an author / co-maintainer
- [ ] Review the 12 ADRs in `docs/adr/` to understand architectural context
- [ ] Run `php bin/phpunit` and confirm green locally
- [ ] Review open GitHub Issues — triage priority (see `CONTRIBUTING.md` for labels)
- [ ] Set up your own CI environment if needed (GitHub Actions secrets, see table above)
- [ ] Read the active `release-please` PR to understand what's pending for next Monday release
- [ ] Review the active god-class reduction roadmap in [ADR-0012](../adr/0012-godclass-baseline-ratchet.md)
- [ ] Add an entry to this document for anything you discover that isn't written down

---

## Emergency Contacts

- **Primary maintainer:** moag1000 — GitHub issues (preferred), moag2000s@gmail.com (urgent)
- **Security vulnerabilities:** Report via GitHub Security Advisories (private disclosure) before
  public issue
- **ENX/TISAX licensing questions:** ENX Association at [https://enx.com/contact](https://enx.com/contact)
- **BSI IT-Grundschutz questions:** BSI IT-Grundschutz Hotline (see bsi.bund.de for current contact)
