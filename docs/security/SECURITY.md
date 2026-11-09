# Security Implementation Guide

This document describes the security features implemented in Little ISMS Helper, following OWASP Top 10 guidelines and Symfony best practices.

## OWASP Top 10 2021 Coverage

### A01:2021 – Broken Access Control ✅

**Implementation:**
- **Voters for fine-grained access control** (`src/Security/Voter/`, selection):
  - `DocumentVoter` - Document access control with multi-tenancy
  - `AssetVoter` - Asset access control with multi-tenancy
  - `RiskVoter` - Risk access control with multi-tenancy
  - `IncidentVoter` - Incident access control with multi-tenancy
  - `ControlVoter` - Control access control with multi-tenancy
  - `ComplianceInheritanceVoter` - Compliance inheritance access control
  - `EntityVoter` - Generic entity access control
  - `PermissionVoter` - Permission management access control
  - `RoleVoter` - Role management access control
  - `TagVoter` - Tag access control
  - `UserVoter` - User management access control
  - `RecordResponsibleVoter` - "role OR responsible" for reminded actions
  - `AsyncJobVoter` - job status and downloads only for the job's owner (or `ROLE_SUPER_ADMIN`)
  - `ReportAccessVoter`, `GlobalCatalogueVoter`, `TenantScopedAdminVoter`, `SwitchUserVoter`

- **Authorization checks:**
  - `access_control`: every locale-prefixed route needs `ROLE_USER`, `/(de|en)/admin` needs `ROLE_ADMIN`
  - `#[IsGranted(...)]` attributes on controllers and actions, voters for record-level permissions
  - Multi-tenancy support in all voters (`HoldingTreeAccessTrait::decideTenantScope()`)
  - `ROLE_AUDITOR`, `ROLE_GROUP_CISO` and `ROLE_KONZERN_AUDITOR` are read-only: writes and
    create/edit forms answer 403 without `ROLE_MANAGER` (`ReadOnlyRoleSubscriber`, also for API
    sub-requests). GDPR breach actions, DPIA decisions and the Art. 15/20 personal-data export are
    `ROLE_MANAGER` (incl. DPO); `tests/Security/WriteActionRoleGateTest.php` pins them.
  - `ROLE_TOP_MGMT` (top management) reads everything and approves only what belongs to top
    management; `ROLE_ADMIN` does not inherit it.
  - Privacy exports carry `#[IsGranted(new Expression(PrivacyExportAccess::RULE))]`.
  - `^/api` (REST API, OpenAPI docs, JSON-LD contexts) requires a session or API token.
  - API tokens authenticate only in the stateless `api_token` firewall and only on `/api` and `/mcp`:
    any other path answers 401, a token request never creates a session or cookie, and a token
    writes nowhere except through `save_record` on `/mcp` (`ReadOnlyApiSubscriber`: 405 elsewhere).
    `save_record` needs the organisation switch (`McpSettings`), a token with `canWrite` and a
    non-reviewer user (`McpWriteAccess`); status, approvals and other decisions stay proposals in the
    suggestion queue.
  - REST and MCP write with the same rights as the form: only `<entity>:write` serialization groups,
    entity voters, `#[SameTenantOwner]` on owner fields. Evidence fields (`#[ApiReadOnlyEvidence]`:
    approvals, NIS-2/DORA report timestamps, Control `implementationStatus`/`implementationPercentage`)
    are never writable over the API; `tests/Api/ApiResourceContractTest.php` locks it. Details:
    [`NON_UI_CONTRACTS.md`](NON_UI_CONTRACTS.md), [`../setup/API_SETUP.md`](../setup/API_SETUP.md).
  - `App\Security\UserChecker` refuses deactivated users on every login path (form, SSO,
    remember-me, impersonation, token); `DeactivatedUserSubscriber` ends their open sessions and
    deactivation revokes their tokens. Pinned by `tests/Security/ApiTokenBoundaryTest.php`.
  - The lifecycle history (`/lifecycle/{type}/{id}/history`) checks the record's
    organisation itself — the tenant filter does not cover every lifecycle type.
  - Holding actions that read or write subsidiary rows go through
    `App\Service\Group\HoldingScope` (`read()` for explicitly scoped lookups,
    `asTenant()` per subsidiary): Konzern push-down, applying an industry baseline to
    the subtree (`ROLE_ADMIN` of the holding), Konzern certification bundle.

**Testing:**
```bash
# Access-control tests (voters, token boundary, read-only roles)
php bin/phpunit tests/Security/
```

### A02:2021 – Cryptographic Failures ✅

**Implementation:**
- **Enhanced Session Security** (`config/packages/framework.yaml`):
  - Cookie name `isms_session`
  - `cookie_secure: 'auto'` - HTTPS only in production
  - `cookie_httponly: true` - Prevents JavaScript access to session cookie (XSS protection)
  - `cookie_samesite: 'lax'` - CSRF protection (cookie sent with same-site and top-level navigation requests)
  - `gc_maxlifetime: 3600` - Session lifetime: 1 hour

- **Session Fixation Protection** (`config/packages/security.yaml`):
  - Symfony automatically regenerates session ID on successful authentication (built-in protection)
  - `invalidate_session: true` on logout - Completely invalidates session on logout

- **Remember Me Cookie Security**:
  - `secure: auto` - HTTPS only in production, HTTP allowed in development
  - `httponly: true` - No JavaScript access
  - `samesite: 'lax'` - CSRF protection
  - `lifetime: 604800` - 1 week maximum
  - Issued only after the second factor; the signature covers password and active MFA devices
    (`signature_properties: [password, mfaRememberMeMarker]`)

- **TLS/HTTPS Enforcement**:
  - HSTS header with 6 months max-age (`max-age=15768000`)

### A03:2021 – Injection ✅

**Implementation:**

1. **SQL Injection Prevention:**
   - Doctrine ORM with prepared statements
   - Raw SQL limited to backup/restore operations (SET FOREIGN_KEY_CHECKS, batch inserts)

2. **XSS Prevention:**
   - Twig auto-escaping enabled (`autoescape: 'html'`)
   - CSP headers in production
   - Record text is never output as HTML (Gate 92: `check_raw_output.py` for Twig, PHPStan rule `MarkupOutputRule` for PHP): components escape
     their text slots; any other `|raw` needs an entry in
     `scripts/quality/baselines/raw_output_allowlist.txt`; JSON into pages via `|fa_json`
   - Flashes render once in `base.html.twig` through `|fa_flash` (plain text, escaped)
   - Word documents only from `Export\DocxDocumentFactory::create()` (output escaping on)

3. **File Upload Injection:**
   - `FileUploadSecurityService`:
     - MIME type validation (server-side via finfo)
     - Magic byte verification
     - Extension whitelist
     - File size limits (10MB)
     - Safe filename generation
   - Evidence files (documents, versions, training materials) live below `var/uploads/`
     (`App\Service\Upload\ProtectedUploads`), outside the web root; they are served only by
     voter-checked, audit-logged download actions (`tests/Security/EvidenceOutsideWebRootTest.php`).
     Tenant logos and avatars stay in `public/uploads/`.
   - Uploaded workbooks are opened only via `App\Service\Import\SafeSpreadsheetReader` (fixed
     reader type, zip limits, read filter; `tests/Security/SpreadsheetUploadReaderTest.php`)

4. **Email Header Injection:**
   - `EmailNotificationService::sanitizeEmailSubject()`
   - Removes control characters from email subjects

5. **CSV/Excel Formula Injection:**
   - `ExcelExportService::sanitizeFormulaInjection()` and `App\Util\CsvSanitizer::sanitize()`
   - Prefixes dangerous characters (`= + - @`, TAB, CR) with a single quote
   - Every exporter that writes record text with `setCellValue()` / `fputcsv()` runs it
     through `CsvSanitizer` (audit workbooks, VVT, list exports, bulk CSV, LkSG, TISAX)

6. **Path Traversal:**
   - Stored paths are resolved by `ProtectedUploads::resolve()` (only inside the upload areas)
   - Filename sanitization

### A04:2021 – Insecure Design ✅

**Implementation:**
- Rate limiting for brute force prevention:
  - Login: firewall `login_throttling` of the `main` firewall — 5 failed attempts per
    username+IP and 25 per IP in 15 minutes
  - API tokens: 20 failed token attempts per client IP in 10 minutes → 429
    (`api_auth_failure`, `ApiTokenAuthenticator`)
  - Re-authentication (password confirm): 5 attempts per user in 15 minutes
  - MFA codes: 5 attempts per token in 15 minutes (TOTP and backup codes); a TOTP code is
    accepted once (last accepted time step per token)
  - API and `/mcp`: 100 requests per minute per API token (else per user, else per IP);
    an MCP JSON-RPC batch counts each message, at most 20 per batch
  - Public token URLs (Trust Center, supplier questionnaire): 60 requests per minute per IP
  - Document upload: 20 uploads per hour per user
  - Client IPs come from `X-Forwarded-For` only behind trusted proxies (loopback and
    private networks by default, `SYMFONY_TRUSTED_PROXIES` for public proxies)
- Security event logging for monitoring

### A05:2021 – Security Misconfiguration ✅

**Implementation:**
- Security headers (`SecurityHeadersSubscriber`, all environments except dev):
  - `Content-Security-Policy` — `script-src 'self' 'nonce-…'` without
    `'unsafe-inline'`/`'unsafe-eval'`/`data:`. The nonce is per request
    (`App\Security\CspNonce`); inline scripts carry `nonce="{{ csp_nonce() }}"`,
    Turbo visits reuse the document's nonce (`assets/js/csp-nonce.js` sends it
    as `X-CSP-Nonce`). Inline `on*=` handlers, `javascript:` URLs and CSS
    imports in JS modules are banned by Gate 67. `style-src` still allows
    `'unsafe-inline'`.
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Strict-Transport-Security` (HSTS)
  - `Permissions-Policy`
  - `Referrer-Policy`

- Custom error pages (prevents information disclosure):
  - `templates/bundles/TwigBundle/Exception/error{403,404,429,500,503}.html.twig`

- Production configuration:
  - Session security hardening
  - Error page customization

### A06:2021 – Vulnerable and Outdated Components

**Recommendations:**
```bash
# Regular dependency updates
composer update
composer audit

# Check for security advisories
symfony check:security
```

### A07:2021 – Identification and Authentication Failures ✅

**Implementation:**
- **Login Rate Limiting** (`config/packages/security.yaml`, firewall `login_throttling`):
  - 5 failed attempts per username+IP, 25 per IP, within 15 minutes; reset on success
  - Checked while the firewall verifies the credentials (not in the login controller)

- **Multi-Factor Authentication** (TOTP + backup codes, NIS-2 Art. 21(2)(j)):
  - One state per login, bound to the person's id (`App\Security\Mfa\MfaSessionState`),
    started by `MfaLoginListener` on every login of the web firewall; the enforcer fails closed
  - Password re-authentication starts a new state and asks for the code again (`ReauthController`)
  - A TOTP code is accepted once (last accepted time step per token, `MfaService`)
  - Remember-me cookie only after the code (see A02)
  - A new authenticator needs its MFA decision in `tests/Security/Mfa/MfaLoginPathsTest` (Gate 91)

- **Session Security** (see A02 above):
  - 1-hour session lifetime with garbage collection
  - Secure, HttpOnly, SameSite=lax cookies
  - Session fixation protection (automatic session regeneration on login)
  - Session invalidation on logout

- **Password Security**:
  - Hashing algorithm `auto` (best available, bcrypt/argon2)
  - Password policy: `PasswordPolicyResolver`

- **CSRF Protection**:
  - Login form `enable_csrf: true`; every state-changing action `#[IsCsrfTokenValid]`
    (fetch() via `X-CSRF-Token` header), enforced by CI Gate 65
  - SameSite cookie attribute (`lax`)

**Recommendations:**
- Add password breach detection (HaveIBeenPwned API)

### A08:2021 – Software and Data Integrity Failures ⚠️

**Recommendations:**
- Implement Subresource Integrity (SRI) for CDN resources
- Add code signing for deployments
- Implement file integrity monitoring

### A09:2021 – Security Logging and Monitoring Failures ✅

**Implementation:**
- `SecurityEventLogger` service:
  - Login success/failure
  - Logout events
  - Access denied (authorization failures)
  - File upload success/failure
  - Data modifications (CREATE, UPDATE, DELETE)
  - Rate limit hits
  - Suspicious activity

- `SecurityEventSubscriber`:
  - Automatic logging for authentication events
  - Exception logging

**Usage:**
```php
// In controllers
$this->securityLogger->logFileUpload($filename, $mimeType, $size, $success);
$this->securityLogger->logDataChange('Asset', $id, 'UPDATE', $changes);
$this->securityLogger->logAccessDenied($resource, $action, $user);
```

**Log Location:**
- Development: `var/log/dev.log`
- Production: `var/log/prod.log`

### A10:2021 – Server-Side Request Forgery (SSRF) ✅

**Implementation:**
- PDF generation: `isRemoteEnabled: false` (prevents SSRF)
- User-configurable outbound URLs carry `#[NoInternalIp]` (`src/Validator/Constraint/NoInternalIp.php`)
  and are fetched only through `App\Http\UserUrlHttpClient`: no private addresses after DNS
  resolution, no redirects. `tests/Security/OutboundUserUrlClientTest.php` rejects other raw HTTP
  clients in `src/`.
- Web push goes only to known browser push services (`App\Http\PushEndpointPolicy`,
  `tests/Security/WebPushEndpointTest.php`)

## Services Overview

### FileUploadSecurityService

Comprehensive file upload security:

```php
// Validate file upload
$this->fileUploadSecurity->validateUploadedFile($file);

// Generate safe filename
$safeFilename = $this->fileUploadSecurity->generateSafeFilename($file);
```

**Features:**
- MIME type validation (server-side)
- Magic byte verification
- Extension whitelist
- File size limits
- Safe filename generation

### SecurityEventLogger

Centralized security event logging:

```php
// Log events
$this->securityLogger->logLoginSuccess($user);
$this->securityLogger->logAccessDenied($resource, $action, $user);
$this->securityLogger->logFileUpload($filename, $mimeType, $size, $success);
$this->securityLogger->logSuspiciousActivity($description, $details);
```

## Security Headers

Applied in every environment except `dev` (`SecurityHeadersSubscriber`):

- **CSP**: Restricts resource loading
- **HSTS**: Forces HTTPS (6 months)
- **X-Frame-Options**: Prevents clickjacking
- **X-Content-Type-Options**: Prevents MIME sniffing
- **Permissions-Policy**: Restricts browser features

## Testing Security

### Manual Testing

1. **File Upload:**
```bash
# Try uploading malicious files
curl -F "file=@malicious.php.jpg" http://localhost/document/new
```

2. **Access Control:**
```bash
# Try accessing other tenant's resources
curl -H "Cookie: isms_session=..." http://localhost/asset/1
```

3. **Rate Limiting:**
```bash
# Try brute forcing login
# (the 6th failed POST to /de/login within 15 minutes is refused)
for i in {1..10}; do curl -X POST http://localhost/de/login -d '_username=x@example.com&_password=wrong'; done
```

### Automated Testing

```bash
# Run security audit
composer audit

# Check for vulnerabilities
symfony check:security

# Run tests with coverage
php bin/phpunit --coverage-html coverage/
```

## Deployment Checklist

Before deploying to production:

- [ ] Enable HTTPS
- [ ] Configure proper session storage (Redis/Memcached)
- [ ] Set up log rotation
- [ ] Configure monitoring and alerting
- [ ] Test all security headers
- [ ] Verify error pages
- [ ] Test file upload restrictions
- [ ] Verify rate limiting works
- [ ] Test access control for all resources
- [ ] Enable security event logging monitoring

## Incident Response

If a security incident is detected:

1. **Check logs:**
```bash
tail -f var/log/prod.log | grep SECURITY
```

2. **Identify affected users:**
```sql
SELECT * FROM audit_log WHERE created_at > 'incident_time';
```

3. **Block attacker:**
```yaml
# config/packages/rate_limiter.yaml (API, re-auth, public token URLs, uploads, MFA)
# config/packages/security.yaml (login_throttling)
# Reduce limits temporarily
```

4. **Review security events:**
```bash
grep "SUSPICIOUS_ACTIVITY\|ACCESS_DENIED" var/log/prod.log
```

## Future Enhancements

1. Account lockout beyond the login throttling window
2. IP allow-listing for the admin panel
3. OAuth for browser-based assistant connectors
4. Real-time security monitoring
5. Automated vulnerability scanning
6. Subresource Integrity for CDN resources

## References

- [OWASP Top 10 2021](https://owasp.org/Top10/)
- [Symfony Security Best Practices](https://symfony.com/doc/current/security.html)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
