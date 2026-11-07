# Security Implementation Guide

This document describes the security features implemented in Little ISMS Helper, following OWASP Top 10 guidelines and Symfony best practices.

## OWASP Top 10 2021 Coverage

### A01:2021 – Broken Access Control ✅

**Implementation:**
- **Voters for fine-grained access control:**
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

- **Authorization checks:**
  - `#[IsGranted('ROLE_USER')]` on all controllers
  - `$this->denyAccessUnlessGranted('view', $entity)` for resource-level permissions
  - Multi-tenancy support in all voters
  - `ROLE_AUDITOR` (and `ROLE_GROUP_CISO` / `ROLE_KONZERN_AUDITOR`, which inherit it) is
    read-only: state-changing actions need `ROLE_MANAGER` or a persona role. GDPR breach
    actions, DPIA decisions and the Art. 15/20 personal-data export are `ROLE_MANAGER`
    (incl. DPO); `tests/Security/WriteActionRoleGateTest.php` pins them.
  - `^/api` (REST API, OpenAPI docs, JSON-LD contexts) requires a session or API token.
  - The lifecycle history (`/lifecycle/{type}/{id}/history`) checks the record's
    organisation itself — the tenant filter does not cover every lifecycle type.

**Testing:**
```bash
# Test voter permissions
bin/console debug:voter AssetVoter
```

### A02:2021 – Cryptographic Failures ✅

**Implementation:**
- **Enhanced Session Security** (`config/packages/framework.yaml`):
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

- **TLS/HTTPS Enforcement**:
  - HSTS header with 6 months max-age
  - Automatic HTTP to HTTPS upgrade in CSP policy

### A03:2021 – Injection ✅

**Implementation:**

1. **SQL Injection Prevention:**
   - Doctrine ORM with prepared statements
   - Raw SQL limited to backup/restore operations (SET FOREIGN_KEY_CHECKS, batch inserts)

2. **XSS Prevention:**
   - Twig auto-escaping enabled (`autoescape: 'html'`)
   - CSP headers in production
   - No `|raw` on user content
   - Flash messages are escaped where they are rendered (`message|e`): the alert macros
     output their body raw, and flashes can carry record names or exception text

3. **File Upload Injection:**
   - `FileUploadSecurityService`:
     - MIME type validation (server-side via finfo)
     - Magic byte verification
     - Extension whitelist
     - File size limits (10MB)
     - Safe filename generation

4. **Email Header Injection:**
   - `EmailNotificationService::sanitizeEmailSubject()`
   - Removes control characters from email subjects

5. **CSV/Excel Formula Injection:**
   - `ExcelExportService::sanitizeFormulaInjection()` and `App\Util\CsvSanitizer::sanitize()`
   - Prefixes dangerous characters (`= + - @`, TAB, CR) with a single quote
   - Every exporter that writes record text with `setCellValue()` / `fputcsv()` runs it
     through `CsvSanitizer` (audit workbooks, VVT, list exports, bulk CSV, LkSG, TISAX)

6. **Path Traversal:**
   - `DocumentController::download()` with realpath validation
   - Filename sanitization

### A04:2021 – Insecure Design ✅

**Implementation:**
- Rate limiting for brute force prevention:
  - Login: firewall `login_throttling` — 5 failed attempts per username+IP and 25 per IP
    in 15 minutes (form login, API token, SSO)
  - Re-authentication (password confirm): 5 attempts per user in 15 minutes
  - MFA codes: 5 attempts per token in 15 minutes (TOTP and backup codes)
  - API: 100 requests per minute per IP
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
  - `templates/bundles/TwigBundle/Exception/error{403,404,500}.html.twig`

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
  - Checked while the firewall verifies the credentials. A limiter inside the login
    controller (before 2026-11) never saw the POST and only counted page views.

- **Session Security** (see A02 above):
  - 1-hour session lifetime with garbage collection
  - Secure, HttpOnly, SameSite=lax cookies
  - Session fixation protection (automatic session regeneration on login)
  - Session invalidation on logout

- **Password Security**:
  - Automatic bcrypt/argon2 hashing
  - High cost factor for production
  - Password hashing algorithm: auto (uses best available)

- **CSRF Protection**:
  - Enabled on all forms (`enable_csrf: true`)
  - SameSite cookie attribute (`lax`)
  - Token validation on form submissions

**Recommendations:**
- Implement progressive account lockout after failed attempts
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
- User-configurable outbound URLs (webhooks) validated by `#[NoInternalIp]` (`src/Validator/Constraint/NoInternalIp.php`)
- Webhooks do not follow redirects (`max_redirects: 0`), so a 302 cannot carry the POST
  to an internal address after the URL check

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

## Security Headers (Production Only)

All security headers are automatically applied in production environment:

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
curl -H "Cookie: PHPSESSID=..." http://localhost/asset/1
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
# config/packages/rate_limiter.yaml
# Reduce limits temporarily
```

4. **Review security events:**
```bash
grep "SUSPICIOUS_ACTIVITY\|ACCESS_DENIED" var/log/prod.log
```

## Future Enhancements

Priority improvements:

1. ✅ **Multi-Factor Authentication (MFA)** - IMPLEMENTED
2. ✅ **Password complexity requirements** - IMPLEMENTED (PasswordPolicyResolver)
3. **Account lockout mechanism**
4. **IP whitelisting for admin panel**
5. **API authentication (JWT/OAuth)**
6. ✅ **Enhanced audit logging (database)** - IMPLEMENTED
7. **Real-time security monitoring**
8. **Automated vulnerability scanning**

## References

- [OWASP Top 10 2021](https://owasp.org/Top10/)
- [Symfony Security Best Practices](https://symfony.com/doc/current/security.html)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
