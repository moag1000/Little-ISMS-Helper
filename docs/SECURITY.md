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

- **Authorization checks:**
  - `#[IsGranted('ROLE_USER')]` on all controllers
  - `$this->denyAccessUnlessGranted('view', $entity)` for resource-level permissions
  - Multi-tenancy support in all voters

**Testing:**
```bash
# Test voter permissions
bin/console debug:voter AssetVoter
```

### A02:2021 – Cryptographic Failures ✅

**Implementation:**
- Session security in production (`config/packages/prod/framework.yaml`):
  - `cookie_secure: true` (HTTPS only)
  - `cookie_httponly: true` (No JavaScript access)
  - `cookie_samesite: 'lax'` (CSRF protection)
- TLS/HTTPS enforcement via HSTS header (6 months)

### A03:2021 – Injection ✅

**Implementation:**

1. **SQL Injection Prevention:**
   - Doctrine ORM with prepared statements
   - No raw SQL queries

2. **XSS Prevention:**
   - Twig auto-escaping enabled (`autoescape: 'html'`)
   - CSP headers in production
   - `InputValidationService` for additional sanitization

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
   - `ExcelExportService::sanitizeFormulaInjection()`
   - Prefixes dangerous characters with single quote

6. **Path Traversal:**
   - `DocumentController::download()` with realpath validation
   - Filename sanitization

### A04:2021 – Insecure Design ✅

**Implementation:**
- Rate limiting for brute force prevention:
  - Login: 5 attempts per 15 minutes
  - API: 100 requests per minute
  - Password reset: 3 attempts per hour
  - Document upload: 20 uploads per hour
- Security event logging for monitoring

### A05:2021 – Security Misconfiguration ✅

**Implementation:**
- Security headers (`SecurityHeadersSubscriber`, production only):
  - `Content-Security-Policy`
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
- Login rate limiting (5 attempts per 15 minutes)
- Session security:
  - 1-hour session lifetime
  - Secure cookies (HTTPS only)
  - HttpOnly cookies
  - SameSite: lax

**Recommendations:**
- Implement password complexity requirements
- Add Multi-Factor Authentication (MFA)
- Implement account lockout after failed attempts

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
- URL validation in `InputValidationService`

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

### InputValidationService

Input validation and sanitization:

```php
// Validate and sanitize
$email = $this->inputValidation->validateEmail($input);
$int = $this->inputValidation->validateInteger($input);
$safeFilename = $this->inputValidation->sanitizeFilename($filename);
$safeHtml = $this->inputValidation->sanitizeHtml($html);

// Detect attacks
if ($this->inputValidation->detectXssPatterns($input)) {
    // Log and reject
}
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
for i in {1..10}; do curl -X POST http://localhost/login; done
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

1. **Multi-Factor Authentication (MFA)**
2. **Password complexity requirements**
3. **Account lockout mechanism**
4. **IP whitelisting for admin panel**
5. **API authentication (JWT/OAuth)**
6. **Enhanced audit logging (database)**
7. **Real-time security monitoring**
8. **Automated vulnerability scanning**

## References

- [OWASP Top 10 2021](https://owasp.org/Top10/)
- [Symfony Security Best Practices](https://symfony.com/doc/current/security.html)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
