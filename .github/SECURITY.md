# Security Policy

Little ISMS Helper is an information-security management tool, so security
issues in the tool itself are taken seriously and prioritised over feature
work.

## Supported Versions

Only the latest minor release line receives security patches. The current
supported line is the one published on the [Releases page][releases]
(typically `v3.2.x`).

| Version line | Status                   |
| ------------ | ------------------------ |
| 3.2.x        | ✅ Active, patched       |
| 3.1.x        | ⚠️ End-of-life, no fixes |
| 3.0.x        | ⚠️ End-of-life, no fixes |
| < 3.0        | ❌ Unsupported           |

When a security fix lands, it is shipped as a fresh patch tag (e.g. `v3.2.9`)
and announced in the [CHANGELOG][changelog].

## Reporting a Vulnerability

**Please do not open a public issue for security findings.**

Use one of the following channels:

* **GitHub Private Vulnerability Reporting** (preferred):
  <https://github.com/moag1000/Little-ISMS-Helper/security/advisories/new>
* **Email** the maintainer (see commit-author address) with subject
  `[SECURITY] <short description>`.

Please include in your report:

1. Steps to reproduce, or a minimal proof-of-concept.
2. Affected version(s) — include the value from `composer.json` `version`.
3. The deployment scenario (Docker self-host, composer-based, shared hosting).
4. Whether the vulnerability requires authentication / what role / what tenant.
5. Your assessment of CVSS-3.1 vector if you have one.

### What to expect

* **Acknowledgement** within 5 working days.
* **Triage + first assessment** within 10 working days, including a CVSS-3.1
  rating and a tentative timeline.
* **Coordinated disclosure**: we agree on a disclosure date together. Default
  embargo is 30 days for low/medium, 60 days for high, 90 days for critical.
* **Credit**: with your permission, you will be acknowledged in the release
  notes.

### Out of Scope

* Vulnerabilities in third-party dependencies — please report those upstream.
  We monitor advisories via Dependabot and patch on receipt.
* Findings on outdated versions (see "Supported Versions").
* Theoretical findings without a working exploit path in the default
  configuration.

## Hardening Tips

For a production deployment, please review the security-relevant settings:

* **Quick-Fix Fallback**: enable token-mode (`/admin/quick-fix-settings`) when
  the instance is publicly reachable — see `docs/ADMIN_GUIDE.md`.
* **MFA**: enforce MFA for all admin accounts via the MFA-Enforcer subscriber
  (active by default since v3.2.5).
* **Tenant isolation**: do not bypass the `TenantFilter` SQLFilter for any
  user-facing query.
* **Audit log retention**: minimum 365 days (NIS2 Art. 21.2). Configurable via
  `/admin/audit-log/retention`.
* **CSRF**: enforced for all forms + JSON-API endpoints. Stateless tokens for
  the backup/export/import family.
* **Rate-Limiter**: applied to login + MFA-challenge — adjust thresholds in
  `config/packages/security.yaml`.
* **TOTP secrets**: encrypted at rest (since v3.2.5). Provide a dedicated
  `MFA_ENCRYPTION_KEY` env var instead of relying on `APP_SECRET` derivation.

[releases]: https://github.com/moag1000/Little-ISMS-Helper/releases
[changelog]: https://github.com/moag1000/Little-ISMS-Helper/blob/main/CHANGELOG.md
