# Security Policy

Little ISMS Helper is itself an ISMS-/compliance-tool — it stores risk
registers, audit findings, control implementations, incident timelines
and (depending on tenant configuration) personal data. We treat
vulnerability reports accordingly.

## Supported Versions

The latest stable release receives security fixes. Older minor versions
are not supported. The release cadence is documented in
[`CONTRIBUTING.md`](CONTRIBUTING.md) §"Release Cadence".

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |
| older   | :x:                |

## Reporting a Vulnerability

**Please do not open public issues for security findings.**

Use one of the two private channels:

1. **GitHub Private Vulnerability Reporting**
   <https://github.com/moag1000/Little-ISMS-Helper/security/advisories/new>

2. **Email** — `moag2000s@gmail.com`
   Subject prefix: `[security] Little-ISMS-Helper`

Please include:

- Affected version / commit SHA
- Reproduction steps or proof-of-concept
- Expected vs. observed behaviour
- Impact assessment (data exposure, privilege escalation, tenant
  isolation break, etc.)
- Optional: a suggested fix or mitigation

### Response Timeline

- **Acknowledgement** — within 72 hours
- **Triage + severity assessment** — within 7 days (CVSS 4.0)
- **Fix or mitigation plan** — communicated within 14 days
- **Coordinated disclosure** — public advisory once a patched release is
  available, normally within 30–90 days depending on severity

### Severity Classification

We follow the CVSS 4.0 base score, contextualised for a multi-tenant
ISMS application:

| Rating       | CVSS      | Examples                                                                 |
| ------------ | --------- | ------------------------------------------------------------------------ |
| Critical     | 9.0–10.0  | Unauthenticated RCE, full DB dump, tenant-isolation break               |
| High         | 7.0–8.9   | Authenticated privilege-escalation, mass data exposure                  |
| Medium       | 4.0–6.9   | Self-XSS, info disclosure, missing security headers                     |
| Low          | 0.1–3.9   | Defence-in-depth gaps, theoretical vectors                              |
| Informational| 0.0       | Hardening recommendations                                               |

## Out-of-Scope

- Issues on a fork or local installation that do not affect upstream
- Vulnerabilities requiring physical access or compromised admin
  credentials
- Theoretical findings without a viable attack path
- Volume-based DoS without amplification

## Recognition

Reporters who responsibly disclose are credited in the corresponding
release notes (unless they request anonymity).

## Bug Bounty

This is a community-driven open-source project. There is no monetary
bounty programme. We are happy to provide written acknowledgement and
list reporters in the project's `Hall of Fame` once one exists.
