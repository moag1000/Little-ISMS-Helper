# CVE Analysis Report - Docker Image Security

**Date:** 2025-11-13
**Image Base:** `php:8.4-fpm-alpine`
**Scan Results:** 7 CVEs detected

## Executive Summary

Of the 7 CVEs detected:
- ✅ **2 are False Positives** (Critical/High severity but not applicable)
- ⚠️ **3 are Low Impact** (Disputed, postponed, or minor issues)
- 🔧 **1 requires evaluation** (Medium severity, rare use case)
- ❓ **1 has no information** (Unknown CVE)

**Overall Risk Assessment:** 🟢 LOW - No immediate action required for production use.

---

## Detailed CVE Analysis

### 1. CVE-2023-27482 (CVSS 10.0 - Critical)
**Package:** `alpine/supervisor` 4.2.5-r5
**Status:** ✅ **FALSE POSITIVE**

#### Analysis
This CVE affects **Home Assistant Supervisor**, NOT Alpine Linux's `supervisor` package. The vulnerability is a remote authentication bypass in Home Assistant's API that was fixed in Home Assistant Supervisor 2023.03.1.

#### Evidence
- Alpine Security Tracker lists this because Home Assistant runs on Alpine
- The actual Alpine `supervisor` package (process control system) is unaffected
- No patches needed for Alpine's supervisor 4.2.5

#### Recommendation
**No action required.** This is a scanner false positive.

---

### 2. CVE-2008-0888 (CVSS 9.3 - High)
**Package:** `alpine/unzip` 6.0-r15
**Status:** ✅ **FALSE POSITIVE**

#### Analysis
This is an invalid pointer flaw from **2008** that affects unzip versions before 5.52-11. The vulnerability was patched in 2008 by Tavis Ormandy of Google Security Team.

#### Evidence
- CVE published: February 2008
- Fixed in unzip 5.52-11 (2008)
- Alpine ships unzip 6.0 (released after the fix)
- Alpine Security Tracker does NOT list this CVE for unzip package

#### Recommendation
**No action required.** This vulnerability was fixed before unzip 6.0 release.

---

### 3. CVE-2025-10966 (CVSS 4.3 - Medium)
**Package:** `alpine/curl` 8.14.1-r2
**Status:** 🔧 **LOW RISK** - Evaluate for updates

#### Analysis
Missing SFTP host verification when using the **wolfSSH backend**. This only affects curl builds specifically compiled with wolfSSH support.

#### Impact Assessment
- **Likelihood:** Very Low (wolfSSH is rarely used in production)
- **Impact:** Medium (MITM attacks possible if affected)
- **Default Alpine curl:** Likely NOT built with wolfSSH backend

#### Fix Available
- Fixed in curl 8.17.0+ (released November 2025)
- Alpine will likely provide update in next release cycle

#### Recommendation
**Monitor Alpine updates.** Consider upgrading to curl 8.17.0+ when available in Alpine stable.

**Verification:**
```bash
# Check if your curl build uses wolfSSH
curl --version | grep -i wolfSSH
# No output = not affected
```

---

### 4. CVE-2025-45582 (CVSS 4.1 - Medium)
**Package:** `alpine/tar` 1.35-r3
**Status:** ⚠️ **DISPUTED** - Not a real vulnerability

#### Analysis
Claimed path traversal via directory traversal in GNU Tar 1.35. **DISPUTED by GNU Tar maintainers** as working-as-documented behavior.

#### Upstream Response
The GNU Tar manual explicitly documents this behavior:
> "When extracting from two or more untrusted archives, each one should be extracted independently, into different empty directories. Otherwise, the first archive could create a symbolic link into an area outside the working directory, and the second one could follow the link and overwrite data."

#### Status
- Ubuntu: Marked as "deferred" (likely to be rejected)
- Debian: Listed as "disputed"
- Alpine: No plans to patch documented behavior

#### Recommendation
**No action required.** Follow documented best practices when extracting untrusted tar archives.

**Best Practice:**
```bash
# Extract each untrusted archive into separate directories
mkdir archive1 && tar -xf untrusted1.tar -C archive1/
mkdir archive2 && tar -xf untrusted2.tar -C archive2/
```

---

### 5. CVE-2025-46394 (CVSS 3.2 - Low)
**Package:** `alpine/busybox` 1.37.0-r19
**Status:** ⚠️ **POSTPONED** - Minor terminal corruption issue

#### Analysis
TAR archive filenames can cause terminal corruption in busybox tar implementation.

#### Status
- Debian: "Postponed - Minor issue, terminal corruption, revisit when fixed upstream"
- Alpine: Tracked but unfixed, waiting for upstream BusyBox fix
- Impact: Cosmetic (terminal display only)

#### Recommendation
**No immediate action required.** Monitor for upstream BusyBox updates.

---

### 6. CVE-2024-58251 (CVSS 2.5 - Low)
**Package:** `alpine/busybox` 1.37.0-r19
**Status:** ⚠️ **UNFIXED** - Workaround available

#### Analysis
In `netstat` in BusyBox through 1.37.0, local users can launch network applications with argv[0] containing ANSI terminal escape sequences, causing terminal lock-up when netstat is used with `-p` option.

#### Impact
- **Threat Model:** Local users only (not remotely exploitable)
- **Effect:** Terminal lock-up (denial of service)
- **Scope:** Only when using `netstat -p`

#### Workaround
Don't use the `-p` option to netstat if you don't trust other local users.

#### Status
No fix available as of November 2025. Upstream BusyBox has not released a patch.

#### Recommendation
**Accept risk or avoid `netstat -p`.** Very low impact for containerized applications where local user access is controlled.

---

### 7. CVE-2025-62813 (CVSS N/A - Unknown)
**Status:** ❓ **NO INFORMATION AVAILABLE**

#### Analysis
No CVE details found in public databases (NVD, CVE.org, Alpine Security Tracker, MITRE).

#### Possible Reasons
- Reserved but not yet published
- Scanner false positive
- Private/unpublished vulnerability

#### Recommendation
**Monitor for updates.** Re-check in 30 days if CVE details become available.

---

## Risk Mitigation Strategies

### Immediate Actions (None Required)
All critical and high-severity CVEs are false positives. No immediate patching required.

### Short-term (Next 30 days)
1. ✅ Monitor Alpine Linux package updates
2. ✅ Update base image when Alpine releases curl 8.17.0+
3. ✅ Check for CVE-2025-62813 publication

### Long-term
1. ✅ Implement regular image scanning in CI/CD (already done)
2. ✅ Subscribe to Alpine Security announcements
3. ✅ Rebuild images monthly with latest Alpine packages

---

## Dockerfile Hardening Recommendations

### Option 1: Update to Latest Alpine Packages (Recommended)

```dockerfile
FROM php:8.4-fpm-alpine AS production

# Update all packages to latest security patches
RUN apk update && apk upgrade --no-cache

# Install dependencies
RUN apk add --no-cache \
    git \
    unzip \
    # ... rest of packages
```

**Pros:**
- Gets latest security fixes automatically
- Simple to implement

**Cons:**
- May introduce breaking changes
- Increases build time slightly

### Option 2: Remove Unnecessary Packages

If certain packages aren't needed, remove them to reduce attack surface:

```dockerfile
# If unzip is not needed in production:
# RUN apk del unzip

# If curl is only needed during build:
RUN apk add --no-cache --virtual .build-deps curl \
    && # ... use curl ... \
    && apk del .build-deps
```

### Option 3: Use Multi-Stage Builds (Already Implemented)

✅ Your Dockerfile already uses multi-stage builds, which is excellent for security!

---

## Compliance & Documentation

### ISO 27001:2022 Alignment
- ✅ **A.8.31:** Vulnerability Management - CVEs analyzed and documented
- ✅ **A.8.8:** Technical Vulnerability Management - Regular scanning implemented
- ✅ **A.8.32:** Protection against Malware - Base image security maintained

### Security Posture
- **Vulnerability Scanning:** ✅ Implemented in CI/CD
- **False Positive Rate:** High (2/7 = 29%)
- **Actual Risk:** Low
- **Remediation Time:** Not urgent

---

## Monitoring & Updates

### Recommended Actions

1. **Update CI/CD Workflow:**
   - Add CVE exception list for false positives
   - Focus alerts on actionable vulnerabilities

2. **Regular Updates:**
   ```bash
   # Rebuild Docker images monthly
   docker pull php:8.4-fpm-alpine
   docker-compose build --no-cache
   ```

3. **Alpine Security Monitoring:**
   - Subscribe to: https://security.alpinelinux.org/
   - RSS Feed: https://security.alpinelinux.org/vuln-rss.xml

4. **Scanner Configuration:**
   - Configure scanner to ignore disputed CVEs
   - Add exception rules for false positives

---

## Conclusion

**Security Status:** 🟢 **GOOD**

The Docker image security posture is strong:
- No actual critical or high-severity vulnerabilities
- All concerning CVEs are false positives or low-impact issues
- Multi-stage build already minimizes attack surface
- Regular updates and monitoring in place

**Next Review Date:** 2025-12-13 (30 days)

---

## References

- [Alpine Security Tracker](https://security.alpinelinux.org/)
- [CVE-2023-27482 - Home Assistant](https://www.home-assistant.io/security/)
- [CVE-2008-0888 - NVD](https://nvd.nist.gov/vuln/detail/CVE-2008-0888)
- [CVE-2025-10966 - curl.se](https://curl.se/docs/CVE-2025-10966.html)
- [CVE-2025-45582 - GNU Tar Dispute](https://lists.gnu.org/archive/html/bug-tar/2025-08/msg00012.html)
- [BusyBox Security](https://busybox.net/security.html)
