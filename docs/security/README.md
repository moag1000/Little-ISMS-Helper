# Security Documentation

This directory contains security-related documentation for the Little ISMS Helper project.

## 📚 Available Documents

### [SECURITY.md](SECURITY.md)
Security implementation guide: OWASP Top 10 mapping, access control, sessions, MFA, headers,
rate limits, uploads, SSRF, logging.

### [CVE_ANALYSIS_2025.md](../archive/security/CVE_ANALYSIS_2025.md) (archived)
CVE analysis of the Docker image from 2025-11 with the justifications behind `.trivyignore`.

### [NON_UI_CONTRACTS.md](NON_UI_CONTRACTS.md)
Every security property of the non-UI paths (REST, MCP, restore, imports, exports, jobs, console,
quick-fix) with the test or CI gate that locks it — organisation boundary, token rules, form-equal
rights, restore, exports.

## 🛡️ Vulnerability Management Process

### 1. Detection
- Automated scanning in CI/CD pipeline
- Regular manual security audits
- Dependency monitoring

### 2. Analysis
- CVE verification and research
- False positive identification
- Impact assessment
- Risk scoring

### 3. Response
- Critical (CVSS 9-10): Immediate action within 24h
- High (CVSS 7-8.9): Action within 7 days
- Medium (CVSS 4-6.9): Action within 30 days
- Low (CVSS 0-3.9): Scheduled maintenance

### 4. Documentation
- CVE analysis reports
- Remediation actions
- Exception justifications
- Review schedules

## 🔍 Vulnerability Scanning

### Trivy Scanner Configuration

The project uses Trivy for container image scanning with exceptions for false positives.

**Configuration File:** `/.trivyignore`

**Scan Command:**
```bash
# Scan local Dockerfile build
trivy image little-isms-helper:latest

# Scan with high/critical only
trivy image --severity HIGH,CRITICAL little-isms-helper:latest

# Scan ignoring false positives
trivy image --ignorefile .trivyignore little-isms-helper:latest
```

### False Positive Management

False positives are documented in:
1. **`.trivyignore`** - Scanner exceptions
2. **`CVE_ANALYSIS_2025.md`** - Detailed justifications

All ignored CVEs must have:
- ✅ Justification with evidence
- ✅ Verification date
- ✅ Reference links
- ✅ Review schedule

## 🔐 Docker Image

- Multi-stage build (`production` / `development`), base `php:8.5-fpm-trixie` pinned by digest
- App files owned by `www-data`; nginx, PHP-FPM and MariaDB run under supervisor
- OCI labels, no secrets in the image

Application security features: see [SECURITY.md](SECURITY.md).

## 📝 Compliance

### ISO 27001:2022 Alignment

| Control | Implementation | Status |
|---------|----------------|--------|
| A.8.8 | Technical Vulnerability Management | ✅ Implemented |
| A.8.31 | Separation of Development, Test and Production | ✅ Multi-stage builds |
| A.8.32 | Change Management | ✅ Version control |

### OWASP Top 10 (2021)

| Risk | Mitigation | Documentation |
|------|------------|---------------|
| A01 Broken Access Control | RBAC, Permissions | [SECURITY.md](SECURITY.md) |
| A02 Cryptographic Failures | TLS, Hashing | [SECURITY.md](SECURITY.md) |
| A03 Injection | Parameterized queries, output escaping, CSV sanitizer | [SECURITY.md](SECURITY.md) |
| A04 Insecure Design | Security architecture | [SECURITY.md](SECURITY.md) |
| A05 Security Misconfiguration | Security headers, CSP nonces | [SECURITY.md](SECURITY.md) |
| A06 Vulnerable Components | Dependency scanning | [CVE_ANALYSIS_2025.md](../archive/security/CVE_ANALYSIS_2025.md) |
| A07 Authentication Failures | MFA, Session mgmt | [SECURITY.md](SECURITY.md) |
| A08 Software Integrity | Checksums, signatures | CI/CD pipeline |
| A09 Logging Failures | Audit logging | [SECURITY.md](SECURITY.md) |
| A10 SSRF | `NoInternalIp`, `UserUrlHttpClient` | [SECURITY.md](SECURITY.md) |

## 🚨 Security Incident Response

Reporting channels, response times and disclosure policy: root [`SECURITY.md`](../../SECURITY.md)
(private advisory or e-mail — no public issues).

## 🔄 Regular Security Reviews

### Monthly Tasks
- ☐ Review `.trivyignore` exceptions
- ☐ Update dependency versions
- ☐ Rebuild Docker images
- ☐ Check for Debian security updates (base image)

### Quarterly Tasks
- ☐ Full security audit
- ☐ Penetration testing
- ☐ Update CVE analysis report
- ☐ Review security documentation

### Annual Tasks
- ☐ Third-party security assessment
- ☐ ISO 27001 compliance review
- ☐ Update security policies
- ☐ Security training for team

## 📖 Additional Resources

### Internal Documentation
- [Architecture Overview](../architecture/SOLUTION_DESCRIPTION.md)
- [Security Setup](../setup/AUTHENTICATION_SETUP.md)
- [Audit Logging](../setup/AUDIT_LOGGING.md)

### External Resources
- [Debian Security Tracker](https://security-tracker.debian.org/tracker/)
- [PHP Security](https://www.php.net/manual/en/security.php)
- [OWASP Cheat Sheets](https://cheatsheetseries.owasp.org/)
- [Docker Security](https://docs.docker.com/engine/security/)

